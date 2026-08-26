<?php

use App\Models\Diagram;
use App\Models\Solution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('serves the global map data endpoint as valid JSON', function () {
    $this->actingAs(User::factory()->create())
        ->getJson(route('solutions.map.data'))
        ->assertOk()
        ->assertJsonStructure(['nodes', 'edges']);
});

it('renders the map page container', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('solutions.map'))
        ->assertOk()
        ->assertSee('Mapa de integrações');
});

it('wires the category and directorate query params through to the graph filters', function () {
    // DiagramGraphServiceTest covers the filtering logic itself in
    // isolation — this proves the controller actually reads these two query
    // params (not typo'd/swapped) and passes them through on a real request.
    $erp = Solution::factory()->create(['category' => 'erp', 'directorate' => 'TI']);
    $crm = Solution::factory()->create(['category' => 'crm', 'directorate' => 'TI']);
    $mkt = Solution::factory()->create(['category' => 'marketing', 'directorate' => 'Comercial']);
    $tms = Solution::factory()->create(['category' => 'tms', 'directorate' => 'Comercial']);

    $withErp = Diagram::factory()->active()->create(['source_solution_id' => $erp->id, 'target_solution_id' => $crm->id]);
    attachParticipants($withErp, [[$erp, 0], [$crm, 1]]);

    $withoutErp = Diagram::factory()->active()->create(['source_solution_id' => $mkt->id, 'target_solution_id' => $tms->id]);
    attachParticipants($withoutErp, [[$mkt, 0], [$tms, 1]]);

    $user = User::factory()->create();

    $byCategory = $this->actingAs($user)
        ->getJson(route('solutions.map.data', ['category' => 'erp']))
        ->assertOk()
        ->json();
    expect(collect($byCategory['nodes'])->pluck('id'))
        ->toContain("sol-{$erp->id}", "sol-{$crm->id}")
        ->not->toContain("sol-{$mkt->id}", "sol-{$tms->id}");

    $byDirectorate = $this->actingAs($user)
        ->getJson(route('solutions.map.data', ['directorate' => 'Comercial']))
        ->assertOk()
        ->json();
    expect(collect($byDirectorate['nodes'])->pluck('id'))
        ->toContain("sol-{$mkt->id}", "sol-{$tms->id}")
        ->not->toContain("sol-{$erp->id}", "sol-{$crm->id}");
});
