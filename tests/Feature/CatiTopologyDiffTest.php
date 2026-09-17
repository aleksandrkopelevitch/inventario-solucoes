<?php

use App\Enums\SubmissionDiagramKind;
use App\Enums\UserRole;
use App\Models\Solution;
use App\Models\Submission;
use App\Models\SubmissionDiagram;
use App\Models\User;
use App\Support\ChainLabeler;
use App\Support\Diagrams\TopologyDiff;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function chainOf(array $labels, array $edges = []): array
{
    return [
        'nodes' => array_map(fn (mixed $label) => is_array($label)
            ? $label
            : ['solution_id' => null, 'label' => $label, 'kind' => 'system'], $labels),
        'edges' => array_map(fn (array $e) => $e + ['arrow' => '->', 'protocol' => null], $edges),
    ];
}

function diffOf(array $asIs, array $toBe): array
{
    return TopologyDiff::between($asIs, $toBe, (new ChainLabeler)->resolveSolutions(collect([$asIs, $toBe])));
}

function submissionWith(array $asIs, array $toBe): Submission
{
    $submission = Submission::factory()->create();

    foreach ([SubmissionDiagramKind::AsIs->value => $asIs, SubmissionDiagramKind::ToBe->value => $toBe] as $kind => $chain) {
        SubmissionDiagram::create(['submission_id' => $submission->id, 'kind' => $kind, 'chain' => $chain]);
    }

    return $submission;
}

it('reports what the proposal adds and what it drops', function () {
    $diff = diffOf(
        chainOf(['Portal', 'Planilha', 'ERP'], [['from' => 0, 'to' => 1], ['from' => 1, 'to' => 2]]),
        chainOf(['Portal', 'Barramento', 'ERP'], [['from' => 0, 'to' => 1], ['from' => 1, 'to' => 2]]),
    );

    expect($diff['blocks']['added'])->toBe(['Barramento'])
        ->and($diff['blocks']['removed'])->toBe(['Planilha'])
        ->and($diff['blocks']['kept'])->toBe(2)
        ->and($diff['changed'])->toBeTrue();
});

it('matches a block by its SOLUTION, whatever somebody typed over it', function () {
    $sap = Solution::factory()->create(['name' => 'SAP S/4HANA']);

    // The same system, relabelled on one of the two canvases. Matching on the
    // visible text would report it as removed and added at once.
    $diff = diffOf(
        chainOf([['solution_id' => $sap->id, 'label' => null, 'kind' => 'system']]),
        chainOf([['solution_id' => $sap->id, 'label' => 'ERP corporativo', 'kind' => 'system']]),
    );

    expect($diff['changed'])->toBeFalse()->and($diff['blocks']['kept'])->toBe(1);
});

it('matches free text by its folded label, so an accent is not a change', function () {
    $diff = diffOf(chainOf(['Integração']), chainOf(['integracao']));

    expect($diff['changed'])->toBeFalse();
});

it('does not use the chain index, which two separate drawings never share', function () {
    // `removeNode()` reindexes, so the same block sits at a different index in
    // two canvases authored apart. An index-based match would call everything
    // added and removed at the same time.
    $diff = diffOf(
        chainOf(['A', 'B', 'C']),
        chainOf(['C', 'B', 'A']),
    );

    expect($diff['changed'])->toBeFalse()->and($diff['blocks']['kept'])->toBe(3);
});

it('reads a link by the pair it joins, not by its direction', function () {
    // Turning an arrow around is a change to a link that already existed.
    // Reporting it as one removal plus one addition would bury the blocks that
    // really came and went.
    $diff = diffOf(
        chainOf(['A', 'B'], [['from' => 0, 'to' => 1]]),
        chainOf(['A', 'B'], [['from' => 1, 'to' => 0]]),
    );

    expect($diff['links']['added'])->toBeEmpty()
        ->and($diff['links']['removed'])->toBeEmpty()
        ->and($diff['links']['kept'])->toBe(1);
});

it('names a new link by both of its ends', function () {
    $diff = diffOf(
        chainOf(['Portal', 'ERP']),
        chainOf(['Portal', 'ERP'], [['from' => 0, 'to' => 1]]),
    );

    expect($diff['links']['added'])->toBe(['Portal → ERP']);
});

it('says so when the two drawings agree', function () {
    $diff = diffOf(chainOf(['A', 'B'], [['from' => 0, 'to' => 1]]), chainOf(['A', 'B'], [['from' => 0, 'to' => 1]]));

    expect($diff['changed'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// The panel.
// ---------------------------------------------------------------------------

it('shows the comparison on the committee tab, computed when it is read', function () {
    $submission = submissionWith(
        chainOf(['Portal', 'Planilha'], [['from' => 0, 'to' => 1]]),
        chainOf(['Portal', 'Barramento'], [['from' => 0, 'to' => 1]]),
    );

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->get(route('submissions.show', $submission))
        ->assertOk()
        ->assertSee('O que muda')
        ->assertSee('Barramento')
        ->assertSee('Planilha');
});

it('withholds the comparison until both canvases are drawn', function () {
    // One node is an untouched canvas: `SubmissionDiagram::open()` seeds a root
    // block. An empty AS IS is a legitimate state, just not a comparable one.
    $submission = submissionWith(chainOf(['A']), chainOf(['A', 'B']));

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->get(route('submissions.show', $submission))
        ->assertOk()
        ->assertDontSee('O que muda');
});
