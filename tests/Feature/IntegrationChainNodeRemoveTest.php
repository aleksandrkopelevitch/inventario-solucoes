<?php

use App\Enums\UserRole;
use App\Models\Integration;
use App\Models\Solution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function nodeRemoveAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

it('removes a block, drops its links and reindexes the ones above it', function () {
    $a = Solution::factory()->create(['name' => 'A']);
    $b = Solution::factory()->create(['name' => 'B']);
    $c = Solution::factory()->create(['name' => 'C']);
    $d = Solution::factory()->create(['name' => 'D']);

    // A -> B, B -> C, C -> D. Removing B (index 1) must drop the two edges
    // touching it and pull C/D down to indices 1/2, so C -> D survives as 1 -> 2.
    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [
                ['solution_id' => $a->id, 'label' => null, 'kind' => 'system'],
                ['solution_id' => $b->id, 'label' => null, 'kind' => 'system'],
                ['solution_id' => $c->id, 'label' => null, 'kind' => 'system'],
                ['solution_id' => $d->id, 'label' => null, 'kind' => 'system'],
            ],
            'edges' => [
                ['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => 'rest'],
                ['from' => 1, 'to' => 2, 'arrow' => '->', 'protocol' => 'soap'],
                ['from' => 2, 'to' => 3, 'arrow' => '->', 'protocol' => 'sftp'],
            ],
        ],
    ]);
    attachParticipants($integration, [[$a, 0], [$b, 1], [$c, 2], [$d, 3]]);

    $response = $this->actingAs(nodeRemoveAdmin())
        ->deleteJson(route('solutions.integrations.chain.node.remove', [$a, $integration, 1]))
        ->assertOk()
        ->assertJson(['type' => 'success']);

    $integration->refresh();

    expect($integration->chain['nodes'])->toBe([
        ['solution_id' => $a->id, 'label' => null, 'kind' => 'system'],
        ['solution_id' => $c->id, 'label' => null, 'kind' => 'system'],
        ['solution_id' => $d->id, 'label' => null, 'kind' => 'system'],
    ])
        // Only C -> D survives, renumbered from 2 -> 3 down to 1 -> 2.
        ->and($integration->chain['edges'])->toBe([
            ['from' => 1, 'to' => 2, 'arrow' => '->', 'protocol' => 'sftp'],
        ])
        // Derived columns follow: B is gone from participants, and the flow now
        // starts at A (still in === 0) but A is isolated, so C is the source.
        ->and($integration->participants->pluck('name')->sort()->values()->all())->toBe(['A', 'C', 'D'])
        ->and($integration->target_solution_id)->toBe($d->id)
        // The scalar protocol is re-derived from the surviving edge.
        ->and($integration->protocol->value)->toBe('sftp');

    // The response rebuilds the whole graph, in the same shape as the page's
    // data-integration-graph — the client re-renders instead of patching.
    expect($response->json('graph.nodes'))->toHaveCount(3)
        ->and($response->json('graph.edges'))->toBe([
            ['from' => 1, 'to' => 2, 'arrow' => '->', 'protocol' => ['value' => 'sftp', 'label' => 'SFTP']],
        ])
        ->and($response->json('graph.nodes.1.label'))->toBe('C')
        ->and($response->json('summary'))->toBe('C -> D, A');
});

it('reindexes viz_layout positions, comments and edge anchors together', function () {
    $a = Solution::factory()->create(['name' => 'A']);
    $b = Solution::factory()->create(['name' => 'B']);
    $c = Solution::factory()->create(['name' => 'C']);

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [
                ['solution_id' => $a->id, 'label' => null, 'kind' => 'system'],
                ['solution_id' => $b->id, 'label' => null, 'kind' => 'system'],
                ['solution_id' => $c->id, 'label' => null, 'kind' => 'system'],
            ],
            'edges' => [
                ['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null], // touches B, dies
                ['from' => 0, 'to' => 2, 'arrow' => '<->', 'protocol' => null], // survives as 0 -> 1
            ],
        ],
        'viz_layout' => [
            'nodes' => [
                ['x' => 10, 'y' => 10],
                ['x' => 20, 'y' => 20], // B's position — must vanish, not shift onto C
                ['x' => 30, 'y' => 30],
            ],
            'edges' => [
                ['from' => 'r', 'to' => 'l'], // anchors of the dying edge
                ['from' => 't', 'to' => 'b'], // anchors of the surviving one
            ],
            'comments' => ['nota A', 'nota B', 'nota C'],
        ],
    ]);
    attachParticipants($integration, [[$a, 0], [$b, 1], [$c, 2]]);

    $this->actingAs(nodeRemoveAdmin())
        ->deleteJson(route('solutions.integrations.chain.node.remove', [$a, $integration, 1]))
        ->assertOk();

    $layout = $integration->fresh()->viz_layout;

    // C keeps ITS position and comment (index 2 -> 1), it doesn't inherit B's.
    expect($layout['nodes'])->toBe([['x' => 10, 'y' => 10], ['x' => 30, 'y' => 30]])
        ->and($layout['comments'])->toBe(['nota A', 'nota C'])
        // Only the surviving edge's anchors remain — and they're the right ones.
        ->and($layout['edges'])->toBe([['from' => 't', 'to' => 'b']]);
});

