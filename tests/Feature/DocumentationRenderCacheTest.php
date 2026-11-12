<?php

use App\Models\Diagram;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Support\Documentation\PageLinks;
use App\Support\GitbookRenderer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The parse of a large page is cached, and nothing it prints goes stale
|--------------------------------------------------------------------------
|
| `GitbookRenderer::parsed()` caches commonmark's output for a page past a
| few KB. What the parse reads besides the text — a cited diagram's name and
| picture, a file's name and size, the URL root — is in the key, and a page
| with a card grid (resolved per reader inside the parse) is never cached.
*/

/** A page long enough to be cached, citing `$extra`. */
function longMarkdown(string $extra): string
{
    return "# Página longa\n\n" . str_repeat("Um parágrafo de enchimento para passar do limite do cache.\n\n", 120) . $extra . "\n";
}

it('serves a large page from the cache instead of parsing it again', function () {
    $markdown = longMarkdown('Fim.');
    $key = 'gitbook-render:v1:' . md5(implode("\0", [$markdown, '0', url('/'), '']));

    expect(cache()->has($key))->toBeFalse();

    $first = app(GitbookRenderer::class)->render($markdown, linkDiagrams: false);

    expect(cache()->has($key))->toBeTrue()
        ->and(app(GitbookRenderer::class)->render($markdown, linkDiagrams: false))->toBe($first);

    // A short page is not worth the round trip: parsed every time, never stored.
    $short = "# Curta\n\nTexto.";
    app(GitbookRenderer::class)->render($short, linkDiagrams: false);
    expect(cache()->has('gitbook-render:v1:' . md5(implode("\0", [$short, '0', url('/'), '']))))->toBeFalse();
});

it('sees a diagram renamed, re-drawn or deleted after the page was cached', function () {
    Storage::fake('public');
    $diagram = Diagram::factory()->create(['name' => 'Fluxo antigo']);
    $markdown = longMarkdown('{% diagram slug="' . $diagram->slug . '" %}');
    $render = fn () => app(GitbookRenderer::class)->render($markdown, linkDiagrams: false);

    expect($render())->toContain('Fluxo antigo')->toContain('Sem imagem ainda');

    $diagram->update(['name' => 'Fluxo novo']);
    expect($render())->toContain('Fluxo novo')->not->toContain('Fluxo antigo');

    $diagram->addMedia(UploadedFile::fake()->image('a.png'))->toMediaCollection(Diagram::DIAGRAM_COLLECTION);
    $first = $diagram->fresh()->picture();
    expect($render())->toContain('?v=' . $first->id);

    $diagram->addMedia(UploadedFile::fake()->image('b.png'))->toMediaCollection(Diagram::DIAGRAM_COLLECTION);
    $second = $diagram->fresh()->picture();
    expect($second->id)->not->toBe($first->id)
        ->and($render())->toContain('?v=' . $second->id)->not->toContain('?v=' . $first->id);

    $diagram->delete();
    expect($render())->toContain('Diagrama removido');
});

it('never caches a page with a card grid, whose links depend on the reader', function () {
    $notebook = Notebook::factory()->create();
    DocumentationPage::factory()->for($notebook)->create(['slug' => 'outra', 'title' => 'Outra']);
    $markdown = longMarkdown("{% cards cols=\"2\" %}\n{% card title=\"Um\" link=\"page:outra\" %}\n{% endcard %}\n{% endcards %}");

    // Nobody's addresses: a card with no link. The caderno's editor: a link.
    // Were the first parse cached, the second reader would get the first's.
    $none = app(GitbookRenderer::class)->render($markdown, pageLinks: PageLinks::none());
    $internal = app(GitbookRenderer::class)->render($markdown, pageLinks: PageLinks::internal($notebook));

    expect($none)->not->toContain(route('notebooks.pages.edit', [$notebook, 'outra']))
        ->and($internal)->toContain(route('notebooks.pages.edit', [$notebook, 'outra']));
});
