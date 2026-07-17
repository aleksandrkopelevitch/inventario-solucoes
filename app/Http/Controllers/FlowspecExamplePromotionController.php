<?php

namespace App\Http\Controllers;

use App\Http\Requests\PromoteFlowspecExampleRequest;
use App\Models\FlowspecChat;
use App\Models\FlowspecExample;
use App\Models\FlowspecMessage;
use App\Services\Flowspec\CredentialScrubber;
use App\Services\Flowspec\FlowspecDocument;
use App\View\Components\Flowspec\Thread;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ongoing corpus curation (F8, Stage 6): promotes a generated and approved
 * flowSpec to FlowspecExample. CredentialScrubber runs again here — nothing
 * enters the corpus with a literal secret, even if it slipped through
 * unnoticed in the conversation.
 */
class FlowspecExamplePromotionController extends Controller
{
    public function store(PromoteFlowspecExampleRequest $request, FlowspecChat $chat, FlowspecMessage $message, CredentialScrubber $scrubber)
    {
        if ($message->flow_spec === null) {
            throw ValidationException::withMessages(['name' => 'Esta mensagem não carrega um flowSpec gerado.']);
        }

        $violations = $scrubber->violations($message->flow_spec);

        if ($violations !== []) {
            throw ValidationException::withMessages(['name' => 'Redija antes de promover — credencial literal: ' . implode(' | ', $violations)]);
        }

        $slug = Str::slug($request->validated('name'));

        while (FlowspecExample::query()->where('slug', $slug)->exists()) {
            $slug .= '-' . Str::lower(Str::random(4));
        }

        FlowspecExample::create([
            'name'        => $request->validated('name'),
            'slug'        => $slug,
            'description' => $request->validated('description'),
            'tags'        => $request->validated('tags'),
            'flow_spec'   => $message->flow_spec,
            'connectors'  => FlowspecDocument::from($message->flow_spec)->connectorNames(),
            'source'      => 'chat',
            'is_active'   => true,
        ]);

        return response()->json([
            'type'           => 'success',
            'message'        => 'flowSpec promovido a exemplo do corpus.',
            'updatableSlots' => [Thread::slot($chat)],
        ]);
    }
}
