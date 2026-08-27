<?php

use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Services\DocumentationSearchService;
use App\View\Components\Documentation\SearchResults;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/** A shared caderno with one page of documentation. */
function sharedNotebook(string $token, ?string $documentation = null, string $title = 'Visão geral'): Notebook
{
    $notebook = Notebook::factory()->create(['public_token' => $token]);

    if ($documentation !== null) {
        DocumentationPage::factory()->for($notebook)->create([
            'title'         => $title,
            'documentation' => $documentation,
        ]);
    }

    return $notebook;
}

/** The rendered slot HTML from a search response. */
function searchHtml(string $token, array $query = []): string
{
    $response = test()->getJson(route('public.docs.search', [$token, ...$query]))->assertOk();

    return $response->json('updatableSlots.0.content');
}

/*
|--------------------------------------------------------------------------
| The palette on the page
|--------------------------------------------------------------------------
*/

it('renders the search panel above the documentation, not behind a shortcut', function () {
    $notebook = sharedNotebook('tok-panel', '# Visão geral');

    $response = $this->get(route('public.docs.notebook', $notebook->public_token))
        ->assertOk()
        ->assertSee('data-ak-docs-search', false)
        ->assertSee('data-ak-docs-search-input', false)
        // The slot the search response replaces must exist before the first
        // search, or ajax-slot.js has nothing to swap.
        ->assertSee('id="' . SearchResults::DOM_ID . '"', false)
        ->assertSee(route('public.docs.search', $notebook->public_token), false)
        // The trigger lives in the topbar and opens the palette; the palette
        // itself is a <dialog> mounted at the end of the body.
        ->assertSee('data-ak-docs-search-open', false)
        ->assertSee('data-ak-docs-shell', false);

    $html = $response->getContent();

    // The TRIGGER comes before the documentation, the palette after it: a
    // <dialog> lives in the top layer, so where it sits in the flow is exactly
    // what stops it fighting the sticky header's stacking context.
    expect(strpos($html, 'data-ak-docs-search-open'))->toBeLessThan(strpos($html, 'data-ak-docs-shell'))
        ->and(strpos($html, 'data-ak-docs-search-input'))->toBeGreaterThan(strpos($html, 'data-ak-docs-shell'));
});

it('ships the palette warm once the corpus is indexed', function () {
    $notebook = sharedNotebook('tok-warm', "# Visão geral\n\n## Contrato\n\n| a | b |\n| --- | --- |\n| 1 | 2 |");
    DocumentationPage::factory()->for($notebook)->create(['title' => 'Outra', 'documentation' => '# Outra']);

    // Cold: the palette must not drag the index build into the page render.
    $this->get(route('public.docs.notebook', $notebook->public_token))
        ->assertOk()
        ->assertSee('data-ak-docs-search-pending', false)
        ->assertDontSee('data-ak-docs-search-facet', false);

    // Warm (the client's first search built it): the results slot ships with
    // the HTML, section chips included.
    $this->getJson(route('public.docs.search', $notebook->public_token))->assertOk();

    $this->get(route('public.docs.notebook', $notebook->public_token))
        ->assertOk()
        ->assertDontSee('data-ak-docs-search-pending', false)
        ->assertSee('data-ak-docs-search-facet="section"', false);
});

it('offers the three search scopes, all on by default', function () {
    $notebook = sharedNotebook('tok-scopes', '# Visão geral');

    $content = $this->get(route('public.docs.notebook', $notebook->public_token))
        ->assertOk()
        ->getContent();

    foreach (['prose', 'table', 'code'] as $scope) {
        expect($content)->toMatch('/data-ak-docs-search-scope="' . $scope . '"[^>]*checked/');
    }

    // The content-tag row the palette replaced is gone: "results that CONTAIN a
    // table" and "search INSIDE tables" side by side was the confusing part.
    expect($content)->not->toContain('data-ak-docs-search-facet="tag"');
});

