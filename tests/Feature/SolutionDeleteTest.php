<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Notebook;
use App\Models\Person;
use App\Models\Solution;
use App\Models\SolutionRelationship;
use App\Models\Submission;
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

it('leaves a CATI submission drawing standing, with the block as free text', function () {
    // A submission's AS IS / TO BE stores `{solution_id, label}` per node with
    // no foreign key to cascade, so a delete that left it alone kept a node
    // pointing at a row that is gone, which `ChainLabeler` renders as "?".
    $solution = Solution::factory()->create(['name' => 'Sistema Fantasma']);
    $submission = Submission::factory()->create();
    $canvas = $submission->diagrams()->create([
        'kind'  => 'as_is',
        'chain' => ['nodes' => [['solution_id' => $solution->id, 'label' => null, 'kind' => 'system']], 'edges' => []],
    ]);

    $this->actingAs(solutionAdmin())->deleteJson(route('solutions.destroy', $solution))->assertOk();

    $chain = $canvas->fresh()->chain;

    expect($chain['nodes'][0]['solution_id'])->toBeNull()
        ->and($chain['nodes'][0]['label'])->toBe('Sistema Fantasma');
});

it('shows the delete control only to an admin', function () {
    $solution = Solution::factory()->create();

    $this->actingAs(solutionAdmin())->get(route('solutions.show', $solution))
        ->assertSee('solution-page-delete');

    $this->actingAs(User::factory()->editor()->create())
        ->get(route('solutions.show', $solution))
        ->assertDontSee('solution-page-delete');
});

it('names a block whose label was blank rather than leaving it nameless', function () {
    // `?? ` only substitutes on null, so a node stored with an empty string
    // kept it and rendered as an unnamed rectangle — the outcome the sweep
    // exists to avoid.
    $solution = Solution::factory()->create(['name' => 'Sem Rotulo']);
    $canvas = Submission::factory()->create()->diagrams()->create([
        'kind'  => 'as_is',
        'chain' => ['nodes' => [['solution_id' => $solution->id, 'label' => '', 'kind' => 'system']], 'edges' => []],
    ]);

    $this->actingAs(solutionAdmin())->deleteJson(route('solutions.destroy', $solution))->assertOk();

    expect($canvas->fresh()->chain['nodes'][0]['label'])->toBe('Sem Rotulo');
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
