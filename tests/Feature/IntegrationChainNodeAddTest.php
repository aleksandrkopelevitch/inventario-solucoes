<?php

use App\Enums\UserRole;
use App\Models\Integration;
use App\Models\Solution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function nodeAddAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

it('appends a registered solution to the end of the chain, resyncing participants and the target', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $sap = Solution::factory()->create(['name' => 'SAP']);
    $viasoft = Solution::factory()->create(['name' => 'Viasoft']);

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($integration, [[$svl, 0], [$sap, 1]]);

    $response = $this->actingAs(nodeAddAdmin())
        ->postJson(route('solutions.integrations.chain.node.add', [$svl, $integration]), [
            'solution_id' => $viasoft->id,
            'arrow'       => '->',
            'protocol'    => 'rest',
        ])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($response->json('node.label'))->toBe('Viasoft')
        ->and($response->json('node.solutionId'))->toBe($viasoft->id)
        ->and($response->json('from'))->toBe(1)
        ->and($response->json('arrow'))->toBe('->')
        ->and($response->json('protocol.value'))->toBe('rest')
        ->and($response->json('summary'))->toBe('SVL -> SAP -> Viasoft');

    $integration->refresh();

    expect($integration->chain['nodes'])->toHaveCount(3)
        ->and($integration->chain['nodes'][2])->toBe(['solution_id' => $viasoft->id, 'label' => null])
        ->and($integration->chain['edges'])->toBe([
            ['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null],
            ['from' => 1, 'to' => 2, 'arrow' => '->', 'protocol' => 'rest'],
        ])
        ->and($integration->target_solution_id)->toBe($viasoft->id)
        ->and($integration->participants->pluck('name')->sort()->values()->all())
        ->toBe(['SAP', 'SVL', 'Viasoft']);
});

it('appends a free-text block to the end of the chain', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null]],
            'edges' => [],
        ],
    ]);
    attachParticipants($integration, [[$svl, 0]]);

    $response = $this->actingAs(nodeAddAdmin())
        ->postJson(route('solutions.integrations.chain.node.add', [$svl, $integration]), [
            'label' => 'Sistema legado',
            'arrow' => '<-',
        ])
        ->assertOk();

    expect($response->json('node.label'))->toBe('Sistema legado')
        ->and($response->json('node.solution'))->toBeFalse();

    $integration->refresh();

    expect($integration->chain['nodes'][1])->toBe(['solution_id' => null, 'label' => 'Sistema legado'])
        ->and($integration->participants->pluck('name')->all())->toBe(['SVL']);
});

it('rejects a new block without a system and without free text', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);

    $integration = Integration::factory()->create([
        'chain' => ['nodes' => [['solution_id' => $svl->id, 'label' => null]], 'edges' => []],
    ]);
    attachParticipants($integration, [[$svl, 0]]);

    $response = $this->actingAs(nodeAddAdmin())
        ->postJson(route('solutions.integrations.chain.node.add', [$svl, $integration]), [
            'arrow' => '->',
        ])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    expect($response->json('message'))->toContain('Escolha um sistema');
});

it('appends a block with no connection at all when the panel omits the arrow ("Sem conexão")', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $sap = Solution::factory()->create(['name' => 'SAP']);
    $isolated = Solution::factory()->create(['name' => 'Isolado']);

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($integration, [[$svl, 0], [$sap, 1]]);

    $response = $this->actingAs(nodeAddAdmin())
        ->postJson(route('solutions.integrations.chain.node.add', [$svl, $integration]), [
            'solution_id' => $isolated->id,
        ])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($response->json('from'))->toBeNull()
        ->and($response->json('arrow'))->toBeNull()
        ->and($response->json('protocol'))->toBeNull();

    $integration->refresh();

    expect($integration->chain['nodes'])->toHaveCount(3)
        ->and($integration->chain['edges'])->toHaveCount(1) // nenhuma ligação nova
        ->and($integration->participants->pluck('name')->sort()->values()->all())
        ->toBe(['Isolado', 'SAP', 'SVL']);
});

it('rejects an invalid arrow direction', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);

    $integration = Integration::factory()->create([
        'chain' => ['nodes' => [['solution_id' => $svl->id, 'label' => null]], 'edges' => []],
    ]);
    attachParticipants($integration, [[$svl, 0]]);

    $this->actingAs(nodeAddAdmin())
        ->postJson(route('solutions.integrations.chain.node.add', [$svl, $integration]), [
            'label' => 'Sistema legado',
            'arrow' => '=>',
        ])
        ->assertStatus(422);
});

it('forbids non-admins from adding a chain node', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);

    $integration = Integration::factory()->create([
        'chain' => ['nodes' => [['solution_id' => $svl->id, 'label' => null]], 'edges' => []],
    ]);
    attachParticipants($integration, [[$svl, 0]]);

    $this->actingAs(User::factory()->create()) // viewer
        ->postJson(route('solutions.integrations.chain.node.add', [$svl, $integration]), [
            'label' => 'Sistema legado',
            'arrow' => '->',
        ])
        ->assertForbidden();
});
