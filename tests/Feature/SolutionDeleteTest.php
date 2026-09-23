<?php

use App\Actions\Cati\ApplyApprovedTopology;
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
        // block is still a solution, so it becomes the source. Asserted by
        // NAME, not by `not->toBe($solution->id)` — `null` satisfies that too,
        // so the weaker form passed whether the column was re-derived or
        // wiped, which is the one distinction this line exists to make.
        ->and($diagram->fresh()->source_solution_id)->toBe($other->id);
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

it('cleans an approved TO BE snapshot, so applying it later still works', function () {
    // `approved_topologies.chain` is the THIRD table storing the same node
    // shape, and the one the sweep missed. Its own foreign key only covers the
    // solution the submission was ABOUT, so a solution that merely appears
    // inside the approved chain survived the delete as a dangling id — and
    // `ApplyApprovedTopology` then died on it, because `SyncDiagramFromChain`
    // attaches the participants it reads from the chain.
    $subject = Solution::factory()->create(['name' => 'Assunto']);
    $mentioned = Solution::factory()->create(['name' => 'Sistema Citado']);

    $topology = ApprovedTopology::factory()->create([
        'solution_id' => $subject->id,
        'chain'       => ['nodes' => [
            ['solution_id' => $subject->id, 'label' => 'Assunto', 'kind' => 'system'],
            ['solution_id' => $mentioned->id, 'label' => 'Sistema Citado', 'kind' => 'system'],
        ], 'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => 'rest']]],
    ]);

    $this->actingAs(solutionAdmin())->deleteJson(route('solutions.destroy', $mentioned))->assertOk();

    $chain = $topology->fresh()->chain;

    expect($chain['nodes'][1]['solution_id'])->toBeNull()
        ->and($chain['nodes'][1]['label'])->toBe('Sistema Citado');

    // The point of the sweep: the snapshot is still appliable.
    $diagram = app(ApplyApprovedTopology::class)->handle($topology->fresh(), solutionAdmin());

    expect($diagram->participants->pluck('id')->all())->toBe([$subject->id]);
});

it('names a block whose label was blank rather than leaving it nameless', function () {
    // `?? ` only substitutes on null, so a node stored with an empty string
    // kept it and rendered as an unnamed rectangle — the outcome the sweep
    // exists to avoid.
    $solution = Solution::factory()->create(['name' => 'Sem Rotulo']);
    $diagram = Diagram::factory()->create([
        'chain' => ['nodes' => [['solution_id' => $solution->id, 'label' => '', 'kind' => 'system']], 'edges' => []],
    ]);

    $this->actingAs(solutionAdmin())->deleteJson(route('solutions.destroy', $solution))->assertOk();

    expect($diagram->fresh()->chain['nodes'][0]['label'])->toBe('Sem Rotulo');
});
