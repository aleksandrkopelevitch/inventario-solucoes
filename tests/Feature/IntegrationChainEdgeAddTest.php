<?php

use App\Enums\UserRole;
use App\Models\Integration;
use App\Models\Solution;
use App\Models\User;
use App\Support\ChainLabeler;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function edgeAddAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

it('creates a new edge between two existing blocks without adding a node ("modo ligar")', function () {
    $a = Solution::factory()->create(['name' => 'A']);
    $b = Solution::factory()->create(['name' => 'B']);
    $c = Solution::factory()->create(['name' => 'C']);

    // A -> B, C isolated (created with no connection).
    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [
                ['solution_id' => $a->id, 'label' => null],
                ['solution_id' => $b->id, 'label' => null],
                ['solution_id' => $c->id, 'label' => null],
            ],
            'edges' => [
                ['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null],
            ],
        ],
    ]);
    attachParticipants($integration, [[$a, 0], [$b, 1], [$c, 2]]);

    $response = $this->actingAs(edgeAddAdmin())
        ->postJson(route('solutions.integrations.chain.edge.add', [$a, $integration]), [
            'from'     => 0,
            'to'       => 2,
            'arrow'    => '->',
            'protocol' => 'rest',
        ])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($response->json('from'))->toBe(0)
        ->and($response->json('to'))->toBe(2)
        ->and($response->json('arrow'))->toBe('->')
        ->and($response->json('protocol.value'))->toBe('rest');

    $integration->refresh();

    expect($integration->chain['edges'])->toHaveCount(2)
        ->and($integration->chain['edges'][1])->toBe(['from' => 0, 'to' => 2, 'arrow' => '->', 'protocol' => 'rest'])
        // C is no longer isolated (now has in-degree 1).
        ->and((new ChainLabeler)->isLinear($integration->chain))->toBeFalse();
});

it('returns the index the new edge got in the chain, so the client never infers it', function () {
    $a = Solution::factory()->create();
    $b = Solution::factory()->create();
    $c = Solution::factory()->create();

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [
                ['solution_id' => $a->id, 'label' => null, 'kind' => 'system'],
                ['solution_id' => $b->id, 'label' => null, 'kind' => 'system'],
                ['solution_id' => $c->id, 'label' => null, 'kind' => 'system'],
            ],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($integration, [[$a, 0], [$b, 1], [$c, 2]]);

    // Every other edge endpoint (protocol, retarget, remove) addresses edges by
    // index, so the response has to say which index this one got.
    $this->actingAs(edgeAddAdmin())
        ->postJson(route('solutions.integrations.chain.edge.add', [$a, $integration]), [
            'from'  => 1,
            'to'    => 2,
            'arrow' => '->',
        ])
        ->assertOk()
        ->assertJson(['index' => 1]);

    $this->actingAs(edgeAddAdmin())
        ->postJson(route('solutions.integrations.chain.edge.add', [$a, $integration]), [
            'from'  => 0,
            'to'    => 2,
            'arrow' => '<->',
        ])
        ->assertOk()
        ->assertJson(['index' => 2]);

    expect($integration->fresh()->chain['edges'][2])
        ->toBe(['from' => 0, 'to' => 2, 'arrow' => '<->', 'protocol' => null]);
});

it('rejects connecting a block to itself', function () {
    $a = Solution::factory()->create();
    $b = Solution::factory()->create();

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $a->id, 'label' => null], ['solution_id' => $b->id, 'label' => null]],
            'edges' => [],
        ],
    ]);
    attachParticipants($integration, [[$a, 0], [$b, 1]]);

    $this->actingAs(edgeAddAdmin())
        ->postJson(route('solutions.integrations.chain.edge.add', [$a, $integration]), [
            'from'  => 1,
            'to'    => 1,
            'arrow' => '->',
        ])
        ->assertStatus(422);

    expect($integration->fresh()->chain['edges'])->toBeEmpty();
});

it('rejects an edge identical to one already in the chain, but allows a second one that differs', function () {
    $a = Solution::factory()->create();
    $b = Solution::factory()->create();

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $a->id, 'label' => null], ['solution_id' => $b->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => 'rest']],
        ],
    ]);
    attachParticipants($integration, [[$a, 0], [$b, 1]]);

    $url = route('solutions.integrations.chain.edge.add', [$a, $integration]);

    // Exact duplicate — dragging the same arrow twice out of another port says
    // nothing new and would double-count degrees in SyncIntegrationFromChain.
    $response = $this->actingAs(edgeAddAdmin())
        ->postJson($url, ['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => 'rest'])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    expect($response->json('message'))->toContain('já estão ligados assim')
        ->and($integration->fresh()->chain['edges'])->toHaveCount(1);

    // Same pair over a different protocol is a legitimate second link.
    $this->actingAs(edgeAddAdmin())
        ->postJson($url, ['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => 'sftp'])
        ->assertOk();

    // As is the same pair in the other direction, with no protocol yet.
    $this->actingAs(edgeAddAdmin())
        ->postJson($url, ['from' => 0, 'to' => 1, 'arrow' => '<-'])
        ->assertOk();

    expect($integration->fresh()->chain['edges'])->toHaveCount(3);
});

it('rejects a from/to index outside the chain', function () {
    $a = Solution::factory()->create();
    $b = Solution::factory()->create();

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $a->id, 'label' => null], ['solution_id' => $b->id, 'label' => null]],
            'edges' => [],
        ],
    ]);
    attachParticipants($integration, [[$a, 0], [$b, 1]]);

    $this->actingAs(edgeAddAdmin())
        ->postJson(route('solutions.integrations.chain.edge.add', [$a, $integration]), [
            'from'  => 0,
            'to'    => 5,
            'arrow' => '->',
        ])
        ->assertStatus(422);
});

it('forbids non-admins from creating a new edge', function () {
    $a = Solution::factory()->create();
    $b = Solution::factory()->create();

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $a->id, 'label' => null], ['solution_id' => $b->id, 'label' => null]],
            'edges' => [],
        ],
    ]);
    attachParticipants($integration, [[$a, 0], [$b, 1]]);

    $this->actingAs(User::factory()->create()) // viewer
        ->postJson(route('solutions.integrations.chain.edge.add', [$a, $integration]), [
            'from'  => 0,
            'to'    => 1,
            'arrow' => '->',
        ])
        ->assertForbidden();
});
