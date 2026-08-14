<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Person;
use App\Models\Solution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function companyRelationsAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

// ── People ───────────────────────────────────────────────────────────────

it('attaches a person to the company from its people card', function () {
    $company = Company::factory()->create();
    $person = Person::factory()->create(['company_id' => null, 'name' => 'Ana Silva']);

    $response = $this->actingAs(companyRelationsAdmin())
        ->postJson(route('companies.people.store', $company), ['person_id' => $person->id])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($person->fresh()->company_id)->toBe($company->id);

    // Only the people card comes back — the other card didn't change.
    expect(collect($response->json('updatableSlots'))->pluck('id')->all())
        ->toEqual(['company-people-slot']);
    expect($response->json('updatableSlots.0.content'))->toContain('Ana Silva');
});

it('moves a person that already belonged to another company', function () {
    $from = Company::factory()->create();
    $to = Company::factory()->create();
    $person = Person::factory()->create(['company_id' => $from->id]);

    $this->actingAs(companyRelationsAdmin())
        ->postJson(route('companies.people.store', $to), ['person_id' => $person->id])
        ->assertOk();

    expect($person->fresh()->company_id)->toBe($to->id);
    expect($from->fresh()->people()->count())->toBe(0);
});

it('detaches a person, leaving the person record itself alone', function () {
    $company = Company::factory()->create();
    $person = Person::factory()->create(['company_id' => $company->id]);

    $this->actingAs(companyRelationsAdmin())
        ->deleteJson(route('companies.people.destroy', [$company, $person]))
        ->assertOk();

    expect($person->fresh()->company_id)->toBeNull();
    $this->assertModelExists($person);
});

it('404s detaching a person who belongs to another company (scoped binding)', function () {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    $person = Person::factory()->create(['company_id' => $other->id]);

    $this->actingAs(companyRelationsAdmin())
        ->deleteJson(route('companies.people.destroy', [$company, $person]))
        ->assertNotFound();

    expect($person->fresh()->company_id)->toBe($other->id);
});

it('rejects attaching a person that does not exist', function () {
    $company = Company::factory()->create();

    $this->actingAs(companyRelationsAdmin())
        ->postJson(route('companies.people.store', $company), ['person_id' => 99999])
        ->assertStatus(422);
});

// ── Provided solutions ───────────────────────────────────────────────────

it('attaches a solution as provided by the company', function () {
    $company = Company::factory()->create();
    $solution = Solution::factory()->create(['vendor_company_id' => null, 'name' => 'SAP ECC']);

    $response = $this->actingAs(companyRelationsAdmin())
        ->postJson(route('companies.solutions.store', $company), ['solution_id' => $solution->id])
        ->assertOk();

    expect($solution->fresh()->vendor_company_id)->toBe($company->id);

    expect(collect($response->json('updatableSlots'))->pluck('id')->all())
        ->toEqual(['company-solutions-slot']);
    expect($response->json('updatableSlots.0.content'))->toContain('SAP ECC');
});

it('detaches a provided solution, leaving the solution itself alone', function () {
    $company = Company::factory()->create();
    $solution = Solution::factory()->create(['vendor_company_id' => $company->id]);

    $this->actingAs(companyRelationsAdmin())
        ->deleteJson(route('companies.solutions.destroy', [$company, $solution]))
        ->assertOk();

    expect($solution->fresh()->vendor_company_id)->toBeNull();
    $this->assertModelExists($solution);
});

it('404s detaching a solution provided by another company (scoped binding)', function () {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    $solution = Solution::factory()->create(['vendor_company_id' => $other->id]);

    $this->actingAs(companyRelationsAdmin())
        ->deleteJson(route('companies.solutions.destroy', [$company, $solution]))
        ->assertNotFound();

    expect($solution->fresh()->vendor_company_id)->toBe($other->id);
});

// ── Permissions ──────────────────────────────────────────────────────────

