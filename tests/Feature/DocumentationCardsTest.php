<?php

use App\Enums\UserRole;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\User;
use App\Support\Documentation\BlockVault;
use App\Support\Documentation\PageLinks;
use App\Support\GitbookRenderer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Cards — `{% cards %}` … `{% card %}` … `{% endcards %}`
|--------------------------------------------------------------------------
|
| A regular grid of product cards: a logo, a name, an optional line of
| description and a destination. Two halves are worth pinning — the GRID (every
| card the same size, the columns the author picked, the logo adapting to a
| fixed frame) and the DESTINATION, which is a reference resolved per reader
| and is allowed to leave the caderno precisely because a card that cannot be
| addressed degrades to a card with no link.
*/

function cardsMarkdown(string $cards, int $cols = 3): string
{
    return "{% cards cols=\"{$cols}\" %}\n{$cards}\n{% endcards %}";
}

function oneCard(string $attrs, string $body = ''): string
{
    return "{% card {$attrs} %}\n{$body}\n{% endcard %}";
}

it('renders one card per {% card %}, in the grid the author picked', function () {
    $markdown = cardsMarkdown(
        oneCard('title="Construshow" image="/files/12"', 'ERP de lojas.')
        . "\n" . oneCard('title="SAP S/4 HANA" image="/files/13" fit="cover"'),
    );

    $html = app(GitbookRenderer::class)->render($markdown);

    expect($html)->toContain('lg:grid-cols-3')
        ->and(substr_count($html, 'ak-doc-card__body'))->toBe(2)
        ->and($html)->toContain('>Construshow</span>')
        ->and($html)->toContain('>ERP de lojas.</span>')
        ->and($html)->toContain('src="/files/12"');
});

it('asks for two or four columns and gets them', function () {
    $card = oneCard('title="Um"');

    expect(app(GitbookRenderer::class)->render(cardsMarkdown($card, 2)))
        ->toContain('sm:grid-cols-2')
        ->not->toContain('lg:grid-cols')
        ->and(app(GitbookRenderer::class)->render(cardsMarkdown($card, 4)))
        ->toContain('lg:grid-cols-4');
});

it('falls back to three columns when the count is one nothing styles', function () {
    // A `cols` nobody wrote a class for must not travel on as a class the
    // stylesheet never shipped — the grid would collapse to one column.
    $html = app(GitbookRenderer::class)->render(cardsMarkdown(oneCard('title="Um"'), 7));

    expect($html)->toContain('lg:grid-cols-3');
});

it('carries the fit each card chose, and only the three that exist', function () {
    $markdown = cardsMarkdown(
        oneCard('title="A" image="/files/1" fit="cover"')
        . "\n" . oneCard('title="B" image="/files/2" fit="original"')
        . "\n" . oneCard('title="C" image="/files/3" fit="esticado"')
        . "\n" . oneCard('title="D" image="/files/4"'),
    );

    $html = app(GitbookRenderer::class)->render($markdown);

    expect($html)->toContain('data-fit="cover"')
        ->and($html)->toContain('data-fit="original"')
        // The invented one and the absent one both land on the default.
        ->and(substr_count($html, 'data-fit="contain"'))->toBe(2)
        ->and($html)->not->toContain('esticado');
});

it('renders a card with no logo as a card, not as a hole in the grid', function () {
    $html = app(GitbookRenderer::class)->render(cardsMarkdown(oneCard('title="Sem logo"', 'Só texto.')));

    expect($html)->toContain('>Sem logo</span>')
        ->and($html)->not->toContain('ak-doc-card__media');
});

it('renders nothing at all for a grid with no cards in it', function () {
    expect(app(GitbookRenderer::class)->render("{% cards cols=\"3\" %}\n{% endcards %}"))
        ->not->toContain('ak-doc-cards');
});

it('leaves the prose around the grid alone', function () {
    $html = app(GitbookRenderer::class)->render(
        "Antes.\n\n" . cardsMarkdown(oneCard('title="Um"')) . "\n\nDepois.",
    );

    expect($html)->toContain('<p>Antes.</p>')
        ->and($html)->toContain('<p>Depois.</p>')
        ->and($html)->toContain('ak-doc-cards');
});

it('escapes what somebody typed into a title', function () {
    $html = app(GitbookRenderer::class)->render(
        cardsMarkdown(oneCard('title="A &amp; B"', '<script>alert(1)</script>')),
    );

    expect($html)->toContain('A &amp; B')
        ->and($html)->not->toContain('<script>');
});

/*
|--------------------------------------------------------------------------
| The destination, per reader
|--------------------------------------------------------------------------
*/

it('points a notebook: card at the caderno itself for somebody editing', function () {
    $notebook = Notebook::factory()->create();
    $target = Notebook::factory()->create();

    $html = app(GitbookRenderer::class)->render(
        cardsMarkdown(oneCard('title="Lá" link="notebook:' . $target->slug . '"')),
        pageLinks: PageLinks::internal($notebook),
    );

    expect($html)->toContain('href="' . route('notebooks.show', $target) . '"')
        ->and($html)->not->toContain('target="_blank"');
});

it('points the same card at /docs for a knowledge-base reader — but only if it is published', function () {
    $notebook = Notebook::factory()->published()->create();
    $published = Notebook::factory()->published()->create();
    $draft = Notebook::factory()->create();

    $html = app(GitbookRenderer::class)->render(
        cardsMarkdown(
            oneCard('title="Publicado" link="notebook:' . $published->slug . '"')
            . "\n" . oneCard('title="Rascunho" link="notebook:' . $draft->slug . '"'),
        ),
        pageLinks: PageLinks::knowledgeBase($notebook),
    );

    // The unpublished one still renders — the logo and the name are the
    // documentation, the click is the affordance.
    expect($html)->toContain('href="' . route('docs.notebook', $published) . '"')
        ->and($html)->toContain('>Rascunho</span>')
        ->and($html)->not->toContain($draft->slug);
});

