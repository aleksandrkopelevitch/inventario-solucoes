<?php

namespace App\Http\Controllers;

use App\Actions\PromoteFlowspecExample;
use App\Http\Requests\PromoteFlowspecExampleRequest;
use App\Models\FlowspecChat;
use App\Models\FlowspecMessage;
use App\Services\Flowspec\CredentialScrubber;
use App\View\Components\Flowspec\Thread;
use Illuminate\Validation\ValidationException;

/**
 * Ongoing corpus curation (F8, Stage 6): promotes a generated and approved
 * flowSpec to FlowspecExample. CredentialScrubber runs again here — nothing
 * enters the corpus with a literal secret, even if it slipped through
 * unnoticed in the conversation. Promotion is idempotent: a message links to
 * the example it produced and can't be promoted twice.
 */
class FlowspecExamplePromotionController extends Controller
{
    public function store(PromoteFlowspecExampleRequest $request, FlowspecChat $chat, FlowspecMessage $message, CredentialScrubber $scrubber, PromoteFlowspecExample $promote)
    {
        if ($message->flow_spec === null) {
            throw ValidationException::withMessages(['name' => 'Esta mensagem não carrega um flowSpec gerado.']);
        }

        if ($message->isPromoted()) {
            throw ValidationException::withMessages(['name' => 'Esta mensagem já foi promovida ao corpus.']);
        }

        $violations = $scrubber->violations($message->flow_spec);

        if ($violations !== []) {
            throw ValidationException::withMessages(['name' => 'Redija antes de promover — credencial literal: ' . implode(' | ', $violations)]);
        }

        $promote->handle(
            $message,
            $request->validated('name'),
            $request->validated('description'),
            $request->validated('tags'),
        );

        return response()->json([
            'type'           => 'success',
            'message'        => 'flowSpec promovido a exemplo do corpus.',
            'updatableSlots' => [Thread::slot($chat)],
        ]);
    }
}
