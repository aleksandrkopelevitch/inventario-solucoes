<?php

use App\Actions\Cati\CompareSubmissionTopologies;
use App\Enums\SubmissionDiagramKind;
use App\Enums\UserRole;
use App\Exceptions\TopologyCompareFailed;
use App\Models\Solution;
use App\Models\Submission;
use App\Models\SubmissionDiagram;
use App\Models\User;
use App\Support\Archify\ArchitectureIr;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    // See `.claude/rules/archify-artifacts.md`: this box's clock steps, and a
    // wall-clock process timeout reads that as a hung sidecar.
    config(['services.archify.timeout' => 300]);
});

function canvasFor(Submission $submission, SubmissionDiagramKind $kind, array $chain, array $layout = []): SubmissionDiagram
{
    return SubmissionDiagram::create([
        'submission_id' => $submission->id,
        'kind'          => $kind->value,
        'chain'         => $chain,
        'viz_layout'    => $layout,
    ]);
}

// ---------------------------------------------------------------------------
// Canvas → architecture spec. Pure, no database and no sidecar.
// ---------------------------------------------------------------------------

it('reads the drawing as a grid, left to right and top to bottom', function () {
    $spec = ArchitectureIr::fromCanvas(
        [
            'nodes' => [
                ['solution_id' => null, 'label' => 'A', 'kind' => 'system'],
                ['solution_id' => null, 'label' => 'B', 'kind' => 'system'],
                ['solution_id' => null, 'label' => 'C', 'kind' => 'system'],
            ],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => 'rest']],
        ],
        ['nodes' => [['x' => 500, 'y' => 20], ['x' => 100, 'y' => 20], ['x' => 100, 'y' => 400]]],
        collect(),
        'AS IS',
    );

    // Columns follow the x order and rows the y order — B is left of A, C is
    // below B. The pixels themselves are deliberately not carried over.
    expect(collect($spec['components'])->pluck('col', 'label')->all())->toBe(['A' => 1, 'B' => 0, 'C' => 0])
        ->and(collect($spec['components'])->pluck('row', 'label')->all())->toBe(['A' => 0, 'B' => 0, 'C' => 1])
        ->and($spec['layout'])->toBe(['mode' => 'grid', 'cols' => 2, 'gapX' => 120, 'gapY' => 80]);
});

it('never puts two blocks in one cell, whatever the canvas looked like', function () {
    // Two blocks left exactly on top of each other is an ordinary state of a
    // working canvas; Archify refuses overlapping components, so a comparison
    // that passed the pixels through would fail on somebody's drawing habits.
    $spec = ArchitectureIr::fromCanvas(
        [
            'nodes' => [
                ['solution_id' => null, 'label' => 'A', 'kind' => 'system'],
                ['solution_id' => null, 'label' => 'B', 'kind' => 'system'],
            ],
            'edges' => [],
        ],
        ['nodes' => [['x' => 10, 'y' => 10], ['x' => 10, 'y' => 10]]],
        collect(),
        'AS IS',
    );

    $cells = collect($spec['components'])->map(fn (array $c) => $c['row'] . ',' . $c['col']);

    expect($cells->unique())->toHaveCount(2);
});

it('places a block nobody ever dragged after the ones with a position', function () {
    $spec = ArchitectureIr::fromCanvas(
        [
            'nodes' => [
                ['solution_id' => null, 'label' => 'posicionado', 'kind' => 'system'],
                ['solution_id' => null, 'label' => 'novo', 'kind' => 'system'],
            ],
            'edges' => [],
        ],
        ['nodes' => [['x' => 10, 'y' => 10]]],
        collect(),
        'AS IS',
    );

    $byLabel = collect($spec['components'])->keyBy('label');

    expect($byLabel['novo']['col'])->toBeGreaterThan($byLabel['posicionado']['col']);
});

it('types a block by what its system is, and anything unregistered as external', function () {
    $bi = Solution::factory()->create(['name' => 'BigQuery', 'category' => 'data_bi']);

    $spec = ArchitectureIr::fromCanvas(
        [
            'nodes' => [
                ['solution_id' => $bi->id, 'label' => null, 'kind' => 'system'],
                ['solution_id' => null, 'label' => 'Parceiro', 'kind' => 'system'],
                ['solution_id' => null, 'label' => 'Analista', 'kind' => 'actor'],
            ],
            'edges' => [],
        ],
        [],
        collect([$bi->id => $bi]),
        'AS IS',
    );

    expect(collect($spec['components'])->pluck('type', 'label')->all())
        ->toBe(['BigQuery' => 'database', 'Parceiro' => 'external', 'Analista' => 'external']);
});

