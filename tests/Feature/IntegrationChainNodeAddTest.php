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

it('appends a registered solution as a pure block, with no edge, resyncing participants', function () {
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
        ])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($response->json('node.label'))->toBe('Viasoft')
        ->and($response->json('node.solutionId'))->toBe($viasoft->id)
        ->and($response->json('node.kind'))->toBe('system')
        // The block is born isolated, so the summary lists the edge plus it.
        ->and($response->json('summary'))->toBe('SVL -> SAP, Viasoft');

    $integration->refresh();

    expect($integration->chain['nodes'])->toHaveCount(3)
        ->and($integration->chain['nodes'][2])->toBe(['solution_id' => $viasoft->id, 'label' => null, 'kind' => 'system'])
        // No new edge — wiring is a separate gesture (addEdge/retargetEdge).
        ->and($integration->chain['edges'])->toBe([
            ['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null],
        ])
        // A solution block becomes a participant even with no edge yet.
        ->and($integration->participants->pluck('name')->sort()->values()->all())
        ->toBe(['SAP', 'SVL', 'Viasoft'])
        // ...but it must NOT become the flow's endpoint: the isolated block has
        // in === 0 and out === 0, and being the last node it would win both
        // picks in SyncIntegrationFromChain. The flow still ends at SAP.
        ->and($integration->source_solution_id)->toBe($svl->id)
        ->and($integration->target_solution_id)->toBe($sap->id);
});

it('appends a free-text block', function () {
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
        ])
        ->assertOk();

    expect($response->json('node.label'))->toBe('Sistema legado')
        ->and($response->json('node.solution'))->toBeFalse()
        ->and($response->json('node.icon'))->toBeNull(); // system blocks have no kind icon

    $integration->refresh();

    expect($integration->chain['nodes'][1])->toBe(['solution_id' => null, 'label' => 'Sistema legado', 'kind' => 'system'])
        ->and($integration->participants->pluck('name')->all())->toBe(['SVL']);
});

it('appends a decision block and an actor block, both free text with their kind icon', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);

    $integration = Integration::factory()->create([
        'chain' => ['nodes' => [['solution_id' => $svl->id, 'label' => null]], 'edges' => []],
    ]);
    attachParticipants($integration, [[$svl, 0]]);

    $decision = $this->actingAs(nodeAddAdmin())
        ->postJson(route('solutions.integrations.chain.node.add', [$svl, $integration]), [
            'kind'  => 'decision',
            'label' => 'Pedido aprovado?',
        ])
        ->assertOk();

    $actor = $this->actingAs(nodeAddAdmin())
        ->postJson(route('solutions.integrations.chain.node.add', [$svl, $integration]), [
            'kind'  => 'actor',
            'label' => 'Vendedor',
        ])
        ->assertOk();

    expect($decision->json('node.kind'))->toBe('decision')
        ->and($decision->json('node.icon'))->toContain('<svg')
        ->and($actor->json('node.kind'))->toBe('actor')
        ->and($actor->json('node.icon'))->toContain('<svg');

    $integration->refresh();

    expect($integration->chain['nodes'][1])->toBe(['solution_id' => null, 'label' => 'Pedido aprovado?', 'kind' => 'decision'])
        ->and($integration->chain['nodes'][2])->toBe(['solution_id' => null, 'label' => 'Vendedor', 'kind' => 'actor'])
        // Neither kind can be a catalog solution, so participants don't change.
        ->and($integration->participants->pluck('name')->all())->toBe(['SVL']);
});

it('never lets a decision/actor block reference a solution', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $sap = Solution::factory()->create(['name' => 'SAP']);

    $integration = Integration::factory()->create([
        'chain' => ['nodes' => [['solution_id' => $svl->id, 'label' => null]], 'edges' => []],
    ]);
    attachParticipants($integration, [[$svl, 0]]);

    $this->actingAs(nodeAddAdmin())
        ->postJson(route('solutions.integrations.chain.node.add', [$svl, $integration]), [
            'kind'        => 'actor',
            'label'       => 'Vendedor',
            'solution_id' => $sap->id, // dropped before validation
        ])
        ->assertOk();

    $integration->refresh();

    expect($integration->chain['nodes'][1])->toBe(['solution_id' => null, 'label' => 'Vendedor', 'kind' => 'actor'])
        ->and($integration->participants->pluck('name')->all())->toBe(['SVL']);
});

it('rejects a decision/actor block with no text', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);

    $integration = Integration::factory()->create([
        'chain' => ['nodes' => [['solution_id' => $svl->id, 'label' => null]], 'edges' => []],
    ]);
    attachParticipants($integration, [[$svl, 0]]);

    $response = $this->actingAs(nodeAddAdmin())
        ->postJson(route('solutions.integrations.chain.node.add', [$svl, $integration]), [
            'kind' => 'decision',
        ])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    expect($response->json('message'))->toContain('Informe o texto');
});

it('rejects an unknown block kind', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);

    $integration = Integration::factory()->create([
        'chain' => ['nodes' => [['solution_id' => $svl->id, 'label' => null]], 'edges' => []],
    ]);
    attachParticipants($integration, [[$svl, 0]]);

    $this->actingAs(nodeAddAdmin())
        ->postJson(route('solutions.integrations.chain.node.add', [$svl, $integration]), [
            'kind'  => 'gateway',
            'label' => 'Qualquer coisa',
        ])
        ->assertStatus(422);
});

it('rejects a new block without a system and without free text', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);

    $integration = Integration::factory()->create([
        'chain' => ['nodes' => [['solution_id' => $svl->id, 'label' => null]], 'edges' => []],
    ]);
    attachParticipants($integration, [[$svl, 0]]);

    $response = $this->actingAs(nodeAddAdmin())
        ->postJson(route('solutions.integrations.chain.node.add', [$svl, $integration]), [])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    expect($response->json('message'))->toContain('Escolha um sistema');
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
        ])
        ->assertForbidden();
});