it('gives a magic-link visitor no address outside the caderno they hold', function () {
    $notebook = Notebook::factory()->create(['public_token' => 'tok123456789']);
    $target = Notebook::factory()->published()->create();

    $html = app(GitbookRenderer::class)->render(
        cardsMarkdown(oneCard('title="Outro caderno" image="/files/9" link="notebook:' . $target->slug . '"')),
        pageLinks: PageLinks::shared($notebook, 'tok123456789'),
    );

    expect($html)->toContain('>Outro caderno</span>')
        ->and($html)->toContain('src="/files/9"')
        ->and($html)->not->toContain('<a class="ak-doc-card"');
});

it('keeps the card when the caderno it points at is gone', function () {
    $notebook = Notebook::factory()->create();

    $html = app(GitbookRenderer::class)->render(
        cardsMarkdown(oneCard('title="Sumiu" link="notebook:nao-existe"')),
        pageLinks: PageLinks::internal($notebook),
    );

    expect($html)->toContain('>Sumiu</span>')
        ->and($html)->not->toContain('href=');
});

it('resolves a page: card against the caderno being read', function () {
    $notebook = Notebook::factory()->create();
    DocumentationPage::factory()->for($notebook)->create(['slug' => 'autenticacao']);

    $internal = app(GitbookRenderer::class)->render(
        cardsMarkdown(oneCard('title="Autenticação" link="page:autenticacao#tokens"')),
        pageLinks: PageLinks::internal($notebook),
    );

    $shared = app(GitbookRenderer::class)->render(
        cardsMarkdown(oneCard('title="Autenticação" link="page:autenticacao"')),
        pageLinks: PageLinks::shared($notebook, 'tok123456789'),
    );

    expect($internal)->toContain(route('notebooks.pages.edit', [$notebook, 'autenticacao']) . '#tokens"')
        ->and($shared)->toContain('href="' . route('public.docs.page', ['tok123456789', 'autenticacao']) . '"');
});

it('opens an external address in a new tab and refuses one that is not an address', function () {
    $html = app(GitbookRenderer::class)->render(
        cardsMarkdown(
            oneCard('title="Site" link="https://digibee.com"')
            . "\n" . oneCard('title="Armadilha" link="javascript:alert(1)"'),
        ),
    );

    expect($html)->toContain('href="https://digibee.com" target="_blank" rel="noopener"')
        // This renderer runs with allow_unsafe_links, so a destination that is
        // not http(s) or mailto has to be refused HERE or it reaches the href.
        ->and($html)->not->toContain('javascript:')
        ->and($html)->toContain('>Armadilha</span>');
});

/*
|--------------------------------------------------------------------------
| The catalog the editor's picker reads
|--------------------------------------------------------------------------
*/

it('offers every caderno and this one\'s pages to the card picker', function () {
    $notebook = Notebook::factory()->create();
    DocumentationPage::factory()->for($notebook)->create(['title' => 'Autenticação', 'slug' => 'autenticacao', 'documentation' => '# Autenticação']);
    $other = Notebook::factory()->published()->create();
    $draft = Notebook::factory()->create();

    $response = $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->getJson(route('notebooks.card-targets', $notebook));

    $response->assertOk();

    $slugs = collect($response->json('notebooks'))->pluck('published', 'slug');

    // Not scoped to this caderno, unlike the LINK catalog: a card that a reader
    // has no address for degrades to a card with no link, so the grid is
    // allowed to be an index of the other cadernos.
    expect($slugs)->toHaveKeys([$notebook->slug, $other->slug, $draft->slug])
        ->and($slugs[$other->slug])->toBeTrue()
        ->and($slugs[$draft->slug])->toBeFalse()
        ->and(collect($response->json('pages'))->pluck('slug'))->toContain('autenticacao');
});

it('refuses the card catalog to somebody who cannot edit the caderno', function () {
    $notebook = Notebook::factory()->create();

    $this->actingAs(User::factory()->create(['role' => UserRole::Member->value]))
        ->getJson(route('notebooks.card-targets', $notebook))
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| The Documentation Assistant may neither write a grid nor lose one
|--------------------------------------------------------------------------
*/

it('freezes a whole card grid for the assistant', function () {
    $page = "Texto.\n\n" . cardsMarkdown(oneCard('title="SAP" image="/files/12" link="notebook:sap"'), 3) . "\n\nMais texto.";

    $vault = BlockVault::from([$page]);
    $masked = $vault->mask($page);

    // One marker for the whole grid: every logo in it is a `/files/{id}` only
    // an upload knows and every destination a slug only the picker does, so
    // there is nothing in here the model can author correctly.
    expect($masked)->toContain('[[BLOCK-1]]')
        ->and($masked)->not->toContain('{% card')
        ->and($masked)->not->toContain('/files/12')
        ->and($vault->restore($masked))->toBe($page)
        ->and($vault->audit('Texto.'))->toBe(1);
});

it('strips a card grid out of a page handed over only as reference', function () {
    $stripped = BlockVault::strip(cardsMarkdown(oneCard('title="SAP" image="/files/12"')));

    expect($stripped)->toBe('[grade de cards]');
});
