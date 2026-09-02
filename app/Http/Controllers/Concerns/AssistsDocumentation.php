<?php

namespace App\Http\Controllers\Concerns;

use App\Contracts\Documentable;
use App\Http\Requests\StoreDocumentationChatMessageRequest;
use App\Jobs\GenerateDocumentationChatReply;
use App\Models\DocumentationChat;
use App\Models\DocumentationChatMessage;
use App\Models\Notebook;
use App\Support\Documentation\DocumentationRequirements;
use App\View\Components\Documentation\ChatThread;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Documentation Assistant ("Assiste IA") for a documentation page. A
 * conversation (DocumentationChat), one per (user, page) — reopening the panel
 * resumes the same thread. `NotebookPageController` resolves the page and its
 * caderno (which owns the context documents), and delegates to this trait the
 * side panel, sending a message (async job + polling) and applying a proposed
 * draft. Nothing is persisted to the page itself until the user saves.
 *
 * The chat is scoped to the NOTEBOOK, not to a solution. It was the other way
 * round until the container swap, and the difference shows in two places worth
 * knowing: the context documents are the notebook's, and the requirements
 * checklist reaches THROUGH the notebook to whatever solutions it documents
 * (`DocumentationRequirements`), so a caderno describing three systems reports
 * facts about all three, and one describing none still works.
 *
 * The target is typed as `Documentable` rather than `DocumentationPage`
 * because it used to be either that or an `Integration` — the second kind of
 * documentation this app no longer has. Nothing in here branches on it.
 */
trait AssistsDocumentation
{
    /** Side panel: the requirements checklist, the thread so far, and the composer. */
    protected function chatPanelResponse(Notebook $notebook, Documentable $target, string $sendUrl): JsonResponse
    {
        $this->authorize('update', $notebook);

        $chat = $this->resolveChat($notebook, $target);

        return response()->json([
            'content' => view('documentation.panels.chat', [
                'notebook'        => $notebook,
                'targetLabel'     => $target->documentationTitle(),
                'chat'            => $chat,
                'sendUrl'         => $sendUrl,
                'contextStoreUrl' => route('notebooks.context.store', $notebook),
                // The catalog behind the "Páginas de contexto" picker. It lists
                // every caderno's pages, not just this one's — see
                // NotebookPageController::contextPages() for why context and a
                // LINK are scoped differently.
                'contextPagesUrl' => route('notebooks.context-pages', $notebook),
                'requirements'    => DocumentationRequirements::for($target),
            ])->render(),
        ]);
    }

    /**
     * Persists the user's message and dispatches the reply job. One pending
     * turn at a time — the composer stays enabled during "gerando…" (it lives
     * outside the thread slot), so the server refuses a second message while
     * the chat isAwaitingReply(): without this, a second send would dispatch a
     * concurrent job that WithoutOverlapping would just queue, generated
     * against a history that ignores the new message.
     */
    protected function sendChatMessage(StoreDocumentationChatMessageRequest $request, Notebook $notebook, Documentable $target): JsonResponse
    {
        $chat = $this->resolveChat($notebook, $target);

        if ($chat->isAwaitingReply()) {
            throw ValidationException::withMessages([
                'message' => 'Aguarde a resposta atual terminar antes de enviar outra mensagem.',
            ]);
        }

        $data = $request->validated();

        $message = $chat->messages()->create([
            'role'              => 'user',
            'content'           => $data['message'],
            'existing_content'  => $data['existing_content'] ?? null,
            'context_media_ids' => array_map(intval(...), $data['media_ids'] ?? []),
            'context_page_ids'  => array_map(intval(...), $data['page_ids'] ?? []),
        ]);

        $chat->touch();

        GenerateDocumentationChatReply::dispatch($message);

        return response()->json([
            'updatableSlots' => [ChatThread::slot($chat)],
            // Clears the composer — the thread (with "gerando…") is already
            // back in the slot. The composer lives outside the slot and its
            // attachments only apply to this message; without clearing, the
            // same context docs would be resent on every following message.
            'js' => "document.dispatchEvent(new CustomEvent('ak:docs-chat-composer-reset', {detail: {formId: 'docs-chat-message-form'}}));",
        ]);
    }

    /** Polling while the reply job runs (docs-chat.js). */
    protected function chatStatusResponse(Notebook $notebook, DocumentationChat $chat): JsonResponse
    {
        $this->authorize('update', $notebook);
        abort_unless($chat->notebook_id === $notebook->id, 404);

        $pending = $chat->isAwaitingReply();

        return response()->json([
            'pending'        => $pending,
            'updatableSlots' => $pending ? [] : [ChatThread::slot($chat)],
        ]);
    }

    /**
     * Marks a message's draft as applied (bookkeeping only — the actual
     * Markdown push into the editor happens client-side via
     * `__akDocsSetMarkdown`, same as the old flow's "Aplicar"). Idempotent.
     */
    protected function applyChatMessageResponse(Notebook $notebook, DocumentationChatMessage $message): JsonResponse
    {
        $this->authorize('update', $notebook);
        $message->loadMissing('chat');
        abort_unless($message->chat->notebook_id === $notebook->id, 404);

        if ($message->applied_at === null) {
            $message->update(['applied_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * `notebook_id` is kept synced to whichever caderno the current request is
     * scoped to, rather than trusted from creation time: it is what the context
     * documents (and the 404 guards) key off, and a page can be re-filed under
     * another caderno while a chat about it is still open.
     */
    private function resolveChat(Notebook $notebook, Documentable $target): DocumentationChat
    {
        $chat = DocumentationChat::firstOrCreate([
            'user_id'     => request()->user()->id,
            'target_type' => $target->getMorphClass(),
            'target_id'   => $target->getKey(),
        ], [
            'notebook_id' => $notebook->id,
        ]);

        if ($chat->notebook_id !== $notebook->id) {
            $chat->update(['notebook_id' => $notebook->id]);
        }

        return $chat;
    }

    /**
     * Payload for the main editor page (not the side panel) to resume polling
     * on load if a reply is still generating — e.g. the user closed the panel
     * or navigated away mid-generation. Deliberately does NOT create a chat
     * (unlike resolveChat()): most page loads have no conversation yet, and
     * creating one just to check would be a write on every view. Null when
     * there's no chat yet, or it exists but isn't awaiting a reply.
     */
    protected function chatResumeFor(Notebook $notebook, Documentable $target): ?array
    {
        $user = request()->user();

        if (! $user?->can('update', $notebook)) {
            return null;
        }

        $chat = DocumentationChat::query()
            ->where('user_id', $user->id)
            ->where('target_type', $target->getMorphClass())
            ->where('target_id', $target->getKey())
            ->first();

        if (! $chat || ! $chat->isAwaitingReply()) {
            return null;
        }

        return [
            'statusUrl' => route('notebooks.chat.status', [$notebook, $chat]),
        ];
    }
}
