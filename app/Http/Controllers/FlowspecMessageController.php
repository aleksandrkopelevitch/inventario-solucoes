<?php

namespace App\Http\Controllers;

use App\Actions\Flowspec\NormalizeReferenceFlowspec;
use App\Http\Requests\StoreFlowspecMessageRequest;
use App\Jobs\GenerateFlowspecReply;
use App\Models\FlowspecChat;
use App\View\Components\Flowspec\Thread;
use Illuminate\Validation\ValidationException;

class FlowspecMessageController extends Controller
{
    public function store(StoreFlowspecMessageRequest $request, FlowspecChat $chat, NormalizeReferenceFlowspec $normalize)
    {
        // One pending turn at a time: the composer stays enabled during
        // "generating…" (it lives outside the thread slot), so the server
        // needs to refuse — without this, the 2nd message dispatches a
        // concurrent GenerateFlowspecReply that WithoutOverlapping would hold
        // consuming attempts, and the in-progress reply would be generated
        // against a history that ignores the new message.
        if ($chat->isAwaitingReply()) {
            throw ValidationException::withMessages([
                'message' => 'Aguarde a resposta atual terminar antes de enviar outra mensagem.',
            ]);
        }

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

        $chat->touch();

        GenerateFlowspecReply::dispatch($message);

        return response()->json([
            'updatableSlots' => [Thread::slot($chat)],
            // Clears the composer — the thread (with "generating…") is already
            // back in the slot. Also resets the context chips and the composer
            // attachment UI (reference pill/panel, textarea height): the
            // composer lives outside the slot and its attachments only apply to
            // this message; without clearing, the same system/document/reference
            // would be resent on every following message.
            'js' => "document.dispatchEvent(new CustomEvent('ak:chips-reset', {detail: {names: ['solutions', 'documents']}}));"
                . "document.dispatchEvent(new CustomEvent('ak:flowspec-composer-reset', {detail: {formId: 'flowspec-message-form'}}));",
        ]);
    }
}
