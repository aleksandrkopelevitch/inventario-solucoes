<?php

use App\Models\DocumentationPage;
use App\Models\Notebook;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

uses(LazilyRefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The migration that retires the topology drawings' media
|--------------------------------------------------------------------------
|
| It runs once per database, so it is exercised here by calling it again on
| a database the suite already migrated: what it reads (the pages, the media
| table) is still there.
*/

function topologyRemoval(): object
{
    return require database_path('migrations/2026_11_27_120019_delete_topology_diagram_media.php');
}

it('refuses to run while a page still cites a drawing, naming the page', function () {
    $notebook = Notebook::factory()->create(['slug' => 'vtex']);
    DocumentationPage::factory()->for($notebook)->create([
        'slug'          => 'clientes',
        'documentation' => "Antes.\n\n{% diagram slug=\"cadastro\" %}\n\nDepois.",
    ]);
    // Only a citation alone on its line was one; quoted in prose, it is text.
    DocumentationPage::factory()->for($notebook)->create([
        'slug'          => 'notas',
        'documentation' => 'Escrevia-se `{% diagram slug="x" %}` no meio da frase.',
    ]);

    expect(fn () => topologyRemoval()->up())
        ->toThrow(RuntimeException::class, 'vtex/clientes');

    try {
        topologyRemoval()->up();
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain('vtex/notas');
    }
});

it('deletes the drawings media with their files, and leaves every other media alone', function () {
    Storage::fake('public');
    $page = DocumentationPage::factory()->for(Notebook::factory())->create(['documentation' => 'Texto.']);
    $kept = $page->addMedia(UploadedFile::fake()->image('page.png'))->toMediaCollection('docs');
    $orphan = $page->addMedia(UploadedFile::fake()->image('canvas.png'))->toMediaCollection('diagram');

    // What a drawing's picture looked like: the class is gone, the morph name
    // is what the row still says.
    DB::table('media')->where('id', $orphan->id)->update(['model_type' => 'App\\Models\\Diagram', 'model_id' => 999]);
    $path = $orphan->getPath();

    expect(is_file($path))->toBeTrue();

    topologyRemoval()->up();

    expect(Media::find($orphan->id))->toBeNull()
        ->and(is_file($path))->toBeFalse()
        ->and(Media::find($kept->id))->not->toBeNull();
});
