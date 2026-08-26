<?php

use App\Enums\UserRole;
use App\Models\Diagram;
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

    $diagram = Diagram::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($diagram, [[$svl, 0], [$sap, 1]]);

    $response = $this->actingAs(nodeUpdateAdmin())
        ->patchJson(route('diagrams.chain.node.update', [$diagram, 1]), [
            'solution_id' => $viasoft->id,
        ])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($response->json('node.label'))->toBe('Viasoft')
        ->and($response->json('node.solutionId'))->toBe($viasoft->id)
        ->and($response->json('summary'))->toBe('SVL -> Viasoft');

    $diagram->refresh();

    expect($diagram->chain['nodes'][1])->toBe(['solution_id' => $viasoft->id, 'label' => null, 'kind' => 'system'])
        ->and($diagram->target_solution_id)->toBe($viasoft->id)
        ->and($diagram->participants->pluck('name')->sort()->values()->all())
        ->toBe(['SVL', 'Viasoft']);
});

it('retitles a chain node to free text, dropping it from participants but keeping the segment', function () {
    $this->seed(AttributeOptionSeeder::class);

    $svl = Solution::factory()->create(['name' => 'SVL']);
    $sap = Solution::factory()->create(['name' => 'SAP']);

    $diagram = Diagram::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($diagram, [[$svl, 0], [$sap, 1]]);

    $this->actingAs(nodeUpdateAdmin())
        ->patchJson(route('diagrams.chain.node.update', [$diagram, 1]), [
            'label' => 'Sistema legado',
        ])
        ->assertOk();

    $diagram->refresh();

    // The only remaining participant (SVL) becomes both source and target by fallback —
    // same rule as SyncDiagramFromChain when only one Solution node is left.
    expect($diagram->chain['nodes'][1])->toBe(['solution_id' => null, 'label' => 'Sistema legado', 'kind' => 'system'])
        ->and($diagram->participants->pluck('name')->all())->toBe(['SVL'])
        ->and($diagram->source_solution_id)->toBe($svl->id)
        ->and($diagram->target_solution_id)->toBe($svl->id);
});

it('converts a solution block into a decision block, dropping it from participants', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $sap = Solution::factory()->create(['name' => 'SAP']);

    $diagram = Diagram::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($diagram, [[$svl, 0], [$sap, 1]]);

    $response = $this->actingAs(nodeUpdateAdmin())
        ->patchJson(route('diagrams.chain.node.update', [$diagram, 1]), [
            'kind'        => 'decision',
            'label'       => 'Estoque disponível?',
            'solution_id' => $sap->id, // a decision block never keeps a solution
        ])
        ->assertOk();

    expect($response->json('node.kind'))->toBe('decision')
        ->and($response->json('node.solution'))->toBeFalse()
        ->and($response->json('node.icon'))->toContain('<svg')
        ->and($response->json('summary'))->toBe('SVL -> Estoque disponível?');

    $diagram->refresh();

    expect($diagram->chain['nodes'][1])->toBe(['solution_id' => null, 'label' => 'Estoque disponível?', 'kind' => 'decision'])
        // The edge is untouched: converting a block never rewires the graph.
        ->and($diagram->chain['edges'])->toBe([['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]])
        ->and($diagram->participants->pluck('name')->all())->toBe(['SVL']);
});

it('converts an actor block back into a registered solution', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $sap = Solution::factory()->create(['name' => 'SAP']);

    $diagram = Diagram::factory()->create([
        'chain' => [
            'nodes' => [
                ['solution_id' => $svl->id, 'label' => null, 'kind' => 'system'],
                ['solution_id' => null, 'label' => 'Vendedor', 'kind' => 'actor'],
            ],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($diagram, [[$svl, 0]]);

    $this->actingAs(nodeUpdateAdmin())
        ->patchJson(route('diagrams.chain.node.update', [$diagram, 1]), [
            'kind'        => 'system',
            'solution_id' => $sap->id,
        ])
        ->assertOk();

    $diagram->refresh();

    expect($diagram->chain['nodes'][1])->toBe(['solution_id' => $sap->id, 'label' => null, 'kind' => 'system'])
        ->and($diagram->participants->pluck('name')->sort()->values()->all())->toBe(['SAP', 'SVL']);
});

it('never allows retitling the root node (index 0)', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $sap = Solution::factory()->create(['name' => 'SAP']);
    $viasoft = Solution::factory()->create(['name' => 'Viasoft']);

    $diagram = Diagram::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($diagram, [[$svl, 0], [$sap, 1]]);

    $this->actingAs(nodeUpdateAdmin())
        ->patchJson(route('diagrams.chain.node.update', [$diagram, 0]), [
            'solution_id' => $viasoft->id,
        ])
        ->assertNotFound();

    expect($diagram->fresh()->chain['nodes'][0])->toBe(['solution_id' => $svl->id, 'label' => null]);
});

it('404s for a node index outside the chain', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $sap = Solution::factory()->create(['name' => 'SAP']);

    $diagram = Diagram::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($diagram, [[$svl, 0], [$sap, 1]]);

    $this->actingAs(nodeUpdateAdmin())
        ->patchJson(route('diagrams.chain.node.update', [$diagram, 5]), [
            'label' => 'Qualquer coisa',
        ])
        ->assertNotFound();
});

it('rejects a node update without a system and without free text', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $sap = Solution::factory()->create(['name' => 'SAP']);

    $diagram = Diagram::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($diagram, [[$svl, 0], [$sap, 1]]);

    $response = $this->actingAs(nodeUpdateAdmin())
        ->patchJson(route('diagrams.chain.node.update', [$diagram, 1]), [
            'label' => '',
        ])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    expect($response->json('message'))->toContain('Escolha um sistema');
});

it('forbids non-admins from retitling a chain node', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $sap = Solution::factory()->create(['name' => 'SAP']);

    $diagram = Diagram::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($diagram, [[$svl, 0], [$sap, 1]]);

    $this->actingAs(User::factory()->create()) // viewer
        ->patchJson(route('diagrams.chain.node.update', [$diagram, 1]), [
            'label' => 'Sistema legado',
        ])
        ->assertForbidden();
});
