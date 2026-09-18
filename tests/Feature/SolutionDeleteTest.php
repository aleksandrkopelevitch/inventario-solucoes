<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Diagram;
use App\Models\Notebook;
use App\Models\Person;
use App\Models\Solution;
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

    $this->actingAs(User::factory()->create(['role' => UserRole::Writer->value]))
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

it('leaves the drawing standing, with the block as free text', function () {
    $solution = Solution::factory()->create(['name' => 'Sistema Fantasma']);
    $other = Solution::factory()->create();
    $diagram = Diagram::factory()->create([
        'chain' => [
            'nodes' => [
                ['solution_id' => $solution->id, 'label' => null, 'kind' => 'system'],
                ['solution_id' => $other->id, 'label' => null, 'kind' => 'system'],
            ],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    $diagram->afterChainMutation();

    expect($diagram->fresh()->source_solution_id)->toBe($solution->id);

    $this->actingAs(solutionAdmin())->deleteJson(route('solutions.destroy', $solution))->assertOk();

    // The cascade on `source_solution_id` would have taken the whole drawing.
    $this->assertModelExists($diagram);

    $chain = $diagram->fresh()->chain;
    expect($chain['nodes'][0]['solution_id'])->toBeNull()
        ->and($chain['nodes'][0]['label'])->toBe('Sistema Fantasma')
        ->and($chain['edges'])->toHaveCount(1)
        // Re-derived from what is LEFT in the chain, not blanked: the other
        // block is still a solution, so it becomes the source.
        ->and($diagram->fresh()->source_solution_id)->not->toBe($solution->id);
});

it('cleans a CATI submission drawing the same way it cleans a catalog one', function () {
    // A submission's AS IS / TO BE is the SAME canvas with a different owner
    // (both implement `ChainCanvas`), storing the same `{solution_id, label}`
    // node — and it has no foreign key to cascade, so a delete that only swept
    // `diagrams` left a node pointing at a row that is gone, which
    // `ChainLabeler` renders as "?".
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

    $this->actingAs(User::factory()->create(['role' => UserRole::Writer->value]))
        ->get(route('solutions.show', $solution))
        ->assertDontSee('solution-page-delete');
});
