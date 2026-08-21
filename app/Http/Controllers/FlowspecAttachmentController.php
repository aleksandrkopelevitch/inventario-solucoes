<?php

namespace App\Http\Controllers;

use App\Actions\Flowspec\AttachFlowspecDocuments;
use App\Actions\Flowspec\AttachFlowspecFile;
use App\Actions\Flowspec\AttachFlowspecText;
use App\Enums\FlowspecDocumentType;
use App\Http\Requests\StoreFlowspecAttachmentRequest;
use App\Models\FlowspecAttachment;
use App\Models\FlowspecChat;
use App\View\Components\Flowspec\ContextPanel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The conversation's context: attach, remove, and browse what there is to
 * attach.
 *
 * Everything here writes to the CHAT, not to a message — attaching a document
 * mid-thread applies to every following turn, which is the whole reason this
 * replaced the old per-message pickers. The response is always the context
 * panel's slot, so the list and the meter move together.
 */
class FlowspecAttachmentController extends Controller
{
    public function __construct(
        private readonly AttachFlowspecDocuments $documents,
        private readonly AttachFlowspecFile $files,
        private readonly AttachFlowspecText $texts,
    ) {}

    public function store(StoreFlowspecAttachmentRequest $request, FlowspecChat $chat): JsonResponse
    {
        $created = $this->attach($request, $chat);

        return response()->json([
            'type'           => 'success',
            'message'        => $this->message($created),
            'updatableSlots' => [ContextPanel::slot($chat)],
        ]);
    }

    public function destroy(FlowspecChat $chat, FlowspecAttachment $attachment): JsonResponse
    {
        $this->authorize('update', $chat);

        // The media row goes with it: unlike a submission's material, a chat's
        // attachment has no audit value once removed from the context, and
        // leaving the file on disk would be a leak of something the user
        // deliberately took back out.
        $attachment->media?->delete();
        $attachment->delete();

        return response()->json([
            'type'           => 'success',
            'message'        => 'Removido do contexto.',
            'updatableSlots' => [ContextPanel::slot($chat->refresh())],
        ]);
    }

    /**
     * The picker panel: every Solution that has documentation, its pages, and
     * the integrations it participates in that document themselves.
     *
     * Not nested under `{chat}` — the new-chat screen has no chat and needs the
     * same picker. When a chat IS given, what it already has attached comes back
     * marked, so the list reads as state rather than as a form that forgot.
     */
    public function picker(Request $request): JsonResponse
    {
        $this->authorize('viewAny', FlowspecChat::class);

        $chat = $this->pickerChat($request);

        return response()->json([
            'content' => view('flowspec.panels.documents', [
                'chat'     => $chat,
                'attached' => $chat === null ? [] : $this->attachedRefs($chat),
            ])->render(),
        ]);
    }

    /**
     * Which of the two shapes this request carries decides what gets attached.
     * All three can arrive at once from the new-chat composer's staged context.
     *
     * @return Collection<int, FlowspecAttachment>
     */
    private function attach(StoreFlowspecAttachmentRequest $request, FlowspecChat $chat): Collection
    {
        $created = collect();

        if ($request->documentRefs() !== []) {
            $created = $created->merge($this->documents->handle($chat, $request->documentRefs()));
        }

        foreach ($request->uploadedFiles() as $file) {
            $created->push($this->files->handle($chat, $file));
        }

        foreach ($request->pastedTexts() as $text) {
            $created->push($this->texts->handle($chat, $text['content'], $text['label']));
        }

        return $created;
    }

    /**
     * Names what happened, and never lies about a no-op: re-checking a document
     * the conversation already has is idempotent (AttachFlowspecDocuments), and
     * silently answering "adicionado" would leave the user looking for a second
     * copy that was never created.
     *
     * @param  Collection<int, FlowspecAttachment>  $created
     */
    private function message(Collection $created): string
    {
        if ($created->isEmpty()) {
            return 'Isso já estava no contexto desta conversa.';
        }

        if ($created->contains(fn (FlowspecAttachment $a) => $a->hasSensitiveFindings())) {
            return 'Anexado ao contexto — confira o aviso de credencial.';
        }

        return $created->count() === 1
            ? 'Anexado ao contexto da conversa.'
            : "{$created->count()} itens anexados ao contexto da conversa.";
    }

    /**
     * `?chat=` is validated by hand rather than route-bound: the picker is a
     * static route (the new-chat screen has no chat to bind), so this parameter
     * is untrusted input and must be authorized like any other — otherwise it
     * would report another user's attached context.
     */
    private function pickerChat(Request $request): ?FlowspecChat
    {
        $id = $request->query('chat');

        if (! is_numeric($id)) {
            return null;
        }

        $chat = FlowspecChat::query()->find((int) $id);

        return $chat !== null && $request->user()->can('view', $chat) ? $chat : null;
    }

    /**
     * The picker's own reference shape (`page:12`) for what's already attached,
     * so a checkbox can render checked.
     *
     * @return list<string>
     */
    private function attachedRefs(FlowspecChat $chat): array
    {
        return $chat->attachments()
            ->whereNotNull('reference_id')
            ->get(['reference_type', 'reference_id'])
            ->map(fn (FlowspecAttachment $a) => FlowspecDocumentType::forMorphClass((string) $a->reference_type)->reference($a->reference_id))
            ->all();
    }
}
