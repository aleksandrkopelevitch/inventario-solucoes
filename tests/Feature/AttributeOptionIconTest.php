<?php

use App\Enums\AttributeGroup;
use App\Models\AttributeOption;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('saves a heroicon on environment/cloud options, the only groups that support it', function () {
    $this->actingAs(admin())
        ->postJson(route('attribute-options.store', AttributeGroup::Environment), ['label' => 'SaaS interno', 'icon' => 'server-stack'])
        ->assertOk();

    $this->assertDatabaseHas('attribute_options', ['group' => 'environment', 'label' => 'SaaS interno', 'icon' => 'server-stack']);
});

it('ignores an icon submitted for a group that does not support it', function () {
    $this->actingAs(admin())
        ->postJson(route('attribute-options.store', AttributeGroup::Category), ['label' => 'Nova categoria', 'icon' => 'cloud'])
        ->assertOk();

    $this->assertDatabaseHas('attribute_options', ['group' => 'category', 'label' => 'Nova categoria', 'icon' => null]);
});

it('rejects an icon that does not exist in the heroicons set', function () {
    $response = $this->actingAs(admin())
        ->postJson(route('attribute-options.store', AttributeGroup::Cloud), ['label' => 'Azure', 'icon' => 'this-icon-does-not-exist'])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    expect($response->json('message'))->toContain('não existe');
});

it('updates the icon of an existing option', function () {
    $option = AttributeOption::create(['group' => 'cloud', 'value' => 'azure', 'label' => 'Azure', 'icon' => null]);

    $this->actingAs(admin())
        ->patchJson(route('attribute-options.update', $option), ['label' => 'Azure', 'icon' => 'cloud'])
        ->assertOk();

    expect($option->fresh()->icon)->toBe('cloud');
});
