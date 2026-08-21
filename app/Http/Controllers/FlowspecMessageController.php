<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFlowspecMessageRequest;
use App\Jobs\GenerateFlowspecReply;
use App\Models\FlowspecChat;
use App\View\Components\Flowspec\ContextPanel;
use App\View\Components\Flowspec\Thread;
use Illuminate\Validation\ValidationException;

class FlowspecMessageController extends Controller
{
    public function store(StoreFlowspecMessageRequest $request, FlowspecChat $chat)
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

        $message = $chat->messages()->create([
            'role'    => 'user',
            'content' => $request->validated('message'),
        ]);

        $chat->touch();

        GenerateFlowspecReply::dispatch($message);

        return response()->json([
            // The context panel comes back too, not because the attachments
            // changed but because the METER did: a new turn grows the history,
            // which is the one context line that moves on its own.
            'updatableSlots' => [Thread::slot($chat), ContextPanel::slot($chat)],
            // Clears only the textarea. The conversation's context deliberately
            // survives the send — it belongs to the chat now, and resetting it
            // is what used to make the second question in a thread lose the
            // documentation the first was answered with.
            'js' => "document.dispatchEvent(new CustomEvent('ak:flowspec-composer-reset', {detail: {formId: 'flowspec-message-form'}}));",
        ]);
    }
}