it('forbids a viewer from attaching or detaching either relation', function () {
    $company = Company::factory()->create();
    $person = Person::factory()->create(['company_id' => $company->id]);
    $solution = Solution::factory()->create(['vendor_company_id' => $company->id]);
    $viewer = User::factory()->create();

    $this->actingAs($viewer)
        ->postJson(route('companies.people.store', $company), ['person_id' => Person::factory()->create()->id])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->deleteJson(route('companies.people.destroy', [$company, $person]))
        ->assertForbidden();

    $this->actingAs($viewer)
        ->postJson(route('companies.solutions.store', $company), ['solution_id' => Solution::factory()->create()->id])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->deleteJson(route('companies.solutions.destroy', [$company, $solution]))
        ->assertForbidden();

    expect($person->fresh()->company_id)->toBe($company->id);
    expect($solution->fresh()->vendor_company_id)->toBe($company->id);
});

// ── What the cards render ────────────────────────────────────────────────

it('offers only unlinked records in both pickers, with their current owner in the label', function () {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create(['name' => 'Outra Empresa']);

    Person::factory()->create(['company_id' => $company->id, 'name' => 'Já aqui']);
    Person::factory()->create(['company_id' => $otherCompany->id, 'name' => 'Em outra']);
    Person::factory()->create(['company_id' => null, 'name' => 'Sem empresa']);

    Solution::factory()->create(['vendor_company_id' => $company->id, 'name' => 'Já fornecido']);
    Solution::factory()->create(['vendor_company_id' => null, 'name' => 'Sem fornecedor']);

    $response = $this->actingAs(companyRelationsAdmin())
        ->get(route('companies.show', $company))
        ->assertOk();

    // Already linked here → not in the picker (but still listed as a row).
    $response->assertDontSee('>Já aqui</option>', false);
    $response->assertDontSee('>Já fornecido</option>', false);
    // Free ones are offered as-is…
    $response->assertSee('>Sem empresa</option>', false);
    $response->assertSee('>Sem fornecedor</option>', false);
    // …and someone else's shows who they belong to, so the move isn't blind.
    $response->assertSee('>Em outra — Outra Empresa</option>', false);
});

it('gives each card its own slot id and detach action for an admin', function () {
    $company = Company::factory()->create();
    $person = Person::factory()->create(['company_id' => $company->id]);
    $solution = Solution::factory()->create(['vendor_company_id' => $company->id]);
    // Something left to add, otherwise the creators are (correctly) hidden.
    Person::factory()->create(['company_id' => null]);
    Solution::factory()->create(['vendor_company_id' => null]);

    $response = $this->actingAs(companyRelationsAdmin())
        ->get(route('companies.show', $company))
        ->assertOk();

    $response->assertSee('id="company-people-slot"', false);
    $response->assertSee('id="company-solutions-slot"', false);
    $response->assertSee(route('companies.people.destroy', [$company, $person]), false);
    $response->assertSee(route('companies.solutions.destroy', [$company, $solution]), false);
    $response->assertSeeText('Adicionar pessoa');
    $response->assertSeeText('Adicionar sistema');
});

it('hides a creator that has nothing left to offer', function () {
    $company = Company::factory()->create();
    // The only person and the only solution are already this company's.
    Person::factory()->create(['company_id' => $company->id]);
    Solution::factory()->create(['vendor_company_id' => $company->id]);

    $this->actingAs(companyRelationsAdmin())
        ->get(route('companies.show', $company))
        ->assertOk()
        ->assertDontSeeText('Adicionar pessoa')
        ->assertDontSeeText('Adicionar sistema');
});

it('shows a viewer the same lists with no way to change them', function () {
    $company = Company::factory()->create();
    Person::factory()->create(['company_id' => $company->id, 'name' => 'Alguém']);
    Solution::factory()->create(['vendor_company_id' => $company->id, 'name' => 'Algum sistema']);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('companies.show', $company))
        ->assertOk()
        ->assertSeeText('Alguém')
        ->assertSeeText('Algum sistema');

    $response->assertDontSeeText('Adicionar pessoa');
    $response->assertDontSeeText('Adicionar sistema');
    $response->assertDontSee('data-ak-inline-edit-field="person_id"', false);
    $response->assertDontSee('companies.people.destroy', false);
});
