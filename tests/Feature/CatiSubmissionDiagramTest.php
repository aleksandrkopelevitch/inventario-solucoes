<?php

use App\Actions\Cati\BuildDeckSpec;
use App\Actions\Cati\RenderTicketText;
use App\Enums\SubmissionDiagramKind;
use App\Enums\UserRole;
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

function uploadDiagram(Submission $submission, SubmissionDiagramKind $kind, string $name = 'diagrama.png')
{
    return test()->post(route('submissions.diagrams.upload.store', [$submission, $submission->diagram($kind)]), [
        'image' => UploadedFile::fake()->image($name),
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

it('accepts an uploaded picture on every slot, AS IS and TO BE included, and reports it everywhere it shows', function () {
    Storage::fake('public');
    $submission = diagramSubmission();

    foreach (SubmissionDiagramKind::cases() as $kind) {
        $response = uploadDiagram($submission, $kind)->assertOk();

        expect($submission->diagram($kind)->fresh()->isFilled())->toBeTrue()
            ->and(collect($response->json('updatableSlots'))->pluck('id')->all())
            ->toBe(['submission-diagrams-slot', 'submission-checklist-slot', 'submission-stage-strip-slot']);
    }
});

it('removes an uploaded picture', function () {
    Storage::fake('public');
    $submission = diagramSubmission();
    uploadDiagram($submission, SubmissionDiagramKind::ToBe)->assertOk();

    $this->deleteJson(route('submissions.diagrams.upload.destroy', [$submission, $submission->diagram(SubmissionDiagramKind::ToBe)]))
        ->assertOk();

    expect($submission->diagram(SubmissionDiagramKind::ToBe)->fresh()->isFilled())->toBeFalse();
});

it('refuses a slot that belongs to another submission', function () {
    Storage::fake('public');
    $mine = diagramSubmission();
    $theirs = diagramSubmission();

    $this->post(route('submissions.diagrams.upload.store', [$mine, $theirs->diagram(SubmissionDiagramKind::ToBe)]), [
        'image' => UploadedFile::fake()->image('invadido.png'),
    ])->assertNotFound();
});

it('lets a viewer see the pictures but not upload one', function () {
    Storage::fake('public');
    $submission = diagramSubmission();
    uploadDiagram($submission, SubmissionDiagramKind::AsIs)->assertOk();
    $diagram = $submission->diagram(SubmissionDiagramKind::AsIs);

    $this->actingAs(User::factory()->reader()->create());

    $this->get(route('submissions.diagrams.picture.show', [$submission, $diagram]))->assertOk();
    $this->post(route('submissions.diagrams.upload.store', [$submission, $diagram]), [
        'image' => UploadedFile::fake()->image('outra.png'),
    ])->assertForbidden();
});

it('refuses a file that is not a picture', function () {
    Storage::fake('public');
    $submission = diagramSubmission();

    $this->postJson(route('submissions.diagrams.upload.store', [$submission, $submission->diagram(SubmissionDiagramKind::AsIs)]), [
        'image' => UploadedFile::fake()->create('notas.pdf', 10, 'application/pdf'),
    ])->assertStatus(422);
});

it('serves a diagram picture behind the submission permission', function () {
    Storage::fake('public');

    $submission = diagramSubmission();
    $diagram = $submission->diagram(SubmissionDiagramKind::C4Container);

    $this->get(route('submissions.diagrams.picture.show', [$submission, $diagram]))->assertNotFound();

    uploadDiagram($submission, SubmissionDiagramKind::C4Container)->assertOk();

    $this->get(route('submissions.diagrams.picture.show', [$submission, $diagram]))->assertOk();
});

/*
|--------------------------------------------------------------------------
| What the pictures answer
|--------------------------------------------------------------------------
*/

it('ticks the committee diagram item only when all four slots are filled', function () {
    Storage::fake('public');

    $submission = diagramSubmission();
    $item = 'Diagramas de arquitetura anexados';

    expect(app(RenderTicketText::class)->handle($submission))
        ->toContain('* [ ] ' . $item);

    // The item is a claim about ATTACHMENTS, so it must not move until every
    // one of them is actually there.
    foreach ([SubmissionDiagramKind::AsIs, SubmissionDiagramKind::ToBe, SubmissionDiagramKind::C4Context] as $kind) {
        uploadDiagram($submission, $kind)->assertOk();
    }

    expect(app(RenderTicketText::class)->handle($submission->fresh()))
        ->toContain('* [ ] ' . $item);

    uploadDiagram($submission, SubmissionDiagramKind::C4Container)->assertOk();

    expect(app(RenderTicketText::class)->handle($submission->fresh()))
        ->toContain('* [x] ' . $item);
});

it('puts the submission pictures on the deck, in the committee order', function () {
    Storage::fake('public');

    $submission = diagramSubmission();

    foreach ([SubmissionDiagramKind::C4Context, SubmissionDiagramKind::ToBe, SubmissionDiagramKind::AsIs] as $kind) {
        uploadDiagram($submission, $kind)->assertOk();
    }

    $spec = app(BuildDeckSpec::class)->handle($submission->fresh());
    $diagramTitles = collect(SubmissionDiagramKind::cases())->map->slideTitle()->all();
    $slides = collect($spec['slides'])->filter(fn ($slide) => in_array($slide['title'], $diagramTitles, true))->values();

    expect($slides->pluck('title')->all())->toBe(['Arquitetura AS IS', 'Arquitetura TO BE', 'C4 · Contexto (C1)'])
        ->and($slides->every(fn ($slide) => $slide['blocks'][0]['type'] === 'image' && ! isset($slide['blocks'][0]['link'])))->toBeTrue();
});

it('renders the diagrams tab with four upload slots', function () {
    $html = $this->get(route('submissions.show', diagramSubmission()))->assertOk()->getContent();

    expect($html)
        ->toContain('id="submission-tab-diagrams"')
        ->toContain('submission-diagrams-slot')
        ->toContain('Arquitetura AS IS')
        ->toContain('C4 · Contêineres (C2)')
        ->and(substr_count($html, 'data-ak-cati-diagram-upload='))->toBe(4)
        ->and($html)->not->toContain('Desenhar');
});
