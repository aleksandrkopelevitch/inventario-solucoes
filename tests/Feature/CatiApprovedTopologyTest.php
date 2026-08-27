<?php

use App\Actions\Cati\ApplyApprovedTopology;
use App\Contracts\Documentable;
use App\Enums\SubmissionDiagramKind;
use App\Enums\SubmissionSectionKey;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\ApprovedTopology;
use App\Models\Diagram;
use App\Models\Solution;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($this->user);
});

/** A submission with a solution and a TO BE that has actually been drawn on. */
function approvableSubmission(array $attributes = []): Submission
{
    $solution = Solution::factory()->create(['name' => 'SKBridge']);

    $submission = Submission::factory()->withSections()->create([
        'created_by_id' => test()->user->id,
        'solution_id'   => $solution->id,
        ...$attributes,
    ]);

    $submission->section(SubmissionSectionKey::Summary)
        ->update(['content' => 'Ponto único de conexão.']);

    $diagram = $submission->diagram(SubmissionDiagramKind::ToBe);
    $diagram->update([
        'chain' => [
            'nodes' => [
                ['solution_id' => $solution->id, 'label' => null, 'kind' => 'system'],
                ['solution_id' => null, 'label' => 'ERP', 'kind' => 'system'],
            ],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => 'rest']],
        ],
        'viz_layout' => ['nodes' => [['x' => 0, 'y' => 0], ['x' => 320, 'y' => 0]]],
    ]);

    return $submission->fresh();
}

function approve(Submission $submission): void
{
    test()->postJson(route('submissions.decision.store', $submission), [
        'status'          => SubmissionStatus::Approved->value,
        'decision'        => 'Aprovada.',
        'conditions_text' => '',
    ])->assertOk();
}

/*
|--------------------------------------------------------------------------
| Recording what was approved
|--------------------------------------------------------------------------
*/

it('records the approved TO BE as a pending topology instead of writing the catalog', function () {
    // Fase 3 gave a submission its own drawings, whose afterChainMutation() is
    // deliberately empty — so an approval reached nothing and the catalog
    // drifted. This is the record that closes that, WITHOUT guessing a target.
    $submission = approvableSubmission();

    approve($submission);

    $topology = $submission->fresh()->approvedTopology;

    expect($topology)->not->toBeNull()
        ->and($topology->isPending())->toBeTrue()
        ->and($topology->solution_id)->toBe($submission->solution_id)
        ->and($topology->nodeCount())->toBe(2)
        // Nothing was written to any diagram.
        ->and(Diagram::count())->toBe(0);
});

it('snapshots the chain, so later edits to the drawing cannot change what was approved', function () {
    $submission = approvableSubmission();
    approve($submission);

    $diagram = $submission->diagram(SubmissionDiagramKind::ToBe);
    $chain = $diagram->chain;
    $chain['nodes'][] = ['solution_id' => null, 'label' => 'Depois da reunião', 'kind' => 'system'];
    $diagram->update(['chain' => $chain]);

    // A pending change that quietly became a different drawing is worse than
    // no record at all.
    expect($submission->fresh()->approvedTopology->nodeCount())->toBe(2);
});

it('records nothing when the TO BE was never drawn', function () {
    $solution = Solution::factory()->create();
    $submission = Submission::factory()->withSections()->create([
        'created_by_id' => $this->user->id,
        'solution_id'   => $solution->id,
    ]);
    $submission->section(SubmissionSectionKey::Summary)->update(['content' => 'Texto.']);

    approve($submission);

    expect($submission->fresh()->approvedTopology)->toBeNull();
});

it('never resurrects a topology somebody already resolved', function () {
    $submission = approvableSubmission();
    approve($submission);

    $topology = $submission->fresh()->approvedTopology;
    app(ApplyApprovedTopology::class)->dismiss($topology, $this->user, 'Já estava certo.');

    // Re-approving (an edited deliberation) must not reopen a closed handoff.
    approve($submission->fresh());

    expect($submission->fresh()->approvedTopology->isPending())->toBeFalse();
});

