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

    // A -> B, C isolado (nasceu sem conexão).
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
        // C deixou de estar isolado (agora tem grau de entrada 1).
        ->and((new ChainLabeler)->isLinear($integration->chain))->toBeFalse();
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
