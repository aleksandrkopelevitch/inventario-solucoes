<?php

use App\Actions\Cati\BuildDeckSpec;
use App\Actions\Cati\RenderTicketText;
use App\Enums\SubmissionDiagramKind;
use App\Enums\UserRole;
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

function diagramSubmission(array $attributes = []): Submission
{
    return Submission::factory()->withSections()->create([
        'created_by_id' => test()->user->id,
        ...$attributes,
    ]);
}

/*
|--------------------------------------------------------------------------
| The four slots
|--------------------------------------------------------------------------
*/

it('opens the four slots on demand and never opens them twice', function () {
    $submission = diagramSubmission();

    expect($submission->diagrams()->count())->toBe(0);

    $submission->ensureDiagrams();
    $submission->ensureDiagrams();

    expect($submission->diagrams()->count())->toBe(count(SubmissionDiagramKind::cases()))
        ->and($submission->diagrams()->pluck('kind')->map->value->sort()->values()->all())
        ->toBe(['as_is', 'c4_container', 'c4_context', 'to_be']);
});

it('seeds a drawn canvas with the linked solution as its root', function () {
    // The proposal is about that system — making the person place it by hand
    // is asking for something already on the record.
    $solution = Solution::factory()->create(['name' => 'SKBridge']);
    $submission = diagramSubmission(['solution_id' => $solution->id]);

    $chain = $submission->diagram(SubmissionDiagramKind::ToBe)->chain;

    expect($chain['nodes'])->toHaveCount(1)
        ->and($chain['nodes'][0]['solution_id'])->toBe($solution->id)
        ->and($chain['edges'])->toBe([]);
});

it('seeds a drawn canvas with free text when there is no catalog solution', function () {
    // A brand-new system is most of what reaches this committee.
    $submission = diagramSubmission(['name' => 'CATI SKBridge', 'solution_id' => null]);

    $chain = $submission->diagram(SubmissionDiagramKind::AsIs)->chain;

    expect($chain['nodes'][0]['solution_id'])->toBeNull()
        ->and($chain['nodes'][0]['label'])->toBe('CATI SKBridge');
});

it('gives an uploaded kind no chain at all', function () {
    expect(diagramSubmission()->diagram(SubmissionDiagramKind::C4Context)->chain)->toBeNull();
});

