<?php

use App\Enums\UserRole;
use App\Models\Diagram;
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

    $diagram = Diagram::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null], ['solution_id' => $sap->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($diagram, [[$svl, 0], [$sap, 1]]);

    $response = $this->actingAs(nodeAddAdmin())
        ->postJson(route('diagrams.chain.node.add', $diagram), [
            'solution_id' => $viasoft->id,
        ])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($response->json('node.label'))->toBe('Viasoft')
        ->and($response->json('node.solutionId'))->toBe($viasoft->id)
        ->and($response->json('node.kind'))->toBe('system')
        // The block is born isolated, so the summary lists the edge plus it.
        ->and($response->json('summary'))->toBe('SVL -> SAP, Viasoft');

    $diagram->refresh();

    expect($diagram->chain['nodes'])->toHaveCount(3)
        ->and($diagram->chain['nodes'][2])->toBe(['solution_id' => $viasoft->id, 'label' => null, 'kind' => 'system'])
        // No new edge — wiring is a separate gesture (addEdge/retargetEdge).
        ->and($diagram->chain['edges'])->toBe([
            ['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null],
        ])
        // A solution block becomes a participant even with no edge yet.
        ->and($diagram->participants->pluck('name')->sort()->values()->all())
        ->toBe(['SAP', 'SVL', 'Viasoft'])
        // ...but it must NOT become the flow's endpoint: the isolated block has
        // in === 0 and out === 0, and being the last node it would win both
        // picks in SyncDiagramFromChain. The flow still ends at SAP.
        ->and($diagram->source_solution_id)->toBe($svl->id)
        ->and($diagram->target_solution_id)->toBe($sap->id);
});

it('appends a free-text block', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);

    $diagram = Diagram::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $svl->id, 'label' => null]],
            'edges' => [],
        ],
    ]);
    attachParticipants($diagram, [[$svl, 0]]);

    $response = $this->actingAs(nodeAddAdmin())
        ->postJson(route('diagrams.chain.node.add', $diagram), [
            'label' => 'Sistema legado',
        ])
        ->assertOk();

    expect($response->json('node.label'))->toBe('Sistema legado')
        ->and($response->json('node.solution'))->toBeFalse()
        ->and($response->json('node.icon'))->toBeNull(); // system blocks have no kind icon

    $diagram->refresh();

    expect($diagram->chain['nodes'][1])->toBe(['solution_id' => null, 'label' => 'Sistema legado', 'kind' => 'system'])
        ->and($diagram->participants->pluck('name')->all())->toBe(['SVL']);
});

it('appends a decision block and an actor block, both free text with their kind icon', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);

    $diagram = Diagram::factory()->create([
        'chain' => ['nodes' => [['solution_id' => $svl->id, 'label' => null]], 'edges' => []],
    ]);
    attachParticipants($diagram, [[$svl, 0]]);

    $decision = $this->actingAs(nodeAddAdmin())
        ->postJson(route('diagrams.chain.node.add', $diagram), [
            'kind'  => 'decision',
            'label' => 'Pedido aprovado?',
        ])
        ->assertOk();

    $actor = $this->actingAs(nodeAddAdmin())
        ->postJson(route('diagrams.chain.node.add', $diagram), [
            'kind'  => 'actor',
            'label' => 'Vendedor',
        ])
        ->assertOk();

    expect($decision->json('node.kind'))->toBe('decision')
        ->and($decision->json('node.icon'))->toContain('<svg')
        ->and($actor->json('node.kind'))->toBe('actor')
        ->and($actor->json('node.icon'))->toContain('<svg');

    $diagram->refresh();

    expect($diagram->chain['nodes'][1])->toBe(['solution_id' => null, 'label' => 'Pedido aprovado?', 'kind' => 'decision'])
        ->and($diagram->chain['nodes'][2])->toBe(['solution_id' => null, 'label' => 'Vendedor', 'kind' => 'actor'])
        // Neither kind can be a catalog solution, so participants don't change.
        ->and($diagram->participants->pluck('name')->all())->toBe(['SVL']);
});

it('never lets a decision/actor block reference a solution', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);
    $sap = Solution::factory()->create(['name' => 'SAP']);

    $diagram = Diagram::factory()->create([
        'chain' => ['nodes' => [['solution_id' => $svl->id, 'label' => null]], 'edges' => []],
    ]);
    attachParticipants($diagram, [[$svl, 0]]);

    $this->actingAs(nodeAddAdmin())
        ->postJson(route('diagrams.chain.node.add', $diagram), [
            'kind'        => 'actor',
            'label'       => 'Vendedor',
            'solution_id' => $sap->id, // dropped before validation
        ])
        ->assertOk();

    $diagram->refresh();

    expect($diagram->chain['nodes'][1])->toBe(['solution_id' => null, 'label' => 'Vendedor', 'kind' => 'actor'])
        ->and($diagram->participants->pluck('name')->all())->toBe(['SVL']);
});

