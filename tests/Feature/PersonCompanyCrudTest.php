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