it('marks the slot as active only while the corpus is being narrowed', function () {
    // docs-search.js hides the reading shell off this marker, so an idle panel
    // carrying it would blank the documentation on load.
    $notebook = sharedNotebook('tok-active', "# Visão geral\n\nTexto.");

    $idle = searchHtml($notebook->public_token);
    $searching = searchHtml($notebook->public_token, ['q' => 'texto']);

    expect($idle)->not->toContain('data-ak-docs-search-active')
        ->and($searching)->toContain('data-ak-docs-search-active');
});

/*
|--------------------------------------------------------------------------
| Searching
|--------------------------------------------------------------------------
*/

it('answers a search with the results slot and a total', function () {
    $notebook = sharedNotebook('tok-search', "# Faturamento\n\nO fluxo de faturas segue o pedido.");

    $response = $this->getJson(route('public.docs.search', [$notebook->public_token, 'q' => 'faturas']))
        ->assertOk();

    expect($response->json('total'))->toBe(1)
        ->and($response->json('updatableSlots.0.id'))->toBe(SearchResults::DOM_ID)
        ->and($response->json('updatableSlots.0.content'))->toContain('<mark');
});

it('returns the whole corpus in reading order when the query is empty', function () {
    $notebook = sharedNotebook('tok-empty', "# Primeira\n\nAlgum texto.\n\n## Segunda\n\nMais texto.", 'Primeira');

    // The opening H1 repeats the page title, so it IS the page entry — plus
    // one entry per heading below it.
    $this->getJson(route('public.docs.search', $notebook->public_token))
        ->assertOk()
        ->assertJson(['total' => 2]);
});

it('does not list a page and its opening H1 as two results for the same place', function () {
    $notebook = sharedNotebook('tok-h1', "# Faturamento\n\nTexto.", 'Faturamento');

    $this->getJson(route('public.docs.search', [$notebook->public_token, 'q' => 'faturamento']))
        ->assertOk()
        ->assertJson(['total' => 1]);

    // …but an H1 that says something else is still its own hit.
    $notebook->pages()->first()->update(['documentation' => "# Faturamento\n\nTexto.\n\n# Cobrança\n\nOutro texto."]);

    $this->getJson(route('public.docs.search', $notebook->public_token))
        ->assertOk()
        ->assertJson(['total' => 2]);
});

it('finds a heading and links to its own anchor inside the page', function () {
    $notebook = sharedNotebook('tok-anchor', "# Visão geral\n\nIntro.\n\n## Contrato de mensagens\n\nCada evento carrega um id.");

    $html = searchHtml($notebook->public_token, ['q' => 'contrato']);
    $page = $notebook->pages()->first();

    expect($html)->toContain(route('public.docs.page', [$notebook->public_token, $page->slug]) . '#contrato-de-mensagens');
});

it('never returns the same passage as both a page and a section', function () {
    // "carveout" appears once, under a heading. The page entry only owns the
    // text BEFORE the first heading, so exactly one result may match it.
    $notebook = sharedNotebook('tok-overlap', "# Visão geral\n\nIntro sem o termo.\n\n## Detalhes\n\nO carveout roda à noite.");

    $this->getJson(route('public.docs.search', [$notebook->public_token, 'q' => 'carveout']))
        ->assertOk()
        ->assertJson(['total' => 1]);
});

it('matches regardless of accents', function () {
    $notebook = sharedNotebook('tok-accents', "# Integração de crédito\n\nRotina noturna.");

    $this->getJson(route('public.docs.search', [$notebook->public_token, 'q' => 'integracao credito']))
        ->assertOk()
        ->assertJson(['total' => 1]);

    // …and the other way round: an accented query against unaccented text.
    // Folding is applied to BOTH sides, so neither direction depends on the
    // visitor spelling the accent the way the author did.
    $this->getJson(route('public.docs.search', [$notebook->public_token, 'q' => 'rotína']))
        ->assertOk()
        ->assertJson(['total' => 1]);
});