it('leaves a chain with no layout saved alone', function () {
    $a = Solution::factory()->create();
    $b = Solution::factory()->create();

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [
                ['solution_id' => $a->id, 'label' => null, 'kind' => 'system'],
                ['solution_id' => $b->id, 'label' => null, 'kind' => 'system'],
            ],
            'edges' => [],
        ],
        'viz_layout' => null,
    ]);
    attachParticipants($integration, [[$a, 0], [$b, 1]]);

    $this->actingAs(nodeRemoveAdmin())
        ->deleteJson(route('solutions.integrations.chain.node.remove', [$a, $integration, 1]))
        ->assertOk();

    $integration->refresh();

    expect($integration->chain['nodes'])->toHaveCount(1)
        ->and($integration->viz_layout)->toBeNull();
});

it('removes a decision block without touching participants', function () {
    $a = Solution::factory()->create(['name' => 'A']);
    $b = Solution::factory()->create(['name' => 'B']);

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [
                ['solution_id' => $a->id, 'label' => null, 'kind' => 'system'],
                ['solution_id' => null, 'label' => 'Aprovado?', 'kind' => 'decision'],
                ['solution_id' => $b->id, 'label' => null, 'kind' => 'system'],
            ],
            'edges' => [
                ['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null],
                ['from' => 1, 'to' => 2, 'arrow' => '->', 'protocol' => null],
            ],
        ],
    ]);
    attachParticipants($integration, [[$a, 0], [$b, 2]]);

    $this->actingAs(nodeRemoveAdmin())
        ->deleteJson(route('solutions.integrations.chain.node.remove', [$a, $integration, 1]))
        ->assertOk();

    $integration->refresh();

    // Both edges went through the decision block, so the graph is left with two
    // isolated solution blocks — still participants, no edges.
    expect($integration->chain['nodes'])->toHaveCount(2)
        ->and($integration->chain['edges'])->toBeEmpty()
        ->and($integration->participants->pluck('name')->sort()->values()->all())->toBe(['A', 'B']);
});

it('never removes the root node (index 0)', function () {
    $a = Solution::factory()->create();
    $b = Solution::factory()->create();

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [
                ['solution_id' => $a->id, 'label' => null, 'kind' => 'system'],
                ['solution_id' => $b->id, 'label' => null, 'kind' => 'system'],
            ],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($integration, [[$a, 0], [$b, 1]]);

    $this->actingAs(nodeRemoveAdmin())
        ->deleteJson(route('solutions.integrations.chain.node.remove', [$a, $integration, 0]))
        ->assertNotFound();

    expect($integration->fresh()->chain['nodes'])->toHaveCount(2);
});

it('404s a node index outside the chain', function () {
    $a = Solution::factory()->create();

    $integration = Integration::factory()->create([
        'chain' => ['nodes' => [['solution_id' => $a->id, 'label' => null, 'kind' => 'system']], 'edges' => []],
    ]);
    attachParticipants($integration, [[$a, 0]]);

    $this->actingAs(nodeRemoveAdmin())
        ->deleteJson(route('solutions.integrations.chain.node.remove', [$a, $integration, 7]))
        ->assertNotFound();
});

it('forbids non-admins from removing a chain node', function () {
    $a = Solution::factory()->create();
    $b = Solution::factory()->create();

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [
                ['solution_id' => $a->id, 'label' => null, 'kind' => 'system'],
                ['solution_id' => $b->id, 'label' => null, 'kind' => 'system'],
            ],
            'edges' => [],
        ],
    ]);
    attachParticipants($integration, [[$a, 0], [$b, 1]]);

    $this->actingAs(User::factory()->create()) // viewer
        ->deleteJson(route('solutions.integrations.chain.node.remove', [$a, $integration, 1]))
        ->assertForbidden();

    expect($integration->fresh()->chain['nodes'])->toHaveCount(2);
});
