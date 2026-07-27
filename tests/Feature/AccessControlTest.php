<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('forbids a viewer from creating people or companies', function () {
    $viewer = User::factory()->create(); // viewer

    $this->actingAs($viewer)
        ->postJson(route('people.store'), ['name' => 'X'])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->postJson(route('companies.store'), ['name' => 'X', 'kind' => 'vendor'])
        ->assertForbidden();
});

it('still allows a viewer to browse read-only screens', function () {
    $viewer = User::factory()->create();

    $this->actingAs($viewer)->get(route('solutions.index'))->assertOk();
    $this->actingAs($viewer)->get(route('companies.index'))->assertOk();
    $this->actingAs($viewer)->get(route('people.index'))->assertOk();
});
