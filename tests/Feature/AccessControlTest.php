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

it('no longer exposes the removed dashboard-background customization endpoints', function () {
    // The gradient/photo `#dashboard-bg` feature was dropped with the Leo
    // visual identity, but its two routes stayed reachable by typing the URL —
    // and `updatePreferences()` returned JS that poked a `#dashboard-bg`
    // element the layout no longer renders. Whole cluster removed
    // (BackgroundTheme, BackgroundPhoto, the panel view, customize-panel.js,
    // User::preference()); these assertions keep it from creeping back.
    $this->actingAs(User::factory()->create(['role' => 'admin']));

    $this->get('/profile/customize')->assertNotFound();
    $this->patch('/profile/preferences', ['type' => 'gradient', 'value' => 'x'])->assertNotFound();

    expect(fn () => route('profile.preferences.panel'))
        ->toThrow(InvalidArgumentException::class);
});