it('counts a canvas as filled only once something beyond the seeded root exists', function () {
    // Opening the canvas is not drawing on it — otherwise the committee's
    // checklist would tick for every submission that ever clicked the tab.
    $submission = diagramSubmission();
    $diagram = $submission->diagram(SubmissionDiagramKind::ToBe);

    expect($diagram->isFilled())->toBeFalse();

    $chain = $diagram->chain;
    $chain['nodes'][] = ['solution_id' => null, 'label' => 'ERP', 'kind' => 'system'];
    $diagram->update(['chain' => $chain]);

    expect($diagram->fresh()->isFilled())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The chain endpoints — the same canvas, a different owner
|--------------------------------------------------------------------------
*/

it('runs the same chain semantics against a submission drawing', function () {
    $submission = diagramSubmission();
    $diagram = $submission->diagram(SubmissionDiagramKind::ToBe);

    $this->postJson(route('submissions.diagrams.chain.node.add', [$submission, $diagram]), [
        'kind' => 'system', 'label' => 'ERP',
    ])->assertOk()->assertJson(['type' => 'success', 'node' => ['label' => 'ERP']]);

    $this->postJson(route('submissions.diagrams.chain.edge.add', [$submission, $diagram]), [
        'from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => 'rest',
    ])->assertOk()->assertJson(['index' => 0]);

    expect($diagram->fresh()->chain['edges'])->toHaveCount(1);
});

it('reindexes a submission drawing on delete, exactly as the diagram canvas does', function () {
    // The one mutation that reindexes, against the second owner — if the
    // shared trait ever grows a Diagram-specific branch, this is what
    // catches it.
    $submission = diagramSubmission();
    $diagram = $submission->diagram(SubmissionDiagramKind::ToBe);
    $diagram->update([
        'chain' => [
            'nodes' => [
                ['solution_id' => null, 'label' => 'A', 'kind' => 'system'],
                ['solution_id' => null, 'label' => 'B', 'kind' => 'system'],
                ['solution_id' => null, 'label' => 'C', 'kind' => 'system'],
            ],
            'edges' => [
                ['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null],
                ['from' => 1, 'to' => 2, 'arrow' => '->', 'protocol' => null],
                ['from' => 0, 'to' => 2, 'arrow' => '->', 'protocol' => null],
            ],
        ],
        'viz_layout' => [
            'nodes'    => [['x' => 0, 'y' => 0], ['x' => 1, 'y' => 1], ['x' => 2, 'y' => 2]],
            'comments' => ['ca', 'cb', 'cc'],
            'edges'    => [['from' => 'r', 'to' => 'l'], ['from' => 't', 'to' => 'b'], ['from' => 'b', 'to' => 't']],
        ],
    ]);

    $response = $this->deleteJson(route('submissions.diagrams.chain.node.remove', [$submission, $diagram, 1]))->assertOk();

    $fresh = $diagram->fresh();

    expect($fresh->chain['nodes'])->toHaveCount(2)
        // Only the 0→2 edge survives, with `to` decremented.
        ->and($fresh->chain['edges'])->toHaveCount(1)
        ->and($fresh->chain['edges'][0])->toMatchArray(['from' => 0, 'to' => 1])
        // Positions and comments are per NODE index…
        ->and($fresh->viz_layout['comments'])->toBe(['ca', 'cc'])
        ->and($fresh->viz_layout['nodes'])->toHaveCount(2)
        // …and anchors are per EDGE index: only the third edge's survives.
        ->and($fresh->viz_layout['edges'])->toBe([['from' => 'b', 'to' => 't']])
        // A reindex answers with a whole rebuilt graph, never a patch.
        ->and($response->json('graph.nodes'))->toHaveCount(2);
});

it('never derives anything from a submission drawing', function () {
    // The whole reason a submission's chain is a different owner: a catalog
    // Diagram's chain writes participants/source/target/direction, and a
    // proposal — which may well be rejected — must never write into the
    // catalog. Two records on purpose: `$catalog` is what must not move,
    // `$drawing` is what gets drawn on.
    $solution = Solution::factory()->create();
    $catalog = Diagram::factory()->create();
    $catalog->participants()->attach($solution->id, ['position' => 0]);

    $submission = diagramSubmission(['solution_id' => $solution->id]);
    $drawing = $submission->diagram(SubmissionDiagramKind::ToBe);

    $before = [
        'participants' => $catalog->participants()->pluck('solutions.id')->all(),
        'source'       => $catalog->source_solution_id,
        'direction'    => $catalog->direction,
    ];

    $this->postJson(route('submissions.diagrams.chain.node.add', [$submission, $drawing]), [
        'kind' => 'system', 'solution_id' => $solution->id,
    ])->assertOk();

    $catalog->refresh();

    expect($catalog->participants()->pluck('solutions.id')->all())->toBe($before['participants'])
        ->and($catalog->source_solution_id)->toBe($before['source'])
        ->and($catalog->direction)->toBe($before['direction']);
});

it('refuses a drawing that belongs to another submission', function () {
    $mine = diagramSubmission();
    $theirs = diagramSubmission();

    $this->postJson(
        route('submissions.diagrams.chain.node.add', [$mine, $theirs->diagram(SubmissionDiagramKind::ToBe)]),
        ['kind' => 'system', 'label' => 'Invadido'],
    )->assertNotFound();
});

it('lets a viewer read the canvas but not draw on it', function () {
    $submission = diagramSubmission();
    $diagram = $submission->diagram(SubmissionDiagramKind::ToBe);

    $this->actingAs(User::factory()->create(['role' => UserRole::Viewer]));

    $this->get(route('submissions.diagrams.edit', [$submission, $diagram]))->assertOk();
    $this->postJson(route('submissions.diagrams.chain.node.add', [$submission, $diagram]), [
        'kind' => 'system', 'label' => 'ERP',
    ])->assertForbidden();
});

it('mounts the canvas with submission urls, so the client never learns whose chain it is', function () {
    $submission = diagramSubmission();
    $diagram = $submission->diagram(SubmissionDiagramKind::ToBe);

    $html = $this->get(route('submissions.diagrams.edit', [$submission, $diagram]))->assertOk()->getContent();

    expect($html)
        ->toContain('data-ak-chain-graph')
        ->toContain('data-ak-node-kinds')
        // The endpoints travel inside the payload — this is what makes the
        // 4.5k-line canvas owner-agnostic with no client change at all.
        ->toContain(str_replace('/', '\/', route('submissions.diagrams.chain.node.add', [$submission, $diagram])));
});

it('refuses to draw on a c4 slot', function () {
    // C4 is a notation the free-graph canvas does not speak; the slot takes an
    // upload instead, and there is no chain to mutate.
    $submission = diagramSubmission();

    $this->get(route('submissions.diagrams.edit', [$submission, $submission->diagram(SubmissionDiagramKind::C4Context)]))
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Pictures
|--------------------------------------------------------------------------
*/

it('accepts an uploaded c4 image and reports it everywhere it shows', function () {
    Storage::fake('public');

    $submission = diagramSubmission();
    $diagram = $submission->diagram(SubmissionDiagramKind::C4Context);

    $response = $this->post(route('submissions.diagrams.upload.store', [$submission, $diagram]), [
        'image' => UploadedFile::fake()->image('c4.png'),
    ])->assertOk();

    expect($diagram->fresh()->isFilled())->toBeTrue()
        ->and(collect($response->json('updatableSlots'))->pluck('id')->all())
        ->toBe(['submission-diagrams-slot', 'submission-checklist-slot', 'submission-stage-strip-slot']);
});

it('refuses an upload onto a drawn slot, and a canvas capture onto a c4 one', function () {
    Storage::fake('public');

    $submission = diagramSubmission();

    $this->post(route('submissions.diagrams.upload.store', [$submission, $submission->diagram(SubmissionDiagramKind::ToBe)]), [
        'image' => UploadedFile::fake()->image('nope.png'),
    ])->assertNotFound();

    $this->post(route('submissions.diagrams.picture.store', [$submission, $submission->diagram(SubmissionDiagramKind::C4Context)]), [
        'image' => UploadedFile::fake()->image('nope.png'),
    ])->assertNotFound();
});

it('serves a diagram picture behind the submission permission', function () {
    Storage::fake('public');

    $submission = diagramSubmission();
    $diagram = $submission->diagram(SubmissionDiagramKind::C4Container);

    $this->get(route('submissions.diagrams.picture.show', [$submission, $diagram]))->assertNotFound();

    $this->post(route('submissions.diagrams.upload.store', [$submission, $diagram]), [
        'image' => UploadedFile::fake()->image('c4.png'),
    ])->assertOk();

    $this->get(route('submissions.diagrams.picture.show', [$submission, $diagram]))->assertOk();
});

/*
|--------------------------------------------------------------------------
| What the drawings answer
|--------------------------------------------------------------------------
*/

it('ticks the committee diagram item only when all four slots are filled', function () {
    Storage::fake('public');

    $submission = diagramSubmission();
    $item = 'Diagramas de arquitetura anexados';

    expect(app(RenderTicketText::class)->handle($submission))
        ->toContain('* [ ] ' . $item);

    // Two drawn, two uploaded — the item is a claim about ATTACHMENTS, so it
    // must not move until every one of them is actually there.
    foreach (SubmissionDiagramKind::drawnCases() as $kind) {
        $diagram = $submission->diagram($kind);
        $chain = $diagram->chain;
        $chain['nodes'][] = ['solution_id' => null, 'label' => 'ERP', 'kind' => 'system'];
        $diagram->update(['chain' => $chain]);
    }

    expect(app(RenderTicketText::class)->handle($submission->fresh()))
        ->toContain('* [ ] ' . $item);

    foreach (SubmissionDiagramKind::uploadedCases() as $kind) {
        $this->post(route('submissions.diagrams.upload.store', [$submission, $submission->diagram($kind)]), [
            'image' => UploadedFile::fake()->image('c4.png'),
        ])->assertOk();
    }

    expect(app(RenderTicketText::class)->handle($submission->fresh()))
        ->toContain('* [x] ' . $item);
});

it('puts the submission drawings on the deck, in the committee order', function () {
    Storage::fake('public');

    $submission = diagramSubmission();

    foreach ([SubmissionDiagramKind::AsIs, SubmissionDiagramKind::ToBe] as $kind) {
        $diagram = $submission->diagram($kind);
        $chain = $diagram->chain;
        $chain['nodes'][] = ['solution_id' => null, 'label' => 'ERP', 'kind' => 'system'];
        $diagram->update(['chain' => $chain]);
        $this->post(route('submissions.diagrams.picture.store', [$submission, $diagram]), [
            'image' => UploadedFile::fake()->image('canvas.png'),
        ])->assertOk();
    }

    $this->post(route('submissions.diagrams.upload.store', [$submission, $submission->diagram(SubmissionDiagramKind::C4Context)]), [
        'image' => UploadedFile::fake()->image('c1.png'),
    ])->assertOk();

    $spec = app(BuildDeckSpec::class)->handle($submission->fresh());
    $diagramTitles = collect(SubmissionDiagramKind::cases())->map->slideTitle()->all();
    $titles = collect($spec['slides'])->pluck('title')->filter(fn ($t) => in_array($t, $diagramTitles, true))->values();

    expect($titles->all())->toBe(['Arquitetura AS IS', 'Arquitetura TO BE', 'C4 · Contexto (C1)'])
        // A drawn kind links back to its canvas; an uploaded one has nowhere
        // to go — the tool that made it isn't hosted here.
        ->and(collect($spec['slides'])->firstWhere('title', 'Arquitetura TO BE')['blocks'][0]['link'])
        ->toContain('/diagrams/')
        ->and(collect($spec['slides'])->firstWhere('title', 'C4 · Contexto (C1)')['blocks'][0]['link'])
        ->toBeNull();
});

it('stops printing the catalog diagram canvases once the submission drew its own AS IS', function () {
    // Two answers to "how does it work today" on consecutive slides is a
    // question from the committee, not an answer.
    Storage::fake('public');

    $solution = Solution::factory()->create();
    $diagram = Diagram::factory()->create(['name' => 'SKBridge ↔ ERP']);
    $diagram->participants()->attach($solution->id, ['position' => 0]);
    $diagram->addMedia(UploadedFile::fake()->image('diagram.png'))->toMediaCollection(Diagram::DIAGRAM_COLLECTION);

    $submission = diagramSubmission(['solution_id' => $solution->id]);

    // "Arquitetura de solução" is the SECTION slide — only the diagram ones
    // are in play here.
    $titles = fn () => collect(app(BuildDeckSpec::class)->handle($submission->fresh())['slides'])
        ->pluck('title')
        ->filter(fn ($t) => str_starts_with((string) $t, 'Arquitetura —') || $t === 'Arquitetura AS IS')
        ->values()->all();

    expect($titles())->toBe(['Arquitetura — SKBridge ↔ ERP']);

    $asIs = $submission->diagram(SubmissionDiagramKind::AsIs);
    $chain = $asIs->chain;
    $chain['nodes'][] = ['solution_id' => null, 'label' => 'ERP', 'kind' => 'system'];
    $asIs->update(['chain' => $chain]);
    $this->post(route('submissions.diagrams.picture.store', [$submission, $asIs]), [
        'image' => UploadedFile::fake()->image('canvas.png'),
    ])->assertOk();

    expect($titles())->toBe(['Arquitetura AS IS']);
});

it('renders the diagrams tab with all four slots', function () {
    $html = $this->get(route('submissions.show', diagramSubmission()))->assertOk()->getContent();

    expect($html)
        ->toContain('id="submission-tab-diagrams"')
        ->toContain('submission-diagrams-slot')
        ->toContain('Arquitetura AS IS')
        ->toContain('C4 · Contêineres (C2)');
});
