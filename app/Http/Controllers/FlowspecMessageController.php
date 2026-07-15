<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFlowspecMessageRequest;
use App\Jobs\GenerateFlowspecReply;
use App\Models\FlowspecChat;
use App\View\Components\Flowspec\Thread;

class FlowspecMessageController extends Controller
{
    public function store(StoreFlowspecMessageRequest $request, FlowspecChat $chat)
    {
        $message = $chat->messages()->create([
            'role'    => 'user',
            'content' => $request->validated('message'),
            'meta'    => [
                'solution_ids'  => $request->solutionIds(),
                'document_refs' => $request->documentRefs(),
            ],
        ]);

        $chat->touch();

        GenerateFlowspecReply::dispatch($message);

        return response()->json([
            'updatableSlots' => [Thread::slot($chat)],
            // Limpa o composer — o thread (com "gerando…") já voltou no slot.
            'js' => "document.getElementById('flowspec-message-input').value = ''",
        ]);
    }
}