it('narrows with every extra word instead of widening', function () {
    $notebook = Notebook::factory()->create(['public_token' => 'tok-and']);
    DocumentationPage::factory()->for($notebook)->create(['title' => 'Pedidos', 'documentation' => '# Pedidos']);
    DocumentationPage::factory()->for($notebook)->create(['title' => 'Pedidos cancelados', 'documentation' => '# Pedidos cancelados']);

    $this->getJson(route('public.docs.search', [$notebook->public_token, 'q' => 'pedidos']))
        ->assertOk()->assertJson(['total' => 2]);

    $this->getJson(route('public.docs.search', [$notebook->public_token, 'q' => 'pedidos cancelados']))
        ->assertOk()->assertJson(['total' => 1]);
});

/*
|--------------------------------------------------------------------------
| Facets
|--------------------------------------------------------------------------
*/

it('filters by a content facet', function () {
    $notebook = sharedNotebook(
        'tok-facet-tag',
        "# Visão geral\n\nIntro.\n\n## Com tabela\n\n| a | b |\n| --- | --- |\n| 1 | 2 |\n\n## Sem tabela\n\nSó texto.",
    );

    $this->getJson(route('public.docs.search', [$notebook->public_token, 'filter' => ['tag' => 'table']]))
        ->assertOk()
        ->assertJson(['total' => 1]);
});

it('filters by the top-level section a hit belongs to', function () {
    $notebook = Notebook::factory()->create(['public_token' => 'tok-facet-section']);
    $first = DocumentationPage::factory()->for($notebook)
        ->create(['title' => 'Arquitetura', 'slug' => 'arquitetura', 'documentation' => "# Arquitetura\n\n## Fluxo\n\nTexto."]);
    DocumentationPage::factory()->for($notebook)
        ->create(['title' => 'Operação', 'slug' => 'operacao', 'documentation' => "# Operação\n\nTexto."]);

    // A subpage's hits are filed under its ROOT, not under itself.
    $child = DocumentationPage::factory()->for($notebook)
        ->create(['title' => 'Detalhes', 'slug' => 'detalhes', 'documentation' => "# Detalhes\n\nTexto."]);
    $child->parent()->associate($first);
    $child->save();

    $this->getJson(route('public.docs.search', [$notebook->public_token, 'filter' => ['section' => 'arquitetura']]))
        ->assertOk()
        // Arquitetura's own page + its "Fluxo" heading + the Detalhes subpage.
        ->assertJson(['total' => 3]);
});

it('rejects an unknown content facet with the app json error shape', function () {
    $notebook = sharedNotebook('tok-bad-facet', '# Visão geral');

    $response = $this->getJson(route('public.docs.search', [$notebook->public_token, 'filter' => ['tag' => 'inventado']]))
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    expect($response->json('message'))->toContain('desconhecido');
});

/*
|--------------------------------------------------------------------------
| Scope and access
|--------------------------------------------------------------------------
*/

it('never reaches another solution documentation through the search', function () {
    $shared = sharedNotebook('tok-mine', "# Minha página\n\nTermo compartilhado.");
    sharedNotebook('tok-theirs', "# Página alheia\n\nTermo compartilhado.");

    $response = $this->getJson(route('public.docs.search', [$shared->public_token, 'q' => 'termo compartilhado']))->assertOk();

    expect($response->json('total'))->toBe(1)
        ->and($response->json('updatableSlots.0.content'))
        ->toContain('Minha página')
        ->not->toContain('Página alheia');
});

