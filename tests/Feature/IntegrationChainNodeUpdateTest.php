<?php

use App\Enums\UserRole;
use App\Models\Integration;
use App\Models\Solution;
use App\Models\User;
use Database\Seeders\AttributeOptionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function nodeUpdateAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

it('retitles a chain node to a different registered solution, resyncing participants and endpoints', function () {
    $this->seed(AttributeOptionSeeder::class);

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

    $response = $this->actingAs(nodeUpdateAdmin())
        ->patchJson(route('solutions.integrations.chain.node.update', [$svl, $integration, 1]), [
            'solution_id' => $viasoft->id,
        ])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($response->json('node.label'))->toBe('Viasoft')
        ->and($response->json('node.solutionId'))->toBe($viasoft->id)
        ->and($response->json('summary'))->toBe('SVL -> Viasoft');

    $integration->refresh();

    expect($integration->chain['nodes'][1])->toBe(['solution_id' => $viasoft->id, 'label' => null])
        ->and($integration->target_solution_id)->toBe($viasoft->id)
        ->and($integration->participants->pluck('name')->sort()->values()->all())
        ->toBe(['SVL', 'Viasoft']);
});

it('retitles a chain node to free text, dropping it from participants but keeping the segment', function () {
    $this->seed(AttributeOptionSeeder::class);

    $svl = Solution::factory()->create(['name' => 'SVL']);
    $sap = Solution::factory()->create(['name' => 'SAP']);

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($integration, [[$svl, 0], [$sap, 1]]);

    $this->actingAs(nodeUpdateAdmin())
        ->patchJson(route('solutions.integrations.chain.node.update', [$svl, $integration, 1]), [
            'label' => 'Sistema legado',
        ])
        ->assertOk();

    $integration->refresh();

    // Único participante restante (SVL) vira origem e destino por fallback —
    // mesma regra de SyncIntegrationFromChain quando sobra um só nó de Solução.
    expect($integration->chain['nodes'][1])->toBe(['solution_id' => null, 'label' => 'Sistema legado'])
        ->and($integration->participants->pluck('name')->all())->toBe(['SVL'])
        ->and($integration->source_solution_id)->toBe($svl->id)
        ->and($integration->target_solution_id)->toBe($svl->id);
});

it('never allows retitling the root node (index 0)', function () {
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

    $this->actingAs(nodeUpdateAdmin())
        ->patchJson(route('solutions.integrations.chain.node.update', [$svl, $integration, 0]), [
            'solution_id' => $viasoft->id,
        ])
        ->assertNotFound();

    expect($integration->fresh()->chain['nodes'][0])->toBe(['solution_id' => $svl->id, 'label' => null]);
});

it('404s for a node index outside the chain', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $sap = Solution::factory()->create(['name' => 'SAP']);

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($integration, [[$svl, 0], [$sap, 1]]);

    $this->actingAs(nodeUpdateAdmin())
        ->patchJson(route('solutions.integrations.chain.node.update', [$svl, $integration, 5]), [
            'label' => 'Qualquer coisa',
        ])
        ->assertNotFound();
});

it('rejects a node update without a system and without free text', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $sap = Solution::factory()->create(['name' => 'SAP']);

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($integration, [[$svl, 0], [$sap, 1]]);

    $response = $this->actingAs(nodeUpdateAdmin())
        ->patchJson(route('solutions.integrations.chain.node.update', [$svl, $integration, 1]), [
            'label' => '',
        ])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    expect($response->json('message'))->toContain('Escolha um sistema');
});

it('forbids non-admins from retitling a chain node', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $sap = Solution::factory()->create(['name' => 'SAP']);

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($integration, [[$svl, 0], [$sap, 1]]);

    $this->actingAs(User::factory()->create()) // viewer
        ->patchJson(route('solutions.integrations.chain.node.update', [$svl, $integration, 1]), [
            'label' => 'Sistema legado',
        ])
        ->assertForbidden();
});
