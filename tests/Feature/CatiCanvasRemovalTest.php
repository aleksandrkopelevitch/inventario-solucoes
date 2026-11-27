<?php

use App\Enums\SubmissionDiagramKind;
use App\Models\SubmissionDiagram;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

uses(LazilyRefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The migration that turns the committee's canvas pictures into uploads
|--------------------------------------------------------------------------
|
| Exercised by calling it again on the migrated test database: the media rows
| are given the collections the canvas used to write.
*/

function canvasRemoval(): object
{
    return require database_path('migrations/2026_11_27_153112_move_submission_canvas_pictures_to_uploads.php');
}

/** A media row on `$diagram`, in a collection the model may no longer register. */
function canvasMedia(SubmissionDiagram $diagram, string $collection, string $name): Media
{
    return $diagram->addMedia(UploadedFile::fake()->image($name))->toMediaCollection($collection);
}

it('makes a canvas picture the slot upload, file and all', function () {
    Storage::fake('public');
    $diagram = SubmissionDiagram::factory()->kind(SubmissionDiagramKind::AsIs)->create();
    $png = canvasMedia($diagram, 'diagram', 'canvas.png');
    $path = $png->getPath();

    canvasRemoval()->up();

    expect($diagram->fresh()->picture()?->id)->toBe($png->id)
        ->and(is_file($path))->toBeTrue();
});

it('keeps an upload that was already there and drops the canvas picture beside it', function () {
    Storage::fake('public');
    $diagram = SubmissionDiagram::factory()->kind(SubmissionDiagramKind::ToBe)->create();
    $upload = canvasMedia($diagram, 'submission_diagram', 'escolhida.png');
    $png = canvasMedia($diagram, 'diagram', 'canvas.png');
    $pngPath = $png->getPath();

    canvasRemoval()->up();

    expect($diagram->fresh()->picture()?->id)->toBe($upload->id)
        ->and(Media::find($png->id))->toBeNull()
        ->and(is_file($pngPath))->toBeFalse();
});

it('deletes the pictures pasted onto a canvas', function () {
    Storage::fake('public');
    $diagram = SubmissionDiagram::factory()->kind(SubmissionDiagramKind::AsIs)->create();
    $pasted = canvasMedia($diagram, 'docs', 'colado.png');
    $path = $pasted->getPath();

    canvasRemoval()->up();

    expect(Media::find($pasted->id))->toBeNull()
        ->and(is_file($path))->toBeFalse()
        ->and($diagram->fresh()->picture())->toBeNull();
});
