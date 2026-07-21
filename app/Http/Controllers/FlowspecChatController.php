<?php

namespace App\Http\Controllers;

use App\Actions\Flowspec\NormalizeReferenceFlowspec;
use App\Http\Requests\StoreFlowspecChatRequest;
use App\Jobs\GenerateFlowspecReply;
use App\Models\DocumentationPage;
use App\Models\FlowspecChat;
use App\Models\Integration;
use App\View\Components\Flowspec\Thread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Chat for the Digibee flowSpec generator (F8). The conversation is async:
 * the POST persists the user's message and dispatches GenerateFlowspecReply;
 * the thread (Flowspec\Thread, updatable slot) shows "generating…" and the
 * `status` polling swaps the slot once the reply arrives.
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

    public function store(StoreFlowspecChatRequest $request, NormalizeReferenceFlowspec $normalize)
    {
        $chat = $request->user()->flowspecChats()->create([
            'title' => Str::limit($request->validated('message'), 80),
        ]);

        $reference = $request->referenceFlowspec();

        $message = $chat->messages()->create([
            'role'    => 'user',
            'content' => $request->validated('message'),
            'meta'    => [
                'solution_ids'       => $request->solutionIds(),
                'document_refs'      => $request->documentRefs(),
                'reference_flowspec' => $reference ? $normalize->handle($reference) : null,
            ],
        ]);

        GenerateFlowspecReply::dispatch($message);

        return response()->json(['redirect' => route('flowspec.show', $chat)]);
    }

    public function show(Request $request, FlowspecChat $chat)
    {
        $this->authorize('view', $chat);

        return view('flowspec.show', [
            'chat' => $chat->load('integration:id,name'),
        ]);
    }

    /**
     * Thread polling while the generation job runs (flowspec-chat.js). Only
     * builds the slot (messages + integrations + Blade render) once the
     * reply has arrived — while `pending`, the client discards the slot, and
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
     * Search for the "Specific documents" chips picker — documentation pages
     * (from any Solution or DocumentationGroup) and integrations with their
     * own documentation, combined into a single result set. IDs come
     * prefixed (`page:{id}`/`integration:{id}`) so
     * FlowspecDocumentReference/ParsesFlowspecContextInput can distinguish
     * the two types when saving `meta.document_refs`.
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

        $integrations = Integration::query()
            ->whereNotNull('documentation')
            ->where('documentation', '<>', '')
            ->where('name', 'like', "%{$term}%")
            ->limit(8)
            ->get(['id', 'name'])
            ->map(fn (Integration $integration) => [
                'id'   => "integration:{$integration->id}",
                'name' => $integration->name,
                'meta' => 'Documentação de integração',
            ]);

        // `collect()` forces a plain Support\Collection before merging: when
        // `$pages` has zero matches, Eloquent\Collection::map() keeps it as an
        // (empty) Eloquent\Collection instead of downgrading to a base
        // Collection — `contains(fn ($item) => ! $item instanceof Model)` is
        // vacuously false over zero items, so the downgrade in Eloquent's
        // `map()` never fires. `$pages->merge($integrations)` then runs
        // Eloquent\Collection::merge(), which assumes every item is a Model
        // and calls `$item->getKey()` — a fatal error on the plain arrays
        // `$integrations` actually holds. Reproduces with any term that
        // matches an integration but no documentation page.
        return response()->json(['results' => collect($pages)->merge($integrations)->take(10)->values()]);
    }
}
