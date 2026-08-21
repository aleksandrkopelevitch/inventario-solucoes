<?php

use App\Models\DocumentationPage;
use App\Models\FlowspecAttachment;
use App\Models\FlowspecChat;
use App\Models\Integration;
use App\Services\Flowspec\FlowspecContext;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the full application (via Tests\TestCase, which resolves
| the app from bootstrap/app.php). Unit tests stay framework-free unless they
| opt into the base case explicitly.
|
*/

pest()->extend(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Attaches participants to an integration: [[Solution, position], ...]. Mirrors
 * what `SyncIntegrationFromChain` guarantees in production — participants never
 * exist without a matching `chain` — by building a linear chain (in the order
 * of the given positions) when the test hasn't configured one explicitly.
 * Tests that need an integration genuinely without a `chain` (to exercise
 * that edge case) should attach via `$integration->participants()`
 * directly, bypassing this helper.
 */
function attachParticipants(Integration $integration, array $participants): void
{
    foreach ($participants as [$solution, $position]) {
        $integration->participants()->attach($solution->id, ['position' => $position]);
    }

    if ($integration->chain) {
        return;
    }

    $sorted = collect($participants)->sortBy(fn ($p) => $p[1])->values();

    $integration->chain = [
        'nodes' => $sorted->map(fn ($p) => ['solution_id' => $p[0]->id, 'label' => null])->all(),
        'edges' => $sorted->count() > 1
            ? collect(range(0, $sorted->count() - 2))
                ->map(fn ($i) => ['from' => $i, 'to' => $i + 1, 'arrow' => '->', 'protocol' => null])
                ->all()
            : [],
    ];
    $integration->save();
}

/**
 * A flowSpec context with nothing attached — what the prompt-builder tests need,
 * since they exercise one section at a time rather than a resolved conversation.
 */
function emptyFlowspecContext(): FlowspecContext
{
    return new FlowspecContext(
        pages: collect(),
        integrationDocs: collect(),
        textDocs: collect(),
        referenceFlowspecs: collect(),
        attachments: [],
        attachedMeta: [],
        omittedAttachments: [],
        examples: collect(),
        tags: [],
    );
}

/** Attaches an inventory documentation page to a conversation's context. */
function attachPage(FlowspecChat $chat, DocumentationPage $page): FlowspecAttachment
{
    return FlowspecAttachment::factory()->for($chat, 'chat')->document($page)->create();
}