it('puts the approved drawing in the promoted documentation page, once', function () {
    Storage::fake('public');

    $submission = approvableSubmission();
    $diagram = $submission->diagram(SubmissionDiagramKind::ToBe);
    $this->post(route('submissions.diagrams.picture.store', [$submission, $diagram]), [
        'image' => UploadedFile::fake()->image('canvas.png'),
    ])->assertOk();

    approve($submission->fresh());
    $page = $submission->solution->notebooks()->first()->pages()->first();

    expect($page->documentation)->toContain('/files/')
        ->toContain('Arquitetura aprovada (TO BE)')
        ->and($page->getMedia(Documentable::DOCS_COLLECTION))->toHaveCount(1);

    // Re-promotion reuses its own copy rather than stacking a second one.
    approve($submission->fresh());

    expect($page->fresh()->getMedia(Documentable::DOCS_COLLECTION))->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Applying it
|--------------------------------------------------------------------------
*/

it('applies the approved topology to a new diagram and re-derives its columns', function () {
    // The whole reason this goes through ChainCanvas::writeChain() +
    // afterChainMutation(): assigning `chain` directly would be the one place
    // in the app where topology moves without participants/source/target
    // following it.
    $submission = approvableSubmission();
    approve($submission);
    $topology = $submission->fresh()->approvedTopology;

    $this->postJson(route('submissions.topology.apply', [$submission, $topology]), [
        'diagram_id' => null,
    ])->assertOk();

    $topology->refresh();
    $diagram = $topology->diagram;

    expect($topology->isPending())->toBeFalse()
        ->and($topology->applied_by_id)->toBe($this->user->id)
        ->and($diagram)->not->toBeNull()
        ->and($diagram->chain['nodes'])->toHaveCount(2)
        // Derived, not copied: the sync ran.
        ->and($diagram->participants()->pluck('solutions.id')->all())->toContain($submission->solution_id)
        ->and($diagram->source_solution_id)->toBe($submission->solution_id)
        ->and($diagram->protocol)->toBe('rest')
        // The layout travels with it, or the canvas opens as a pile at origin.
        ->and($diagram->viz_layout['nodes'])->toHaveCount(2);
});

it('applies onto an existing diagram of the same solution', function () {
    $submission = approvableSubmission();
    approve($submission);
    $topology = $submission->fresh()->approvedTopology;

    $diagram = Diagram::factory()->create(['name' => 'Antigo']);
    $diagram->participants()->attach($submission->solution_id, ['position' => 0]);

    $this->postJson(route('submissions.topology.apply', [$submission, $topology]), [
        'diagram_id' => $diagram->id,
    ])->assertOk();

    expect($diagram->fresh()->chain['nodes'])->toHaveCount(2)
        ->and($topology->fresh()->diagram_id)->toBe($diagram->id);
});

it('refuses to apply onto a diagram belonging to another solution', function () {
    // An approval on one solution silently overwriting another's topology is a
    // write nobody would ever look for.
    $submission = approvableSubmission();
    approve($submission);
    $topology = $submission->fresh()->approvedTopology;

    $foreign = Diagram::factory()->create(['name' => 'De outra solução']);
    $foreign->participants()->attach(Solution::factory()->create()->id, ['position' => 0]);
    $before = $foreign->chain;

    $response = $this->postJson(route('submissions.topology.apply', [$submission, $topology]), [
        'diagram_id' => $foreign->id,
    ])->assertStatus(422)->assertJson(['type' => 'warning']);

    expect($response->json('message'))->toContain('não é da solução')
        ->and($foreign->fresh()->chain)->toBe($before)
        ->and($topology->fresh()->isPending())->toBeTrue();
});

it('records a dismissal as a different outcome from an application', function () {
    // "The catalog was already right" and "the catalog now says this" are not
    // the same claim, and the history has to tell them apart.
    $submission = approvableSubmission();
    approve($submission);
    $topology = $submission->fresh()->approvedTopology;

    $this->postJson(route('submissions.topology.dismiss', [$submission, $topology]), [
        'reason' => 'O desenho já era o que está no catálogo.',
    ])->assertOk();

    $topology->refresh();

    expect($topology->isPending())->toBeFalse()
        ->and($topology->applied_at)->toBeNull()
        ->and($topology->dismissed_at)->not->toBeNull()
        ->and($topology->dismissed_reason)->toContain('já era')
        ->and(Diagram::count())->toBe(0);
});

it('refuses to resolve the same handoff twice', function () {
    $submission = approvableSubmission();
    approve($submission);
    $topology = $submission->fresh()->approvedTopology;

    $this->postJson(route('submissions.topology.dismiss', [$submission, $topology]))->assertOk();
    $this->postJson(route('submissions.topology.apply', [$submission, $topology]), ['diagram_id' => null])
        ->assertStatus(409);
});

it('refuses a topology reached through the wrong submission', function () {
    $mine = approvableSubmission();
    $theirs = approvableSubmission();
    approve($theirs);

    $this->postJson(route('submissions.topology.apply', [$mine, $theirs->fresh()->approvedTopology]), [
        'diagram_id' => null,
    ])->assertNotFound();
});

it('lets a viewer see the handoff but not resolve it', function () {
    $submission = approvableSubmission();
    approve($submission);
    $topology = $submission->fresh()->approvedTopology;

    $this->actingAs(User::factory()->create(['role' => UserRole::Viewer]));

    $this->postJson(route('submissions.topology.apply', [$submission, $topology]), ['diagram_id' => null])
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Being visible
|--------------------------------------------------------------------------
*/

it('warns on the solution page that its diagrams are showing the old scenario', function () {
    // Drift that is invisible is the failure this module exists to prevent.
    $submission = approvableSubmission();
    approve($submission);

    $html = $this->get(route('solutions.show', $submission->solution))->assertOk()->getContent();

    expect($html)->toContain('ainda não foi aplicada');

    app(ApplyApprovedTopology::class)->handle($submission->fresh()->approvedTopology, $this->user);

    expect($this->get(route('solutions.show', $submission->solution))->getContent())
        ->not->toContain('ainda não foi aplicada');
});

it('shows the handoff card on the submission and returns its slot on approval', function () {
    $submission = approvableSubmission();

    $response = $this->postJson(route('submissions.decision.store', $submission), [
        'status'          => SubmissionStatus::Approved->value,
        'decision'        => 'Aprovada.',
        'conditions_text' => '',
    ])->assertOk();

    expect(collect($response->json('updatableSlots'))->pluck('id')->all())
        ->toContain('submission-topology-handoff-slot')
        ->and($this->get(route('submissions.show', $submission))->getContent())
        ->toContain('O catálogo ainda não reflete o TO BE');
});

it('renders nothing at all when there is no handoff to make', function () {
    $submission = approvableSubmission();

    $html = $this->get(route('submissions.show', $submission))->assertOk()->getContent();

    // A card that is permanently present and permanently empty teaches people
    // to stop reading that column.
    expect($html)->toContain('submission-topology-handoff-slot')
        ->not->toContain('O catálogo ainda não reflete');
});

it('leaves an applied record readable after its diagram is deleted', function () {
    $submission = approvableSubmission();
    approve($submission);
    $topology = $submission->fresh()->approvedTopology;

    $diagram = app(ApplyApprovedTopology::class)->handle($topology, $this->user);
    $diagram->delete();

    // nullOnDelete, not cascade: the history of having applied it survives the
    // thing it was applied to.
    expect(ApprovedTopology::find($topology->id))->not->toBeNull()
        ->and(ApprovedTopology::find($topology->id)->applied_at)->not->toBeNull()
        ->and(ApprovedTopology::find($topology->id)->diagram_id)->toBeNull();
});
