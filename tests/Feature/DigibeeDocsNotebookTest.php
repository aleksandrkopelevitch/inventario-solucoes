<?php

use App\Actions\Digibee\ImportDigibeeDocsNotebook;
use App\Actions\Digibee\SyncDigibeeDocs;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\Solution;
use App\Services\DocumentationPageService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

function syncCorpus(array $paths): void
{
    Storage::fake('local');

    $index = "## Connectors & Triggers\n\n";

    foreach ($paths as $path => $title) {
        $index .= "- [{$title}](https://docs.digibee.com/{$path}): descrição.\n";
    }

    Http::fake([
        'docs.digibee.com/llms.txt' => Http::response($index),
        'docs.digibee.com/*'        => Http::response("# Título\n\nCorpo da página."),
    ]);

    app(SyncDigibeeDocs::class)->handle();
}

it('reproduces the corpus tree and invents a section page for a path level with no page', function () {
    syncCorpus([
        'documentation/connectors-and-triggers/connectors/web-protocols/rest-v2.md' => 'REST V2',
        'documentation/connectors-and-triggers/connectors/web-protocols/soap-v3.md' => 'SOAP V3',
    ]);

    $report = app(ImportDigibeeDocsNotebook::class)->handle();
    $tree = app(DocumentationPageService::class)->tree($report['notebook']);

    // Three levels Digibee publishes no page for ("connectors-and-triggers",
    // "connectors", "web-protocols"), plus the two real pages.
    expect($report['created'])->toBe(5)
        ->and($report['sections'])->toBe(3)
        ->and($tree->pluck('page.title')->all())
        ->toBe(['Connectors And Triggers', 'Connectors', 'Web Protocols', 'REST V2', 'SOAP V3'])
        ->and($tree->pluck('depth')->all())->toBe([0, 1, 2, 3, 3]);
});

it('collapses a page deeper than MAX_DEPTH and keeps its ancestry in the title', function () {
    syncCorpus([
        'documentation/a/b/c/d/e/deep-page.md' => 'Deep Page',
    ]);

    $report = app(ImportDigibeeDocsNotebook::class)->handle();
    $deepest = $report['notebook']->pages()->get()
        ->first(fn (DocumentationPage $page) => str_contains($page->title, 'Deep Page'));
    $tree = app(DocumentationPageService::class)->tree($report['notebook']);

    expect($report['collapsed'])->toBe(1)
        // Nothing is dropped — the skipped levels move into the title.
        ->and($deepest->title)->toBe('E › Deep Page')
        ->and($tree->max('depth'))->toBeLessThan(DocumentationPage::MAX_DEPTH);
});

it('updates in place on a re-import instead of duplicating the corpus', function () {
    syncCorpus([
        'documentation/connectors-and-triggers/connectors/logic/choice.md' => 'Choice',
    ]);

    app(ImportDigibeeDocsNotebook::class)->handle();
    $second = app(ImportDigibeeDocsNotebook::class)->handle();

    expect($second['created'])->toBe(0)
        ->and($second['updated'])->toBe(4)
        ->and(Notebook::count())->toBe(1);
});

it('matches by title within its parent, so a title the corpus repeats stays two pages', function () {
    // Real corpus: 17 titles repeat (every Release Notes month is "August"),
    // and none of them collide under the same parent — so the pair is the key,
    // and matching on the title alone would merge two different pages.
    syncCorpus([
        'documentation/release-notes/2025/august.md' => 'August',
        'documentation/release-notes/2026/august.md' => 'August',
    ]);

    $report = app(ImportDigibeeDocsNotebook::class)->handle();
    app(ImportDigibeeDocsNotebook::class)->handle();

    expect($report['notebook']->pages()->where('title', 'August')->count())->toBe(2);
});

it('links the caderno to the Digibee solution when the catalog has one', function () {
    $solution = Solution::factory()->create(['name' => 'Digibee (iPaaS)']);
    syncCorpus(['documentation/a.md' => 'A']);

    $report = app(ImportDigibeeDocsNotebook::class)->handle();

    expect($report['notebook']->solutions->pluck('id')->all())->toBe([$solution->id]);
});

it('writes the page with a source note and no leftover site chrome', function () {
    syncCorpus(['documentation/a.md' => 'A']);

    $report = app(ImportDigibeeDocsNotebook::class)->handle();
    $page = $report['notebook']->pages()->where('title', 'A')->first();

    expect($page->documentation)->toContain('Cópia da documentação oficial da Digibee')
        ->and($page->documentation)->toContain('Corpo da página.');
});

it('leaves a section page with no content of its own', function () {
    syncCorpus(['documentation/grupo/pagina.md' => 'Página']);

    $report = app(ImportDigibeeDocsNotebook::class)->handle();
    $section = $report['notebook']->pages()->where('title', 'Grupo')->first();

    expect($section->documentation)->toBeNull();
});