it('404s the search for an unknown or revoked token', function () {
    $this->getJson(route('public.docs.search', 'nope-not-a-token'))->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| The index itself
|--------------------------------------------------------------------------
*/

it('picks up an edit even when it lands in the same second as the last search', function () {
    // The cache keys hash CONTENT, not `updated_at`: timestamps are stored at
    // second resolution, so a clock-keyed index would answer with the old text
    // here — and keep doing it until the TTL expired.
    $notebook = sharedNotebook('tok-invalidate', "# Visão geral\n\nTexto antigo.");

    $this->getJson(route('public.docs.search', [$notebook->public_token, 'q' => 'antigo']))
        ->assertOk()->assertJson(['total' => 1]);

    $notebook->pages()->first()->update(['documentation' => "# Visão geral\n\nTexto novo."]);

    $this->getJson(route('public.docs.search', [$notebook->public_token, 'q' => 'antigo']))
        ->assertOk()->assertJson(['total' => 0]);

    $this->getJson(route('public.docs.search', [$notebook->public_token, 'q' => 'novo']))
        ->assertOk()->assertJson(['total' => 1]);
});

it('escapes page text instead of letting it reach the palette as markup', function () {
    $notebook = sharedNotebook('tok-escape', "# Visão geral\n\nUm <script>alert(1)</script> no meio do texto.");

    $html = searchHtml($notebook->public_token, ['q' => 'meio']);

    expect($html)->not->toContain('<script>')
        ->and($html)->toContain('&lt;script&gt;');
});

/*
|--------------------------------------------------------------------------
| Search scope — WHERE the query looks
|--------------------------------------------------------------------------
*/

it('searches only inside the buckets the query asked for', function () {
    // The same word in three different kinds of element. Narrowing the scope
    // has to change WHICH of them can be found — that is the whole point of
    // the switches, and what turns the corpus into something you can
    // interrogate rather than just grep.
    $notebook = sharedNotebook('tok-scope', implode("\n\n", [
        '# Página',
        'Um parágrafo que menciona sentinela em prosa.',
        "| Coluna | Valor |\n| --- | --- |\n| sentinela | 1 |",
        "```\nconst sentinela = 1\n```",
    ]));

    $svc = app(DocumentationSearchService::class);

    // Everything on: the word is found.
    expect($svc->search($notebook, 'sentinela')['total'])->toBeGreaterThan(0);

    foreach (['prose', 'table', 'code'] as $only) {
        expect($svc->search($notebook, 'sentinela', ['scopes' => [$only]])['total'])
            ->toBeGreaterThan(0, "esperava achar 'sentinela' no escopo {$only}");
    }

    // A word that lives ONLY in prose disappears when prose is off.
    expect($svc->search($notebook, 'parágrafo', ['scopes' => ['prose']])['total'])->toBeGreaterThan(0)
        ->and($svc->search($notebook, 'parágrafo', ['scopes' => ['table', 'code']])['total'])->toBe(0);
});

it('treats an empty scope selection as everywhere, not nowhere', function () {
    // Unticking the last switch must not answer every query with silence.
    $notebook = sharedNotebook('tok-empty-scope', "# Página\n\nTexto com sentinela.");
    $svc = app(DocumentationSearchService::class);

    expect($svc->search($notebook, 'sentinela', ['scopes' => []])['total'])
        ->toBe($svc->search($notebook, 'sentinela')['total']);
});

it('refuses an unknown scope instead of quietly widening the search', function () {
    $notebook = sharedNotebook('tok-bad-scope', '# Página');

    $this->getJson(route('public.docs.search', [$notebook->public_token, 'q' => 'x', 'filter' => ['scopes' => ['tudo']]]))
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);
});

it('keeps the scope selection out of the url when nothing is narrowed', function () {
    // All three on is the default the server already assumes, so the common
    // request stays byte-identical to what it was before scopes existed.
    $notebook = sharedNotebook('tok-default-scope', "# Página\n\nTexto.");

    $payload = app(DocumentationSearchService::class)->search($notebook, 'texto');

    expect($payload['filters']['scopes'])->toBe(DocumentationSearchService::SCOPES);
});
