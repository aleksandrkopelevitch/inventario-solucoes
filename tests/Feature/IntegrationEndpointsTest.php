<?php

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
