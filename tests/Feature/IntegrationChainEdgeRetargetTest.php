<?php

use App\Enums\UserRole;
use App\Models\Integration;
use App\Models\Solution;
use App\Models\User;
use App\Support\ChainLabeler;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function edgeRetargetAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

it('retargets an edge endpoint to a different block, turning the chain into a free graph', function () {
    $a = Solution::factory()->create(['name' => 'A']);
    $b = Solution::factory()->create(['name' => 'B']);
    $c = Solution::factory()->create(['name' => 'C']);

    // A -> B -> C. Retargets the "from" end of B's 2nd edge to A: A -> B, A -> C.
    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [
                ['solution_id' => $a->id, 'label' => null],
                ['solution_id' => $b->id, 'label' => null],
                ['solution_id' => $c->id, 'label' => null],
            ],
            'edges' => [
                ['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null],
                ['from' => 1, 'to' => 2, 'arrow' => '->', 'protocol' => null],
            ],
        ],
    ]);
    attachParticipants($integration, [[$a, 0], [$b, 1], [$c, 2]]);

    $response = $this->actingAs(edgeRetargetAdmin())
        ->patchJson(route('solutions.integrations.chain.edge.retarget', [$a, $integration, 1]), [
            'end'  => 'from',
            'node' => 0,
        ])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($response->json('from'))->toBe(0)
        ->and($response->json('to'))->toBe(2);

    $integration->refresh();

    expect($integration->chain['edges'][1])->toBe(['from' => 0, 'to' => 2, 'arrow' => '->', 'protocol' => null])
        ->and($integration->participants->pluck('name')->sort()->values()->all())->toBe(['A', 'B', 'C'])
        // The chain is no longer linear (edges[1] doesn't connect nodes[1]->nodes[2]) —
        // this only affects the textual summary format (ChainLabeler::label()).
        ->and((new ChainLabeler)->isLinear($integration->chain))->toBeFalse();
});

it('rejects retargeting an edge endpoint onto its own opposite end (self-loop)', function () {
    $a = Solution::factory()->create();
    $b = Solution::factory()->create();

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $a->id, 'label' => null], ['solution_id' => $b->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($integration, [[$a, 0], [$b, 1]]);

    $this->actingAs(edgeRetargetAdmin())
        ->patchJson(route('solutions.integrations.chain.edge.retarget', [$a, $integration, 0]), [
            'end'  => 'from',
            'node' => 1, // already the 'to' of this edge
        ])
        ->assertStatus(422);

    expect($integration->fresh()->chain['edges'][0])->toBe(['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]);
});

it('rejects a node index outside the chain', function () {
    $a = Solution::factory()->create();
    $b = Solution::factory()->create();

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $a->id, 'label' => null], ['solution_id' => $b->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($integration, [[$a, 0], [$b, 1]]);

    $this->actingAs(edgeRetargetAdmin())
        ->patchJson(route('solutions.integrations.chain.edge.retarget', [$a, $integration, 0]), [
            'end'  => 'to',
            'node' => 5,
        ])
        ->assertStatus(422);
});

it('404s for an edge index outside the chain', function () {
    $a = Solution::factory()->create();
    $b = Solution::factory()->create();

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $a->id, 'label' => null], ['solution_id' => $b->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($integration, [[$a, 0], [$b, 1]]);

    $this->actingAs(edgeRetargetAdmin())
        ->patchJson(route('solutions.integrations.chain.edge.retarget', [$a, $integration, 3]), [
            'end'  => 'to',
            'node' => 1,
        ])
        ->assertNotFound();
});

it('forbids non-admins from retargeting an edge', function () {
    $a = Solution::factory()->create();
    $b = Solution::factory()->create();

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $a->id, 'label' => null], ['solution_id' => $b->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($integration, [[$a, 0], [$b, 1]]);

    $this->actingAs(User::factory()->create()) // viewer
        ->patchJson(route('solutions.integrations.chain.edge.retarget', [$a, $integration, 0]), [
            'end'  => 'to',
            'node' => 0,
        ])
        ->assertForbidden();
});
