<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Person;
use App\Models\Solution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function adminUser(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

it('lets an admin create a person linked to a solution with a role', function () {
    $solution = Solution::factory()->create();

    $this->actingAs(adminUser())
        ->postJson(route('people.store'), [
            'name'      => 'João Silva',
            'solutions' => [
                ['value' => (string) $solution->id, 'label' => $solution->name, 'role' => 'technical'],
            ],
        ])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    $this->assertDatabaseHas('people', ['name' => 'João Silva']);
    $this->assertDatabaseHas('person_solution', [
        'solution_id' => $solution->id,
        'role'        => 'technical',
    ]);
});

it('lets an admin update a person', function () {
    $person = Person::factory()->create(['name' => 'Maria']);

    $this->actingAs(adminUser())
        ->patchJson(route('people.update', $person), ['name' => 'Maria Souza'])
        ->assertOk();

    $this->assertDatabaseHas('people', ['id' => $person->id, 'name' => 'Maria Souza']);
});

it('filters people by their solution role', function () {
    // Regression test: Person::scopeFilter()'s role branch used to call
    // wherePivot() inside a whereHas() closure, which isn't a valid method
    // there — it silently resolved to Eloquent's dynamic-where magic instead
    // (`where('pivot', $value)`, a column that doesn't exist), so this
    // filter matched zero people no matter what role was selected.
    $solution = Solution::factory()->create();

    $technical = Person::factory()->create(['name' => 'Ana Técnica']);
    $technical->solutions()->attach($solution->id, ['role' => 'technical']);

    $business = Person::factory()->create(['name' => 'Bruno Negócio']);
    $business->solutions()->attach($solution->id, ['role' => 'business']);

    $content = $this->actingAs(User::factory()->create())
        ->getJson(route('people.index', ['filter' => ['role' => 'technical']]))
        ->assertOk()
        ->json('updatableSlots.0.content');

    expect($content)->toContain('Ana Técnica')
        ->and($content)->not->toContain('Bruno Negócio');
});

it('renders a person detail page', function () {
    $person = Person::factory()->withCompany()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('people.show', $person))
        ->assertOk()
        ->assertSee($person->name);
});

it('lets an admin create a company', function () {
    $this->actingAs(adminUser())
        ->postJson(route('companies.store'), ['name' => 'ACME Ltda', 'kind' => 'vendor'])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    $this->assertDatabaseHas('companies', ['name' => 'ACME Ltda', 'slug' => 'acme-ltda']);
});

it('lets an admin update a company', function () {
    $company = Company::factory()->create();

    $this->actingAs(adminUser())
        ->patchJson(route('companies.update', $company), ['name' => 'Nova Razão', 'kind' => 'partner'])
        ->assertOk();

    $this->assertDatabaseHas('companies', ['id' => $company->id, 'name' => 'Nova Razão', 'kind' => 'partner']);
});

it('returns the companies index slot as JSON filtered by kind', function () {
    Company::factory()->create(['name' => 'Interna SA', 'kind' => 'internal']);
    Company::factory()->create(['name' => 'Fornecedora SA', 'kind' => 'vendor']);

    $content = $this->actingAs(User::factory()->create())
        ->getJson(route('companies.index', ['filter' => ['kind' => 'internal']]))
        ->assertOk()
        ->json('updatableSlots.0.content');

    expect($content)->toContain('Interna SA')
        ->and($content)->not->toContain('Fornecedora SA');
});

it('preserves the active filter when updating a company refreshes the index slot', function () {
    // Regression coverage for the "preserving filters when a mutation
    // refreshes a filtered index slot" chain (CLAUDE.md), same as Solutions.
    $matching = Company::factory()->create(['name' => 'Interna SA', 'kind' => 'internal']);
    Company::factory()->create(['name' => 'Fornecedora SA', 'kind' => 'vendor']);

    $response = $this->actingAs(adminUser())
        ->patchJson(route('companies.update', ['company' => $matching, 'filter' => ['kind' => 'internal']]), [
            'name' => 'Interna Renomeada',
            'kind' => 'internal',
        ])
        ->assertOk();

    $indexSlot = collect($response->json('updatableSlots'))->firstWhere('id', 'companies-index-slot');
    expect($indexSlot['content'])->toContain('Interna Renomeada')
        ->and($indexSlot['content'])->not->toContain('Fornecedora SA');
});

it('preserves the active filter when updating a person refreshes the index slot', function () {
    $solution = Solution::factory()->create();
    $matching = Person::factory()->create(['name' => 'Ana Matching']);
    $matching->solutions()->attach($solution->id, ['role' => 'technical']);
    Person::factory()->create(['name' => 'Bruno Sem Vínculo']);

    $response = $this->actingAs(adminUser())
        ->patchJson(route('people.update', ['person' => $matching, 'filter' => ['role' => 'technical']]), [
            'name' => 'Ana Renomeada',
        ])
        ->assertOk();

    $indexSlot = collect($response->json('updatableSlots'))->firstWhere('id', 'people-index-slot');
    expect($indexSlot['content'])->toContain('Ana Renomeada')
        ->and($indexSlot['content'])->not->toContain('Bruno Sem Vínculo');
});
