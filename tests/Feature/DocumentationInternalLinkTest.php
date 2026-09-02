<?php

use App\Enums\UserRole;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\User;
use App\Services\DocumentationPageService;
use App\Support\Documentation\PageLinks;
use App\Support\GitbookRenderer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Internal links — `page:{slug}` and `#anchor`
|--------------------------------------------------------------------------
|
| A link between two pages of the same caderno is stored as `page:{slug}`
| rather than as a URL, because the same page has two addresses (the app's and
| the magic link's) and a URL in the Markdown would be correct for exactly one
| audience. These tests are about that resolution and its degrade.
*/

function linkAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

it('resolves a page: link to the app URL for a signed-in reader', function () {
    $notebook = Notebook::factory()->create();
    $target = DocumentationPage::factory()->for($notebook)->create(['slug' => 'sap-cpi']);

    $html = app(GitbookRenderer::class)->render(
        'Veja [o outro lado](page:sap-cpi#autenticacao).',
        pageLinks: PageLinks::internal($notebook),
    );

    expect($html)->toContain('href="' . route('notebooks.pages.edit', [$notebook, $target->slug]) . '#autenticacao"')
        ->and($html)->not->toContain('page:sap-cpi');
});

it('resolves the same link to the token URL for a magic-link visitor', function () {
    $notebook = Notebook::factory()->create();
    DocumentationPage::factory()->for($notebook)->create(['slug' => 'sap-cpi']);

    $html = app(GitbookRenderer::class)->render(
        '[o outro lado](page:sap-cpi)',
        pageLinks: PageLinks::shared($notebook, 'tok123456789'),
    );

    expect($html)->toContain('href="' . route('public.docs.page', ['tok123456789', 'sap-cpi']) . '"');
});

it('drops the href entirely for a page the caderno does not have', function () {
    $notebook = Notebook::factory()->create();

    $html = app(GitbookRenderer::class)->render(
        'Veja [a página sumida](page:nao-existe).',
        pageLinks: PageLinks::internal($notebook),
    );

    // The words survive; the link does not. An <a> with no href is not a link,
    // which is the same promise the "Diagrama removido" card makes.
    expect($html)->toContain('>a página sumida</a>')
        ->and($html)->toContain('data-ak-page-missing="nao-existe"')
        ->and($html)->not->toContain('href=');
});

it('refuses to resolve a page of ANOTHER caderno', function () {
    $notebook = Notebook::factory()->create();
    $elsewhere = Notebook::factory()->create();
    DocumentationPage::factory()->for($elsewhere)->create(['slug' => 'outro-caderno']);

    $html = app(GitbookRenderer::class)->render(
        '[lá](page:outro-caderno)',
        pageLinks: PageLinks::internal($notebook),
    );

    expect($html)->toContain('data-ak-page-missing="outro-caderno"')
        ->and($html)->not->toContain('href=');
});

it('resolves a link written inside a hint and inside a table cell', function () {
    $notebook = Notebook::factory()->create();
    DocumentationPage::factory()->for($notebook)->create(['slug' => 'destino']);

    $markdown = <<<'MD'
    {% hint style="info" %}
    Veja [o destino](page:destino).
    {% endhint %}

    | Sistema | Doc |
    | --- | --- |
    | A | [o destino](page:destino#fluxo) |
    MD;

    $html = app(GitbookRenderer::class)->render($markdown, pageLinks: PageLinks::internal($notebook));
    $expected = route('notebooks.pages.edit', [$notebook, 'destino']);

    // Nesting is why the resolution runs over the finished HTML rather than
    // over the Markdown: `renderLines()` recurses, and a link is legitimate in
    // either of these.
    expect(substr_count($html, 'href="' . $expected))->toBe(2)
        ->and($html)->toContain($expected . '#fluxo"');
});

it('leaves an in-page #anchor alone — it needs no resolving', function () {
    $notebook = Notebook::factory()->create();

    $html = app(GitbookRenderer::class)->render(
        "## Autenticação\n\nVolte para [a seção](#autenticação).",
        pageLinks: PageLinks::internal($notebook),
    );

    // Both halves are pinned on purpose, and they are NOT the same string:
    // commonmark keeps the accent in a heading's id and percent-encodes it in a
    // link destination. The browser matches the two, and re-deriving either one
    // by hand is exactly the drift the anchors must never be exposed to.
    expect($html)->toContain('id="autenticação"')
        ->and($html)->toContain('href="#autentica%C3%A7%C3%A3o"');
});

it('renders a page: link as dead text when there is no caderno to resolve against', function () {
    // The search index and the flowSpec thread render with PageLinks::none();
    // neither must ever emit a `page:` address to a browser.
    $html = app(GitbookRenderer::class)->render('[x](page:qualquer)');

    expect($html)->not->toContain('page:qualquer"')
        ->and($html)->toContain('data-ak-page-missing="qualquer"');
});

it('resolves internal links on the public reader', function () {
    $notebook = Notebook::factory()->create(['public_token' => 'tok123456789']);
    $from = DocumentationPage::factory()->for($notebook)->create([
        'slug'          => 'de',
        'documentation' => 'Veja [o outro](page:para#detalhes).',
    ]);
    DocumentationPage::factory()->for($notebook)->create(['slug' => 'para', 'documentation' => '## Detalhes']);

    $this->get(route('public.docs.page', ['tok123456789', $from->slug]))
        ->assertOk()
        ->assertSee(route('public.docs.page', ['tok123456789', 'para']) . '#detalhes', escape: false);
});

it('resolves internal links on the authenticated read-only reader', function () {
    $notebook = Notebook::factory()->create();
    $from = DocumentationPage::factory()->for($notebook)->create([
        'slug'          => 'de',
        'documentation' => 'Veja [o outro](page:para).',
    ]);
    DocumentationPage::factory()->for($notebook)->create(['slug' => 'para', 'documentation' => 'Conteúdo.']);

    // A VIEWER, deliberately: it is the only role that gets the rendered HTML
    // from the server (an editor gets Editor.js and the raw Markdown).
    $viewer = User::factory()->create(['role' => UserRole::Viewer->value]);

    $this->actingAs($viewer)
        ->get(route('notebooks.pages.edit', [$notebook, $from]))
        ->assertOk()
        ->assertSee(route('notebooks.pages.edit', [$notebook, 'para']), escape: false);
});

/*
|--------------------------------------------------------------------------
| The picker's catalog
|--------------------------------------------------------------------------
*/

it('lists the caderno pages and their H1-H3 anchors as link targets', function () {
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create([
        'title'         => 'Visão geral',
        'slug'          => 'visao-geral',
        'documentation' => "# Visão geral\n\nTexto.\n\n## Autenticação\n\nMais.\n\n### Detalhe fino\n\nOk.\n\n#### Fora do alcance\n",
    ]);

    $response = $this->actingAs(linkAdmin())
        ->getJson(route('notebooks.link-targets', $notebook))
        ->assertOk();

    $pages = $response->json('pages');

    expect($pages)->toHaveCount(1)
        ->and($pages[0]['slug'])->toBe($page->slug)
        ->and($pages[0]['title'])->toBe('Visão geral');

    $headings = collect($pages[0]['headings']);

    // The anchors come out of the RENDERED html, so they are the same strings
    // the reader's `id=` carries — never re-derived here.
    expect($headings->pluck('text')->all())->toBe(['Autenticação', 'Detalhe fino'])
        // Accent and all — commonmark's slugger keeps it, and this is a reading
        // of what it emitted rather than a second guess at it.
        ->and($headings->pluck('anchor')->all())->toBe(['autenticação', 'detalhe-fino'])
        ->and($headings->pluck('level')->all())->toBe([2, 3]);
});

it('keeps every caderno out of another caderno link targets', function () {
    $notebook = Notebook::factory()->create();
    DocumentationPage::factory()->for($notebook)->create(['documentation' => '## Aqui']);
    $elsewhere = Notebook::factory()->create();
    DocumentationPage::factory()->for($elsewhere)->create(['title' => 'De outro caderno', 'documentation' => '## Lá']);

    $response = $this->actingAs(linkAdmin())
        ->getJson(route('notebooks.link-targets', $notebook))
        ->assertOk();

    expect(collect($response->json('pages'))->pluck('title')->all())
        ->not->toContain('De outro caderno');
});

it('refuses the link targets to someone who cannot edit the caderno', function () {
    $notebook = Notebook::factory()->create();
    $viewer = User::factory()->create(['role' => UserRole::Viewer->value]);

    $this->actingAs($viewer)
        ->getJson(route('notebooks.link-targets', $notebook))
        ->assertForbidden();
});

it('hands the editor the link picker endpoint and the page slug', function () {
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create();

    $this->actingAs(linkAdmin())
        ->get(route('notebooks.pages.edit', [$notebook, $page]))
        ->assertOk()
        // `json_encode` escapes the slashes inside the `data-config` attribute,
        // which is why this is not simply the route().
        ->assertSee(str_replace('/', '\/', route('notebooks.link-targets', $notebook)), escape: false)
        ->assertSee('&quot;pageSlug&quot;:&quot;' . $page->slug . '&quot;', escape: false);
});

it('reserves the static segments a page slug could collide with', function () {
    $notebook = Notebook::factory()->create();

    // `link-targets` and `context-pages` are real routes under
    // notebooks/{notebook}; a page allowed to take either slug would be
    // unreachable behind them.
    foreach (['Link targets', 'Context pages'] as $title) {
        $page = app(DocumentationPageService::class)->create($notebook, $title);

        expect($page->slug)->not->toBeIn(['link-targets', 'context-pages']);
    }
});
