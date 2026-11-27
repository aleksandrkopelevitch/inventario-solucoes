<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Notebook;
use App\Models\Person;
use App\Models\Solution;
use App\Models\SolutionRelationship;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function solutionAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

it('lets an admin delete a solution', function () {
    $solution = Solution::factory()->create();

    $this->actingAs(solutionAdmin())
        ->deleteJson(route('solutions.destroy', $solution))
        ->assertOk()
        ->assertJsonPath('redirect', route('solutions.index'));

    $this->assertModelMissing($solution);
});

it('refuses to delete for a writer', function () {
    $solution = Solution::factory()->create();

    $this->actingAs(User::factory()->editor()->create())
        ->deleteJson(route('solutions.destroy', $solution))
        ->assertForbidden();

    $this->assertModelExists($solution);
});

it('keeps the people and the company the solution was linked to', function () {
    $company = Company::factory()->create();
    $person = Person::factory()->create();
    $notebook = Notebook::factory()->create();
    $solution = Solution::factory()->create(['vendor_company_id' => $company->id]);
    $solution->people()->attach($person, ['role' => 'owner', 'is_primary' => true]);
    $solution->notebooks()->attach($notebook);

    $this->actingAs(solutionAdmin())->deleteJson(route('solutions.destroy', $solution))->assertOk();

    $this->assertModelExists($company);
    $this->assertModelExists($person);
    $this->assertModelExists($notebook);
    expect($person->fresh()->solutions()->count())->toBe(0);
});

it('shows the delete control only to an admin', function () {
    $solution = Solution::factory()->create();

    $this->actingAs(solutionAdmin())->get(route('solutions.show', $solution))
        ->assertSee('solution-page-delete');

    $this->actingAs(User::factory()->editor()->create())
        ->get(route('solutions.show', $solution))
        ->assertDontSee('solution-page-delete');
});

it('takes the interconnections recorded with it, and nothing else', function () {
    $solution = Solution::factory()->create();
    $other = Solution::factory()->create();
    [$a, $b] = $solution->id < $other->id ? [$solution, $other] : [$other, $solution];
    $relationship = SolutionRelationship::query()->create([
        'solution_a_id' => $a->id,
        'solution_b_id' => $b->id,
        'direction'     => 'both',
    ]);

    $this->actingAs(solutionAdmin())->deleteJson(route('solutions.destroy', $solution))->assertOk();

    $this->assertModelMissing($relationship);
    $this->assertModelExists($other);
});

it('says only that the solution was deleted', function () {
    $solution = Solution::factory()->create(['name' => 'Portal']);

    $this->actingAs(solutionAdmin())
        ->deleteJson(route('solutions.destroy', $solution))
        ->assertOk()
        ->assertJsonPath('message', 'Solução "Portal" excluída.');
});

it('counts the interconnections a delete takes, in the confirm', function () {
    $solution = Solution::factory()->create(['name' => 'Portal']);
    foreach (Solution::factory()->count(2)->create() as $other) {
        [$a, $b] = $solution->id < $other->id ? [$solution, $other] : [$other, $solution];
        SolutionRelationship::query()->create(['solution_a_id' => $a->id, 'solution_b_id' => $b->id, 'direction' => 'both']);
    }

    $this->actingAs(solutionAdmin())->get(route('solutions.show', $solution))
        ->assertOk()
        ->assertSee('Excluir a solução &quot;Portal&quot;?', false)
        ->assertSee('As 2 interconexões cadastradas com ela são removidas.');
});
