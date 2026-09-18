<?php

use App\Enums\UserRole;
use App\Models\ApprovedTopology;
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

it('says out loud that an approved topology went with the solution', function () {
    // `approved_topologies.solution_id` is NOT NULL and cascades, so the row
    // cannot be kept — but a committee decision vanishing without a word is
    // how somebody later asks where it went.
    $solution = Solution::factory()->create(['name' => 'Fantasma']);
    $submission = Submission::factory()->create(['solution_id' => $solution->id]);
    ApprovedTopology::create([
        'submission_id' => $submission->id,
        'solution_id'   => $solution->id,
        'chain'         => ['nodes' => [], 'edges' => []],
        'approved_at'   => now(),
    ]);

    $this->actingAs(solutionAdmin())
        ->deleteJson(route('solutions.destroy', $solution))
        ->assertOk()
        ->assertJsonPath('message', 'Solução "Fantasma" excluída. 1 topologia(s) aprovada(s) para ela também foram removidas.');

    // The submission itself is a record of its own and survives (SET NULL).
    $this->assertModelExists($submission);
});

it('keeps a drawing whose only trace of the solution is a derived column', function () {
    // Both columns are ON DELETE CASCADE, so a diagram the scan misses is not
    // left stale — it is deleted outright by the database, silently. The chain
    // is null here on purpose: the derived column is the only finder that can
    // reach these.
    $solution = Solution::factory()->create();
    $byTarget = Diagram::factory()->create(['chain' => null, 'source_solution_id' => null, 'target_solution_id' => $solution->id]);
    $bySource = Diagram::factory()->create(['chain' => null, 'target_solution_id' => null, 'source_solution_id' => $solution->id]);

    $this->actingAs(solutionAdmin())->deleteJson(route('solutions.destroy', $solution))->assertOk();

    $this->assertModelExists($byTarget);
    $this->assertModelExists($bySource);
    expect($byTarget->fresh()->target_solution_id)->toBeNull()
        ->and($bySource->fresh()->source_solution_id)->toBeNull();
});

it('does not leave a dead system inside a topology another solution is waiting on', function () {
    // The third store of `{solution_id}` nodes. The topologies approved FOR the
    // deleted solution cascade away with it; one approved for ANOTHER solution
    // whose chain merely names it survives — and applying it later wrote the
    // dead id into `diagram_solution`, which is a foreign key violation the
    // admin met as a 500 long after the delete that caused it.
    $ghost = Solution::factory()->create(['name' => 'Sistema Fantasma']);
    $kept = Solution::factory()->create();
    $topology = ApprovedTopology::create([
        'submission_id' => Submission::factory()->create(['solution_id' => $kept->id])->id,
        'solution_id'   => $kept->id,
        'chain'         => ['nodes' => [
            ['solution_id' => $ghost->id, 'label' => null, 'kind' => 'system'],
            ['solution_id' => $kept->id, 'label' => null, 'kind' => 'system'],
        ], 'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]]],
        'approved_at'   => now(),
    ]);

    $this->actingAs(solutionAdmin())->deleteJson(route('solutions.destroy', $ghost))->assertOk();

    $chain = $topology->fresh()->chain;
    expect($chain['nodes'][0]['solution_id'])->toBeNull()
        ->and($chain['nodes'][0]['label'])->toBe('Sistema Fantasma');

    // And the handoff the committee approved still resolves.
    $diagram = app(\App\Actions\Cati\ApplyApprovedTopology::class)->handle($topology->fresh(), solutionAdmin());
    expect($diagram->participants()->pluck('solutions.id')->all())->toBe([$kept->id]);
});

it('says nothing about topologies when there were none', function () {
    $solution = Solution::factory()->create(['name' => 'Fantasma']);

    $this->actingAs(solutionAdmin())
        ->deleteJson(route('solutions.destroy', $solution))
        ->assertJsonPath('message', 'Solução "Fantasma" excluída.');
});

it('shows the delete control only to an admin', function () {
    $solution = Solution::factory()->create();

    $this->actingAs(solutionAdmin())->get(route('solutions.show', $solution))
        ->assertSee('solution-page-delete');

    $this->actingAs(User::factory()->create(['role' => UserRole::Writer->value]))
        ->get(route('solutions.show', $solution))
        ->assertDontSee('solution-page-delete');
});
