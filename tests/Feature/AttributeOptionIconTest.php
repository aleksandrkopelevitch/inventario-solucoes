<?php

use App\Enums\AttributeGroup;
use App\Models\AttributeOption;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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

it('stores a colour and a picture for a hosting value, and nothing of the kind for a category', function () {
    Storage::fake('public');
    $admin = User::factory()->admin()->create();
    $aws = AttributeOption::create(['group' => 'cloud', 'value' => 'aws', 'label' => 'AWS']);

    $this->actingAs($admin)
        ->post(route('attribute-options.update', $aws), [
            '_method' => 'PATCH',
            'label'   => 'AWS',
            'color'   => '#ff9900',
            'image'   => UploadedFile::fake()->image('aws.png', 64, 64),
        ], ['Accept' => 'application/json'])
        ->assertOk();

    $aws->refresh();
    expect($aws->color)->toBe('#ff9900')
        ->and($aws->image_path)->toStartWith('hosting-images/');
    Storage::disk('public')->assertExists($aws->image_path);

    $this->actingAs($admin)
        ->patchJson(route('attribute-options.update', $aws), ['label' => 'AWS', 'color' => '#ff9900', 'image_action' => 'remove'])
        ->assertOk();
    expect($aws->fresh()->image_path)->toBeNull();

    $erp = AttributeOption::create(['group' => 'category', 'value' => 'erp', 'label' => 'ERP']);
    $this->actingAs($admin)
        ->patchJson(route('attribute-options.update', $erp), ['label' => 'ERP', 'color' => '#ff0000'])
        ->assertOk();
    expect($erp->fresh()->color)->toBeNull();
});

it('refuses a colour that is not a hex code', function () {
    $aws = AttributeOption::create(['group' => 'cloud', 'value' => 'aws', 'label' => 'AWS']);

    $this->actingAs(User::factory()->admin()->create())
        ->patchJson(route('attribute-options.update', $aws), ['label' => 'AWS', 'color' => 'red'])
        ->assertStatus(422);
});

it('shows the colour and picture fields only on hosting groups', function () {
    $html = $this->actingAs(User::factory()->admin()->create())
        ->getJson(route('attribute-options.index'))
        ->assertOk()
        ->json('content');

    expect(substr_count($html, 'Cor do container no mapa'))->toBeGreaterThan(0);
});