it('carries a bidirectional link as ONE connection', function () {
    // Archify has no two-headed variant, and two opposite connections would
    // read as two changes the day one of them moves.
    $spec = ArchitectureIr::fromCanvas(
        [
            'nodes' => [
                ['solution_id' => null, 'label' => 'A', 'kind' => 'system'],
                ['solution_id' => null, 'label' => 'B', 'kind' => 'system'],
            ],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '<->', 'protocol' => 'rest']],
        ],
        [],
        collect(),
        'AS IS',
    );

    expect($spec['connections'])->toHaveCount(1)
        ->and($spec['connections'][0]['label'])->toContain('↔');
});

// ---------------------------------------------------------------------------
// The real comparison.
// ---------------------------------------------------------------------------

it('reports what the proposal actually changes', function () {
    $submission = Submission::factory()->create();

    $asIs = [
        'nodes' => [
            ['solution_id' => null, 'label' => 'Portal', 'kind' => 'system'],
            ['solution_id' => null, 'label' => 'ERP', 'kind' => 'system'],
        ],
        'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => 'arquivo']],
    ];

    $toBe = [
        'nodes' => [
            ['solution_id' => null, 'label' => 'Portal', 'kind' => 'system'],
            ['solution_id' => null, 'label' => 'ERP', 'kind' => 'system'],
            ['solution_id' => null, 'label' => 'Barramento', 'kind' => 'system'],
        ],
        'edges' => [
            ['from' => 0, 'to' => 2, 'arrow' => '->', 'protocol' => 'rest'],
            ['from' => 2, 'to' => 1, 'arrow' => '->', 'protocol' => 'rest'],
        ],
    ];

    $layout = ['nodes' => [['x' => 0, 'y' => 0], ['x' => 300, 'y' => 0], ['x' => 150, 'y' => 200]]];

    canvasFor($submission, SubmissionDiagramKind::AsIs, $asIs, $layout);
    canvasFor($submission, SubmissionDiagramKind::ToBe, $toBe, $layout);

    ['path' => $path, 'receipt' => $receipt] = app(CompareSubmissionTopologies::class)->handle($submission);

    expect(is_file($path))->toBeTrue()
        ->and(file_get_contents($path))->toContain('Barramento')
        // Deterministic: the receipt is the machine-readable half, and it is
        // what the committee screen reads rather than the picture.
        ->and($receipt)->not->toBeEmpty();

    @unlink($path);
});

it('asks for the drawing before it asks for the diff', function () {
    $submission = Submission::factory()->create();
    canvasFor($submission, SubmissionDiagramKind::ToBe, [
        'nodes' => [
            ['solution_id' => null, 'label' => 'A', 'kind' => 'system'],
            ['solution_id' => null, 'label' => 'B', 'kind' => 'system'],
        ],
        'edges' => [],
    ]);

    // An empty AS IS is a legitimate state ("nada disso existe ainda") — just
    // not a comparable one.
    expect(fn () => app(CompareSubmissionTopologies::class)->handle($submission))
        ->toThrow(TopologyCompareFailed::class, 'AS IS');
});

// ---------------------------------------------------------------------------
// The endpoints.
// ---------------------------------------------------------------------------

