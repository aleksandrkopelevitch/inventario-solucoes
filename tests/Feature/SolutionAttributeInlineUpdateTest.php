<?php

use App\Enums\UserRole;
use App\Models\Solution;
use App\Models\User;
use Database\Seeders\AttributeOptionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function solutionAttributeAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

it('updates a single attribute inline from the detail-header card, resyncing the label', function () {
    $this->seed(AttributeOptionSeeder::class);

    $solution = Solution::factory()->create(['category' => 'erp', 'criticality' => null]);

    $response = $this->actingAs(solutionAttributeAdmin())
        ->patchJson(route('solutions.attributes.update', $solution), [
            'criticality' => 'high',
        ])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    // Only the detail-header badges reflect this — the detail page has no
    // solutions-index-slot/ResultsCount/FilterChips for the catalog list
    // widgets to land in.
    $ids = collect($response->json('updatableSlots'))->pluck('id');
    expect($ids)->toEqual(collect(['solution-detail-header-slot']));

    $headerSlot = collect($response->json('updatableSlots'))->firstWhere('id', 'solution-detail-header-slot');
    expect($headerSlot['content'])->toContain('Alta');

    expect($solution->fresh()->criticality)->toBe('high');
});

it('clears a nullable attribute back to null (blank sentinel from the select)', function () {
    $this->seed(AttributeOptionSeeder::class);

    $solution = Solution::factory()->create(['criticality' => 'high']);

    $this->actingAs(solutionAttributeAdmin())
        ->patchJson(route('solutions.attributes.update', $solution), [
            'criticality' => '',
        ])
        ->assertOk();

    expect($solution->fresh()->criticality)->toBeNull();
});

it('rejects clearing a non-nullable attribute like category', function () {
    $this->seed(AttributeOptionSeeder::class);

    $solution = Solution::factory()->create(['category' => 'erp']);

    $this->actingAs(solutionAttributeAdmin())
        ->patchJson(route('solutions.attributes.update', $solution), [
            'category' => '',
        ])
        ->assertStatus(422);

    expect($solution->fresh()->category)->toBe('erp');
});

it('rejects a value that is not a valid option for that group', function () {
    $this->seed(AttributeOptionSeeder::class);

    $solution = Solution::factory()->create();

    $this->actingAs(solutionAttributeAdmin())
        ->patchJson(route('solutions.attributes.update', $solution), [
            'environment' => 'not-a-real-option',
        ])
        ->assertStatus(422);
});

it('forbids non-admins from editing an attribute inline', function () {
    $this->seed(AttributeOptionSeeder::class);

    $solution = Solution::factory()->create();

    $this->actingAs(User::factory()->create()) // viewer
        ->patchJson(route('solutions.attributes.update', $solution), [
            'criticality' => 'high',
        ])
        ->assertForbidden();
});

it('shows every attribute in the detail header, even blank ones, instead of dropping them', function () {
    $this->seed(AttributeOptionSeeder::class);

    $solution = Solution::factory()->create([
        'category'    => 'erp',
        'criticality' => null,
        'environment' => null,
        'cloud'       => null,
        'directorate' => null,
    ]);

    $this->actingAs(solutionAttributeAdmin())
        ->get(route('solutions.show', $solution))
        ->assertOk()
        ->assertSee('Criticidade')
        ->assertSee('Ambiente')
        ->assertSee('Hospedagem')
        ->assertSee('Diretoria')
        ->assertSeeText('Não informado');
});

it('lets a viewer see the attribute grid read-only, without the editable selects', function () {
    $this->seed(AttributeOptionSeeder::class);

    $solution = Solution::factory()->create(['category' => 'erp']);

    $response = $this->actingAs(User::factory()->create()) // viewer
        ->get(route('solutions.show', $solution))
        ->assertOk();

    $response->assertDontSee('data-ak-solution-attribute', false);
});