it('rejects a decision/actor block with no text', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);

    $diagram = Diagram::factory()->create([
        'chain' => ['nodes' => [['solution_id' => $svl->id, 'label' => null]], 'edges' => []],
    ]);
    attachParticipants($diagram, [[$svl, 0]]);

    $response = $this->actingAs(nodeAddAdmin())
        ->postJson(route('diagrams.chain.node.add', $diagram), [
            'kind' => 'decision',
        ])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    expect($response->json('message'))->toContain('Informe o texto');
});

it('rejects an unknown block kind', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);

    $diagram = Diagram::factory()->create([
        'chain' => ['nodes' => [['solution_id' => $svl->id, 'label' => null]], 'edges' => []],
    ]);
    attachParticipants($diagram, [[$svl, 0]]);

    $this->actingAs(nodeAddAdmin())
        ->postJson(route('diagrams.chain.node.add', $diagram), [
            'kind'  => 'gateway',
            'label' => 'Qualquer coisa',
        ])
        ->assertStatus(422);
});

it('rejects "image" as a block kind here — only addImageNode() may create one', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);

    $diagram = Diagram::factory()->create([
        'chain' => ['nodes' => [['solution_id' => $svl->id, 'label' => null]], 'edges' => []],
    ]);
    attachParticipants($diagram, [[$svl, 0]]);

    $this->actingAs(nodeAddAdmin())
        ->postJson(route('diagrams.chain.node.add', $diagram), [
            'kind'  => 'image',
            'label' => 'Qualquer coisa',
        ])
        ->assertStatus(422);

    expect($diagram->fresh()->chain['nodes'])->toHaveCount(1);
});

it('rejects a new block without a system and without free text', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);

    $diagram = Diagram::factory()->create([
        'chain' => ['nodes' => [['solution_id' => $svl->id, 'label' => null]], 'edges' => []],
    ]);
    attachParticipants($diagram, [[$svl, 0]]);

    $response = $this->actingAs(nodeAddAdmin())
        ->postJson(route('diagrams.chain.node.add', $diagram), [])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    expect($response->json('message'))->toContain('Escolha um sistema');
});

it('creates a start/end block with no text, defaulting to "Início"/"Fim"', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);

    $diagram = Diagram::factory()->create([
        'chain' => ['nodes' => [['solution_id' => $svl->id, 'label' => null]], 'edges' => []],
    ]);
    attachParticipants($diagram, [[$svl, 0]]);

    $start = $this->actingAs(nodeAddAdmin())
        ->postJson(route('diagrams.chain.node.add', $diagram), [
            'kind' => 'start',
        ])
        ->assertOk();

    $end = $this->actingAs(nodeAddAdmin())
        ->postJson(route('diagrams.chain.node.add', $diagram), [
            'kind' => 'end',
        ])
        ->assertOk();

    expect($start->json('node.label'))->toBe('Início')
        ->and($start->json('node.kind'))->toBe('start')
        ->and($start->json('node.icon'))->toContain('<svg')
        ->and($end->json('node.label'))->toBe('Fim')
        ->and($end->json('node.kind'))->toBe('end');

    $diagram->refresh();

    expect($diagram->chain['nodes'][1])->toBe(['solution_id' => null, 'label' => 'Início', 'kind' => 'start'])
        ->and($diagram->chain['nodes'][2])->toBe(['solution_id' => null, 'label' => 'Fim', 'kind' => 'end'])
        // Neither kind can be a catalog solution, so participants don't change.
        ->and($diagram->participants->pluck('name')->all())->toBe(['SVL']);
});

it('lets a start/end block still take custom free text instead of the default', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);

    $diagram = Diagram::factory()->create([
        'chain' => ['nodes' => [['solution_id' => $svl->id, 'label' => null]], 'edges' => []],
    ]);
    attachParticipants($diagram, [[$svl, 0]]);

    $response = $this->actingAs(nodeAddAdmin())
        ->postJson(route('diagrams.chain.node.add', $diagram), [
            'kind'  => 'start',
            'label' => 'Início do lote noturno',
        ])
        ->assertOk();

    expect($response->json('node.label'))->toBe('Início do lote noturno');
});

it('forbids non-admins from adding a chain node', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);

    $diagram = Diagram::factory()->create([
        'chain' => ['nodes' => [['solution_id' => $svl->id, 'label' => null]], 'edges' => []],
    ]);
    attachParticipants($diagram, [[$svl, 0]]);

    $this->actingAs(User::factory()->create()) // viewer
        ->postJson(route('diagrams.chain.node.add', $diagram), [
            'label' => 'Sistema legado',
        ])
        ->assertForbidden();
});
