<?php

use App\Actions\WriteNotebookDiagram;
use App\Contracts\Documentable;
use App\Models\Diagram;
use App\Models\Notebook;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

it('drops the pictures an overwritten drawing no longer references, and keeps the rest', function () {
    Storage::fake('public');
    $notebook = Notebook::factory()->create();
    $diagram = Diagram::factory()->create(['notebook_id' => $notebook->id]);

    $kept = $diagram->addMediaFromString('a')->usingFileName('a.png')->toMediaCollection(Documentable::DOCS_COLLECTION);
    $orphan = $diagram->addMediaFromString('b')->usingFileName('b.png')->toMediaCollection(Documentable::DOCS_COLLECTION);

    app(WriteNotebookDiagram::class)->handle($notebook, [
        'nodes' => [['solution_id' => null, 'label' => 'Foto', 'kind' => 'image', 'media_id' => $kept->id]],
        'edges' => [],
    ], target: $diagram);

    $this->assertModelExists($kept);
    $this->assertModelMissing($orphan);
});
