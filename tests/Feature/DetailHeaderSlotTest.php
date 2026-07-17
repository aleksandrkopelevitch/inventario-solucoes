<?php

use App\Models\Company;
use App\Models\Person;
use App\Models\Solution;
use App\Models\User;
use Database\Seeders\AttributeOptionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/**
 * Closes the gap found in the audit: editing a solution/person/company from
 * its OWN detail page (not the listing) only reflected on screen if it
 * happened to have the right id — but the controller only returned the
 * listing slot, which doesn't exist on the detail page. The `*\DetailHeader`
 * components close this: the controller returns both slots, and
 * `ajax-slot.js` silently ignores whichever isn't on the current page.
 */
it('renders the solution-detail-header-slot id on the solution detail page', function () {
    $solution = Solution::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('solutions.show', $solution))
        ->assertOk()
        ->assertSee('id="solution-detail-header-slot"', false);
});

it('updates both the index slot and the detail-header slot when editing a solution', function () {
    $this->seed(AttributeOptionSeeder::class);

    $solution = Solution::factory()->create(['name' => 'Antes', 'category' => 'erp', 'status' => 'active']);

    $response = $this->actingAs(admin())
        ->patchJson(route('solutions.update', $solution), [
            'name'     => 'Depois',
            'category' => $solution->category,
            'status'   => $solution->status,
        ])
        ->assertOk()
        ->json();

    $ids = collect($response['updatableSlots'])->pluck('id');
    expect($ids)->toContain('solutions-index-slot', 'solution-detail-header-slot');

    $headerSlot = collect($response['updatableSlots'])->firstWhere('id', 'solution-detail-header-slot');
    expect($headerSlot['content'])->toContain('Depois');
});

it('only updates the index slot (not a detail-header slot) when creating a new solution', function () {
    $this->seed(AttributeOptionSeeder::class);

    $response = $this->actingAs(admin())
        ->postJson(route('solutions.store'), [
            'name'     => 'Novo Sistema',
            'category' => 'erp',
            'status'   => 'active',
        ])
        ->assertOk()
        ->json();

    $ids = collect($response['updatableSlots'])->pluck('id');
    expect($ids)->toContain('solutions-index-slot')
        ->not->toContain('solution-detail-header-slot');
});

it('renders the person-detail-header-slot id on the person detail page', function () {
    $person = Person::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('people.show', $person))
        ->assertOk()
        ->assertSee('id="person-detail-header-slot"', false);
});

it('updates both the index slot and the detail-header slot when editing a person', function () {
    $person = Person::factory()->create(['name' => 'Maria']);

    $response = $this->actingAs(admin())
        ->patchJson(route('people.update', $person), ['name' => 'Maria Souza'])
        ->assertOk()
        ->json();

    $ids = collect($response['updatableSlots'])->pluck('id');
    expect($ids)->toContain('people-index-slot', 'person-detail-header-slot');

    $headerSlot = collect($response['updatableSlots'])->firstWhere('id', 'person-detail-header-slot');
    expect($headerSlot['content'])->toContain('Maria Souza');
});

it('renders the company-detail-header-slot id on the company detail page', function () {
    $company = Company::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('companies.show', $company))
        ->assertOk()
        ->assertSee('id="company-detail-header-slot"', false);
});

it('updates both the index slot and the detail-header slot when editing a company', function () {
    $company = Company::factory()->create(['name' => 'Antiga Razão']);

    $response = $this->actingAs(admin())
        ->patchJson(route('companies.update', $company), ['name' => 'Nova Razão', 'kind' => 'vendor'])
        ->assertOk()
        ->json();

    $ids = collect($response['updatableSlots'])->pluck('id');
    expect($ids)->toContain('companies-index-slot', 'company-detail-header-slot');

    $headerSlot = collect($response['updatableSlots'])->firstWhere('id', 'company-detail-header-slot');
    expect($headerSlot['content'])->toContain('Nova Razão');
});
