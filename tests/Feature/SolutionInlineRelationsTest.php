<?php

use App\Enums\PersonSolutionRole;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Person;
use App\Models\Solution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function solutionRelationsAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

it('links a person as an owner, with a role, from the solution header', function () {
    $solution = Solution::factory()->create();
    $person = Person::factory()->create(['name' => 'Ana Silva']);

    $response = $this->actingAs(solutionRelationsAdmin())
        ->postJson(route('solutions.people.store', $solution), [
            'person_id' => $person->id,
            'role'      => PersonSolutionRole::Business->value,
        ])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($solution->people()->sole()->pivot->role)->toBe(PersonSolutionRole::Business->value);

    // The owners grid lives in the header, so that's the slot that comes back.
    expect(collect($response->json('updatableSlots'))->pluck('id')->all())
        ->toEqual(['solution-detail-header-slot']);
    expect($response->json('updatableSlots.0.content'))->toContain('Ana Silva');
});

it('refuses to link the same person twice, even under a different role', function () {
    $solution = Solution::factory()->create();
    $person = Person::factory()->create();
    $solution->people()->attach($person, ['role' => PersonSolutionRole::Technical->value]);

    // The pivot's own unique index is (person, solution, role), so only the
    // request rule stands between this and a duplicated link.
    $response = $this->actingAs(solutionRelationsAdmin())
        ->postJson(route('solutions.people.store', $solution), [
            'person_id' => $person->id,
            'role'      => PersonSolutionRole::Business->value,
        ])
        ->assertStatus(422);

    expect($response->json('message'))->toContain('já está vinculada');
    expect($solution->people()->count())->toBe(1);
});

it('rejects a role that is not in the enum, or a person that does not exist', function () {
    $solution = Solution::factory()->create();
    $person = Person::factory()->create();

    $this->actingAs(solutionRelationsAdmin())
        ->postJson(route('solutions.people.store', $solution), ['person_id' => $person->id, 'role' => 'chefe-supremo'])
        ->assertStatus(422);

    $this->actingAs(solutionRelationsAdmin())
        ->postJson(route('solutions.people.store', $solution), ['person_id' => 99999, 'role' => PersonSolutionRole::Technical->value])
        ->assertStatus(422);

    expect($solution->people()->count())->toBe(0);
});

it('unlinks an owner, leaving the person record alone', function () {
    $solution = Solution::factory()->create();
    $person = Person::factory()->create();
    $solution->people()->attach($person, ['role' => PersonSolutionRole::Technical->value]);

    $this->actingAs(solutionRelationsAdmin())
        ->deleteJson(route('solutions.people.destroy', [$solution, $person]))
        ->assertOk();

    expect($solution->people()->count())->toBe(0);
    $this->assertModelExists($person);
});

it('shows a linked owner in the column of their role, and moves them when re-roled elsewhere', function () {
    $solution = Solution::factory()->create();
    $person = Person::factory()->create(['name' => 'Ana Silva']);
    $solution->people()->attach($person, ['role' => PersonSolutionRole::VendorContact->value]);

    $response = $this->actingAs(solutionRelationsAdmin())
        ->get(route('solutions.show', $solution))
        ->assertOk();

    // The role decides the column, so the name must sit after that heading.
    $html = $response->getContent();
    expect(strpos($html, 'Contato do fornecedor'))->toBeLessThan(strpos($html, 'Ana Silva'));

    // Re-roling stays on the person's page — and the solution header follows.
    $this->actingAs(solutionRelationsAdmin())
        ->patchJson(route('people.solutions.update', [$person, $solution]), ['role' => PersonSolutionRole::Technical->value])
        ->assertOk();

    expect($solution->people()->sole()->pivot->role)->toBe(PersonSolutionRole::Technical->value);
});

it('forbids a viewer from linking or unlinking an owner', function () {
    $solution = Solution::factory()->create();
    $person = Person::factory()->create();
    $solution->people()->attach($person, ['role' => PersonSolutionRole::Technical->value]);
    $viewer = User::factory()->create();

    $this->actingAs($viewer)
        ->postJson(route('solutions.people.store', $solution), [
            'person_id' => Person::factory()->create()->id,
            'role'      => PersonSolutionRole::Technical->value,
        ])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->deleteJson(route('solutions.people.destroy', [$solution, $person]))
        ->assertForbidden();

    expect($solution->people()->count())->toBe(1);
});

it('offers only unlinked people in the owners picker, with their company in the label', function () {
    $company = Company::factory()->create(['name' => 'Leo Madeiras']);
    $solution = Solution::factory()->create();
    $linked = Person::factory()->create(['name' => 'Já vinculada']);
    Person::factory()->for($company)->create(['name' => 'Ainda livre']);
    $solution->people()->attach($linked, ['role' => PersonSolutionRole::Technical->value]);

    $response = $this->actingAs(solutionRelationsAdmin())
        ->get(route('solutions.show', $solution))
        ->assertOk();

    $response->assertDontSee('>Já vinculada</option>', false);
    $response->assertSee('>Ainda livre — Leo Madeiras</option>', false);
    $response->assertSeeText('Vincular pessoa');
    $response->assertSee(route('solutions.people.destroy', [$solution, $linked]), false);
});

it('leaves the owners grid read-only for a viewer', function () {
    $solution = Solution::factory()->create();
    $person = Person::factory()->create(['name' => 'Alguém']);
    Person::factory()->create(); // someone left to link, were it allowed
    $solution->people()->attach($person, ['role' => PersonSolutionRole::Technical->value]);

    $response = $this->actingAs(User::factory()->create())
        ->get(route('solutions.show', $solution))
        ->assertOk()
        ->assertSeeText('Alguém');

    $response->assertDontSeeText('Vincular pessoa');
    $response->assertDontSee('data-ak-inline-edit-field="person_id"', false);
    $response->assertDontSee('solutions/' . $solution->slug . '/people', false);
});
