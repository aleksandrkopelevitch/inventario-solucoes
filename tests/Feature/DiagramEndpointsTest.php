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

it('serves the whole map, ignoring the filters the map no longer has', function () {
    // The status/category/directorate filters left the map on 2026-10-07; an
    // old bookmark or a stale tab still sending them gets the full picture.
    $erp = Solution::factory()->create(['category' => 'erp', 'directorate' => 'TI']);
    $crm = Solution::factory()->create(['category' => 'crm', 'directorate' => 'TI']);
    $mkt = Solution::factory()->create(['category' => 'marketing', 'directorate' => 'Comercial']);
    $tms = Solution::factory()->create(['category' => 'tms', 'directorate' => 'Comercial']);

    attachParticipants(Diagram::factory()->active()->create(), [[$erp, 0], [$crm, 1]]);
    attachParticipants(Diagram::factory()->active()->create(), [[$mkt, 0], [$tms, 1]]);

    $nodes = $this->actingAs(User::factory()->create())
        ->getJson(route('solutions.map.data', ['category' => 'erp', 'directorate' => 'TI', 'status' => 'planned']))
        ->assertOk()
        ->json('nodes');

    expect(collect($nodes)->pluck('id'))->toContain("sol-{$erp->id}", "sol-{$crm->id}", "sol-{$mkt->id}", "sol-{$tms->id}");
});
