<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttachFlowspecToIntegrationRequest;
use App\Models\FlowspecChat;
use App\Models\FlowspecMessage;
use App\Models\Integration;
use App\Services\Flowspec\CredentialScrubber;
use App\View\Components\Flowspec\Thread;
use Illuminate\Validation\ValidationException;

/**
 * Attaches the flowSpec from a chat message (F8) to an Integration —
 * `generated_flowspec`, `flowspec_status`, `flowspec_generated_at` — and
 * links the chat to the integration.
 */
class FlowspecAttachmentController extends Controller
{
    public function store(AttachFlowspecToIntegrationRequest $request, FlowspecChat $chat, FlowspecMessage $message, CredentialScrubber $scrubber)
    {
        if ($message->flow_spec === null) {
            throw ValidationException::withMessages(['integration_id' => 'Esta mensagem não carrega um flowSpec gerado.']);
        }

        // Attaching writes the flowSpec onto a shared Integration, widening its
        // exposure — the same reason promotion scrubs, so scrub here too. A
        // freshly generated flowSpec is withheld when it leaks, but a legacy
        // message (persisted before that guard) could still carry a literal.
        $violations = $scrubber->violations($message->flow_spec);

        if ($violations !== []) {
            throw ValidationException::withMessages(['integration_id' => 'Redija antes de anexar — credencial literal: ' . implode(' | ', $violations)]);
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