it('stores the comparison on the submission and states what changed', function () {
    Storage::fake('public');

    $submission = Submission::factory()->create();

    canvasFor($submission, SubmissionDiagramKind::AsIs, [
        'nodes' => [
            ['solution_id' => null, 'label' => 'Portal', 'kind' => 'system'],
            ['solution_id' => null, 'label' => 'ERP', 'kind' => 'system'],
        ],
        'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => 'arquivo']],
    ], ['nodes' => [['x' => 0, 'y' => 0], ['x' => 200, 'y' => 0]]]);
    canvasFor($submission, SubmissionDiagramKind::ToBe, [
        'nodes' => [
            ['solution_id' => null, 'label' => 'Portal', 'kind' => 'system'],
            ['solution_id' => null, 'label' => 'ERP', 'kind' => 'system'],
            ['solution_id' => null, 'label' => 'Barramento', 'kind' => 'system'],
        ],
        'edges' => [
            ['from' => 0, 'to' => 2, 'arrow' => '->', 'protocol' => 'rest'],
            ['from' => 2, 'to' => 1, 'arrow' => '->', 'protocol' => 'rest'],
        ],
    ], ['nodes' => [['x' => 0, 'y' => 0], ['x' => 400, 'y' => 0], ['x' => 200, 'y' => 0]]]);

    $admin = User::factory()->create(['role' => UserRole::Admin->value]);

    $this->actingAs($admin)
        ->postJson(route('submissions.topology-delta.store', $submission))
        ->assertOk()
        ->assertJsonPath('updatableSlots.0.id', 'submission-diagrams-slot');

    $media = $submission->fresh()->getFirstMedia(Submission::TOPOLOGY_DELTA_COLLECTION);

    expect($media)->not->toBeNull()
        // The counts come off the receipt, so the committee screen states the
        // change without opening the artifact.
        ->and($media->getCustomProperty('counts')['components']['added'] ?? null)->toBe(1);
});

it('serves the comparison sandboxed, like every other artifact', function () {
    Storage::fake('public');

    $submission = Submission::factory()->create();
    $chain = [
        'nodes' => [
            ['solution_id' => null, 'label' => 'A', 'kind' => 'system'],
            ['solution_id' => null, 'label' => 'B', 'kind' => 'system'],
        ],
        'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => 'rest']],
    ];
    canvasFor($submission, SubmissionDiagramKind::AsIs, $chain);
    canvasFor($submission, SubmissionDiagramKind::ToBe, $chain);

    $admin = User::factory()->create(['role' => UserRole::Admin->value]);
    $this->actingAs($admin)->postJson(route('submissions.topology-delta.store', $submission));

    $response = $this->actingAs($admin)->get(route('submissions.topology-delta.show', $submission));

    $response->assertOk();
    expect($response->headers->get('Content-Security-Policy'))->toContain('sandbox allow-scripts');
});

it('404s the comparison a submission never generated', function () {
    $submission = Submission::factory()->create();

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->get(route('submissions.topology-delta.show', $submission))
        ->assertNotFound();
});

it('refuses the comparison to somebody who may read the submission but not write it', function () {
    $submission = Submission::factory()->create();
    $chain = [
        'nodes' => [
            ['solution_id' => null, 'label' => 'A', 'kind' => 'system'],
            ['solution_id' => null, 'label' => 'B', 'kind' => 'system'],
        ],
        'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => 'rest']],
    ];
    canvasFor($submission, SubmissionDiagramKind::AsIs, $chain);
    canvasFor($submission, SubmissionDiagramKind::ToBe, $chain);

    $this->actingAs(User::factory()->create(['role' => UserRole::Viewer->value]))
        ->postJson(route('submissions.topology-delta.store', $submission))
        ->assertForbidden();

    expect($submission->fresh()->getFirstMedia(Submission::TOPOLOGY_DELTA_COLLECTION))->toBeNull();
});

it('offers the comparison only once both canvases are drawn', function () {
    $submission = Submission::factory()->create();
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);

    // A canvas with ONE node is an untouched canvas — `open()` seeds that root
    // — so this does not count as drawn, which is the point of the assertion
    // below.
    canvasFor($submission, SubmissionDiagramKind::ToBe, [
        'nodes' => [['solution_id' => null, 'label' => 'A', 'kind' => 'system']],
        'edges' => [],
    ]);

    $this->actingAs($admin)
        ->get(route('submissions.show', $submission))
        ->assertOk()
        ->assertDontSee('Comparar os dois desenhos');

    SubmissionDiagram::where('submission_id', $submission->id)->update(['chain' => json_encode([
        'nodes' => [
            ['solution_id' => null, 'label' => 'A', 'kind' => 'system'],
            ['solution_id' => null, 'label' => 'B', 'kind' => 'system'],
        ],
        'edges' => [],
    ])]);

    $this->actingAs($admin)
        ->get(route('submissions.show', $submission))
        ->assertOk()
        ->assertSee('Comparar os dois desenhos');
});
