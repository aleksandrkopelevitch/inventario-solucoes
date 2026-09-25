<?php

use App\Actions\SetDiagramSystems;
use App\Actions\SyncDiagramFromChain;
use App\Enums\UserRole;
use App\Models\Diagram;
use App\Models\Solution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/**
 * A drawing shaped like every generated process and data flow: lanes and
 * neutral blocks, not one of them naming a solution — which is exactly the case
 * the manual half of `diagram_solution` exists for.
 */
function lanedDiagram(): Diagram
{
    return Diagram::factory()->create([
        'chain' => [
            'nodes' => [
                ['solution_id' => null, 'label' => 'Abre o chamado', 'kind' => 'step'],
                ['solution_id' => null, 'label' => 'Aprovado?', 'kind' => 'decision'],
            ],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
}

it('lets a drawing with no system block name the systems it is about', function () {
    $diagram = lanedDiagram();
    $erp = Solution::factory()->create();
    $crm = Solution::factory()->create();

    app(SyncDiagramFromChain::class)->handle($diagram);
    expect($diagram->fresh()->participants)->toHaveCount(0);

    app(SetDiagramSystems::class)->handle($diagram, [$erp->id, $crm->id]);

    expect($diagram->fresh()->participants->pluck('id')->sort()->values()->all())
        ->toBe(collect([$erp->id, $crm->id])->sort()->values()->all());
});

it('keeps the declared systems when the chain is re-derived', function () {
    $diagram = lanedDiagram();
    $declared = Solution::factory()->create();

    app(SetDiagramSystems::class)->handle($diagram, [$declared->id]);

    // Every chain mutation runs the derivation, and it used to detach the whole
    // pivot — a declared system would have survived exactly until the next
    // block was dragged.
    app(SyncDiagramFromChain::class)->handle($diagram->fresh());

    expect($diagram->fresh()->participants->pluck('id')->all())->toBe([$declared->id]);
});

it('never lets a declared system decide where the flow starts or ends', function () {
    $diagram = lanedDiagram();
    $declared = Solution::factory()->create();

    app(SetDiagramSystems::class)->handle($diagram, [$declared->id]);
    app(SyncDiagramFromChain::class)->handle($diagram->fresh());

    // source/target describe the FLOW, and a declared system has no edge to
    // read a direction from.
    expect($diagram->fresh()->source_solution_id)->toBeNull()
        ->and($diagram->fresh()->target_solution_id)->toBeNull();
});

it('drops a declared system once the chain grows a block for it', function () {
    $erp = Solution::factory()->create();
    $diagram = lanedDiagram();

    app(SetDiagramSystems::class)->handle($diagram, [$erp->id]);

    $diagram->update(['chain' => [
        'nodes' => [
            ['solution_id' => $erp->id, 'label' => null, 'kind' => 'system'],
            ['solution_id' => null, 'label' => 'Aprovado?', 'kind' => 'decision'],
        ],
        'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
    ]]);

    app(SyncDiagramFromChain::class)->handle($diagram);

    // Listed ONCE, and as a drawn participant — not twice.
    $participants = $diagram->fresh()->participants;

    expect($participants->pluck('id')->all())->toBe([$erp->id])
        ->and((bool) $participants->first()->pivot->manual)->toBeFalse();
});

it('refuses to declare a system that is already a block on the canvas', function () {
    $erp = Solution::factory()->create();
    $other = Solution::factory()->create();

    $diagram = Diagram::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $erp->id, 'label' => null, 'kind' => 'system']],
            'edges' => [],
        ],
    ]);
    app(SyncDiagramFromChain::class)->handle($diagram);

    app(SetDiagramSystems::class)->handle($diagram->fresh(), [$erp->id, $other->id]);

    $participants = $diagram->fresh()->participants;

    expect($participants->pluck('id')->sort()->values()->all())
        ->toBe(collect([$erp->id, $other->id])->sort()->values()->all())
        ->and($participants->where('id', $erp->id)->first()->pivot->manual)->toEqual(false)
        ->and($participants->where('id', $other->id)->first()->pivot->manual)->toEqual(true);
});

it('replaces the whole declared set, so removing the last one persists', function () {
    $diagram = lanedDiagram();
    $a = Solution::factory()->create();
    $b = Solution::factory()->create();

    app(SetDiagramSystems::class)->handle($diagram, [$a->id, $b->id]);
    app(SetDiagramSystems::class)->handle($diagram->fresh(), []);

    expect($diagram->fresh()->participants)->toHaveCount(0);
});

it('saves the declared systems over the endpoint and refuses a viewer', function () {
    $diagram = lanedDiagram();
    $erp = Solution::factory()->create();

    $this->actingAs(User::factory()->create(['role' => UserRole::Viewer->value]))
        ->patchJson(route('diagrams.systems', $diagram), [
            'solutions' => [['value' => $erp->id, 'label' => $erp->name]],
        ])
        ->assertForbidden();

    expect($diagram->fresh()->participants)->toHaveCount(0);

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->patchJson(route('diagrams.systems', $diagram), [
            'solutions' => [['value' => $erp->id, 'label' => $erp->name]],
        ])
        ->assertOk()
        ->assertJsonPath('type', 'success');

    expect($diagram->fresh()->participants->pluck('id')->all())->toBe([$erp->id]);
});

it('renders the panel with both halves named', function () {
    $drawn = Solution::factory()->create(['name' => 'ERP Desenhado']);
    $declared = Solution::factory()->create(['name' => 'CRM Declarado']);

    $diagram = Diagram::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $drawn->id, 'label' => null, 'kind' => 'system']],
            'edges' => [],
        ],
    ]);
    app(SyncDiagramFromChain::class)->handle($diagram);
    app(SetDiagramSystems::class)->handle($diagram->fresh(), [$declared->id]);

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->get(route('diagrams.show', $diagram))
        ->assertOk()
        ->assertSee('Sistemas envolvidos')
        ->assertSee('Desenhados no canvas')
        ->assertSee('ERP Desenhado')
        ->assertSee('CRM Declarado');
});
