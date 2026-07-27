<?php

use App\Actions\SyncIntegrationFromChain;
use App\Models\Integration;
use App\Models\Solution;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('never turns a stale solution_id on a decision/actor node into a participant, even written directly to storage', function () {
    // Every HTTP entry point scrubs `solution_id` off a decision/actor node
    // before it reaches storage (`ValidatesChainNode`), so every other test
    // proves that scrubbing works — not that the action's OWN gate
    // (`ChainNodeKind::referencesSolution()`) defends storage that skipped
    // it: a chain written directly (factory, a fixture imported before the
    // rule existed, a hand-edited row), never through a Form Request.
    $a = Solution::factory()->create();
    $ghost = Solution::factory()->create();
    $b = Solution::factory()->create();

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [
                ['solution_id' => $a->id, 'label' => null, 'kind' => 'system'],
                // A decision node carrying a stale solution_id — never valid
                // input from any current endpoint, but the action must not
                // trust it regardless of how it got into storage.
                ['solution_id' => $ghost->id, 'label' => 'Aprovado?', 'kind' => 'decision'],
                ['solution_id' => $b->id, 'label' => null, 'kind' => 'system'],
            ],
            'edges' => [
                ['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null],
                ['from' => 1, 'to' => 2, 'arrow' => '->', 'protocol' => null],
            ],
        ],
    ]);

    app(SyncIntegrationFromChain::class)->handle($integration);

    $integration->refresh();

    expect($integration->participants->pluck('id')->sort()->values()->all())->toBe([$a->id, $b->id])
        ->and($integration->participants->pluck('id'))->not->toContain($ghost->id)
        // The flow still resolves end-to-end through the decision node —
        // A is the source (in-degree 0), B the target (out-degree 0) — the
        // ghost's stale solution_id doesn't hijack either endpoint.
        ->and($integration->source_solution_id)->toBe($a->id)
        ->and($integration->target_solution_id)->toBe($b->id);
});

it('never turns a stale solution_id on an actor node into a participant, even written directly to storage', function () {
    $a = Solution::factory()->create();
    $ghost = Solution::factory()->create();

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [
                ['solution_id' => $a->id, 'label' => null, 'kind' => 'system'],
                ['solution_id' => $ghost->id, 'label' => 'Equipe financeira', 'kind' => 'actor'],
            ],
            'edges' => [
                ['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null],
            ],
        ],
    ]);

    app(SyncIntegrationFromChain::class)->handle($integration);

    $integration->refresh();

    expect($integration->participants->pluck('id')->all())->toBe([$a->id])
        ->and($integration->target_solution_id)->toBe($a->id);
});
