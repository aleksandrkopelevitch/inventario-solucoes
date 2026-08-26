<?php

namespace App\Http\Controllers;

use App\Actions\Flowspec\AttachFlowspecDocuments;
use App\Actions\Flowspec\AttachFlowspecFile;
use App\Actions\Flowspec\AttachFlowspecText;
use App\Http\Requests\StoreFlowspecChatRequest;
use App\Jobs\GenerateFlowspecReply;
use App\Models\DocumentationPage;
use App\Models\FlowspecChat;
use App\Services\Flowspec\FlowspecContextResolver;
use App\View\Components\Flowspec\Thread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Chat for the Especialista em Integrações (F8), which generates Digibee
 * flowSpec JSON. The conversation is async:
 * the POST persists the user's message and dispatches GenerateFlowspecReply;
 * the thread (Flowspec\Thread, updatable slot) shows "generating…" and the
 * `status` polling swaps the slot once the reply arrives.
 *
 * Context (attached documentation, files and pastes) belongs to the CHAT and is
 * managed by FlowspecAttachmentController — except on `store()`, the one moment
 * there is no chat yet to attach to.
 */
class FlowspecChatController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', FlowspecChat::class);

        return view('flowspec.index', [
            'chats' => $request->user()->flowspecChats()->latest('updated_at')->withCount('messages')->get(),
        ]);
    }

    public function store(
        StoreFlowspecChatRequest $request,
        AttachFlowspecDocuments $documents,
        AttachFlowspecFile $files,
        AttachFlowspecText $texts,
    ) {
        $chat = $request->user()->flowspecChats()->create([
            'title' => Str::limit($request->validated('message'), 80),
        ]);

        // The context staged in the new-chat composer becomes the conversation's
        // context, BEFORE the message is created: the generation job reads the
        // chat's attachments, so anything attached after the dispatch could miss
        // the very turn it was meant for.
        if ($request->documentRefs() !== []) {
            $documents->handle($chat, $request->documentRefs());
        }

        foreach ($request->uploadedFiles() as $file) {
            $files->handle($chat, $file);
        }

        foreach ($request->pastedTexts() as $text) {
            $texts->handle($chat, $text['content'], $text['label']);
        }

        $message = $chat->messages()->create([
            'role'    => 'user',
            'content' => $request->validated('message'),
        ]);

        GenerateFlowspecReply::dispatch($message);

        return response()->json(['redirect' => route('flowspec.show', $chat)]);
    }

    public function show(Request $request, FlowspecChat $chat)
    {
        $this->authorize('view', $chat);

        return view('flowspec.show', [
            'chat'  => $chat,
            'chats' => $request->user()->flowspecChats()->latest('updated_at')->withCount('messages')->get(),
        ]);
    }

    /**
     * Thread polling while the generation job runs (flowspec-chat.js). Only
     * builds the slot (messages + Blade render) once the reply has
     * arrived — while `pending`, the client discards the slot, and
     * generation can take minutes, so none of this should run on every tick.
     */
    public function status(Request $request, FlowspecChat $chat)
    {
        $this->authorize('view', $chat);

        $pending = $chat->isAwaitingReply();

        return response()->json([
            'pending'        => $pending,
            'updatableSlots' => $pending ? [] : [Thread::slot($chat)],
        ]);
    }

    /**
     * Search behind the picker panel — documentation pages, from any Solution or
     * DocumentationGroup. IDs come prefixed (`page:{id}`) because the reference
     * is stored as a morph pair and `FlowspecDocumentReference` validates that
     * shape; it used to also carry `diagram:{id}`, back when an integration held
     * documentation of its own.
     */
    public function searchDocuments(Request $request): JsonResponse
    {
        $this->authorize('viewAny', FlowspecChat::class);

        $term = trim((string) $request->query('q', ''));

        if ($term === '') {
            return response()->json(['results' => []]);
        }

        $pages = DocumentationPage::query()
            ->whereNotNull('documentation')
            ->where('documentation', '<>', '')
            ->where('title', 'like', "%{$term}%")
            ->with('container')
            ->limit(8)
            ->get()
            ->map(fn (DocumentationPage $page) => [
                'id'   => "page:{$page->id}",
                'name' => "{$page->container->name} — {$page->title}",
                'meta' => 'Página de documentação',
            ]);

        // `collect()` forces a plain Support\Collection: with zero matches,
        // Eloquent\Collection::map() keeps it an (empty) Eloquent\Collection
        // rather than downgrading to a base one — `contains(fn ($item) => !
        // $item instanceof Model)` is vacuously false over zero items, so the
        // downgrade in Eloquent's `map()` never fires, and anything treating
        // the result as a collection of Models then calls `getKey()` on a plain
        // array. It cost a fatal error once, when a second result set was
        // merged in here; the cast is cheap insurance either way.
        return response()->json(['results' => collect($pages)->take(10)->values()]);
    }

    /**
     * Documentation worth attaching for the text being typed — the replacement
     * for the automatic injection this module used to do.
     *
     * Same word-boundary match over the Solution catalog as before (no AI, no
     * embedding: naming a system is a literal string match), except the result
     * is a row of "adicionar ao contexto" buttons instead of 60k characters
     * silently entering the prompt. Nothing here costs a token until it's
     * clicked, which is what lets the composer's meter be honest about what the
     * next request will cost.
     */
    public function suggestDocuments(Request $request, FlowspecContextResolver $resolver): JsonResponse
    {
        $this->authorize('viewAny', FlowspecChat::class);

        $text = trim((string) $request->query('q', ''));

        // Below this there is nothing to match on but noise, and every
        // keystroke would run the catalog scan.
        if (mb_strlen($text) < 3) {
            return response()->json(['suggestions' => []]);
        }

        $chat = $this->suggestionChat($request);

        return response()->json([
            'suggestions' => $resolver->suggestFor($text, $chat === null ? [] : $resolver->attachedKeys($chat)),
        ]);
    }

    /**
     * `?chat=` here is untrusted input on a static route (the new-chat screen
     * has no chat to bind), so it is authorized by hand — otherwise it would
     * leak which documentation another user's conversation has attached, via
     * what this endpoint declines to suggest.
     */
    private function suggestionChat(Request $request): ?FlowspecChat
    {
        $id = $request->query('chat');

        if (! is_numeric($id)) {
            return null;
        }

        $chat = FlowspecChat::query()->find((int) $id);

        return $chat !== null && $request->user()->can('view', $chat) ? $chat : null;
    }
}
