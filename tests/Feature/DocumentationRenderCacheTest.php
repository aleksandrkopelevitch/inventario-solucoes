<?php

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
| few KB. What the parse reads besides the text — a file's name and size, the
| URL root — is in the key, and a page
| with a card grid (resolved per reader inside the parse) is never cached.
*/

/** A page long enough to be cached, citing `$extra`. */
function longMarkdown(string $extra): string
{
    return "# Página longa\n\n" . str_repeat("Um parágrafo de enchimento para passar do limite do cache.\n\n", 120) . $extra . "\n";
}

it('serves a large page from the cache instead of parsing it again', function () {
    $markdown = longMarkdown('Fim.');
    $key = 'gitbook-render:v3:' . md5(implode("\0", [$markdown, url('/'), '']));

    expect(cache()->has($key))->toBeFalse();

    $first = app(GitbookRenderer::class)->render($markdown);

    expect(cache()->has($key))->toBeTrue()
        ->and(app(GitbookRenderer::class)->render($markdown))->toBe($first);

    // A short page is not worth the round trip: parsed every time, never stored.
    $short = "# Curta\n\nTexto.";
    app(GitbookRenderer::class)->render($short);
    expect(cache()->has('gitbook-render:v3:' . md5(implode("\0", [$short, url('/'), '']))))->toBeFalse();
});

it('sees a file renamed or replaced after the page was cached', function () {
    Storage::fake('public');
    $page = DocumentationPage::factory()->for(Notebook::factory())->create();
    $media = $page->addMedia(UploadedFile::fake()->create('contrato.pdf', 10))->toMediaCollection('docs');
    $markdown = longMarkdown('{% file src="/files/' . $media->id . '" %}');
    $render = fn () => app(GitbookRenderer::class)->render($markdown);

    expect($render())->toContain('contrato.pdf');

    $media->update(['file_name' => 'contrato-assinado.pdf']);
    expect($render())->toContain('contrato-assinado.pdf')->not->toContain('>contrato.pdf<');

    $media->delete();
    expect($render())->toContain('Baixar arquivo');
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
