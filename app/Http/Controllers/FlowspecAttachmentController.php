<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttachFlowspecToIntegrationRequest;
use App\Models\FlowspecChat;
use App\Models\FlowspecMessage;
use App\Models\Integration;
use App\View\Components\Flowspec\Thread;
use Illuminate\Validation\ValidationException;

/**
 * Anexa o flowSpec de uma mensagem do chat (F8) a uma Integration —
 * `generated_flowspec`, `flowspec_status`, `flowspec_generated_at` — e
 * vincula o chat à integração.
 */
class FlowspecAttachmentController extends Controller
{
    public function store(AttachFlowspecToIntegrationRequest $request, FlowspecChat $chat, FlowspecMessage $message)
    {
        if ($message->flow_spec === null) {
            throw ValidationException::withMessages(['integration_id' => 'Esta mensagem não carrega um flowSpec gerado.']);
        }

        $integration = Integration::query()->findOrFail($request->validated('integration_id'));
        $this->authorize('update', $integration);

        $integration->generated_flowspec = $message->flow_spec;
        $integration->flowspec_status = ($message->meta['validated'] ?? false) ? 'validated' : 'generated';
        $integration->flowspec_generated_at = now();
        $integration->save();

        $chat->update(['integration_id' => $integration->id]);

        return response()->json([
            'type'           => 'success',
            'message'        => "flowSpec anexado à integração {$integration->name}.",
            'updatableSlots' => [Thread::slot($chat)],
        ]);
    }
}
