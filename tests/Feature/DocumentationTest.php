<?php

use App\Actions\SyncDiagramFromChain;
use App\Enums\UserRole;
use App\Models\Diagram;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\Solution;
use App\Models\User;
use App\Support\GitbookRenderer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

function docsAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

/** Creates a documentation page already hanging off a caderno. */
function notebookPage(Notebook $notebook, ?string $documentation = null): DocumentationPage
{
    return DocumentationPage::factory()->for($notebook)->create(['documentation' => $documentation]);
}

/*
|--------------------------------------------------------------------------
| Caderno — page tree: create / rename / move / delete
|--------------------------------------------------------------------------
*/

it('opens the first page automatically from the caderno, creating one if none exists', function () {
    $notebook = Notebook::factory()->create();

    $response = $this->actingAs(docsAdmin())
        ->get(route('notebooks.show', $notebook))
        ->assertRedirect();

    $page = $notebook->pages()->sole();
    expect($page->title)->toBe('Página inicial')
        ->and($response->headers->get('Location'))->toBe(route('notebooks.pages.edit', [$notebook, $page]));
});

it('never creates a page for a viewer browsing an empty caderno, sending them back to the catalog', function () {
    // A GET must not write. The catalog links here for empty cadernos too, so a
    // viewer following that link used to silently trigger the placeholder-page
    // creation.
    $notebook = Notebook::factory()->create();

    $this->actingAs(User::factory()->create()) // viewer
        ->get(route('notebooks.show', $notebook))
        ->assertRedirect(route('notebooks.index'));

    expect($notebook->pages()->count())->toBe(0);
});

it('reuses the existing first page instead of creating another one', function () {
    $notebook = Notebook::factory()->create();
    $page = notebookPage($notebook, '# Oi');

    $this->actingAs(docsAdmin())->get(route('notebooks.show', $notebook));

    expect($notebook->pages()->count())->toBe(1)
        ->and($notebook->pages()->first()->is($page))->toBeTrue();
});

it('lets an admin create a second page in a caderno', function () {
    $notebook = Notebook::factory()->create();
    notebookPage($notebook);

    $response = $this->actingAs(docsAdmin())
        ->postJson(route('notebooks.pages.store', $notebook), ['title' => 'Guia de troubleshooting'])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($notebook->pages()->count())->toBe(2);
    $newPage = $notebook->pages()->where('title', 'Guia de troubleshooting')->sole();
    expect($response->json('redirect'))->toBe(route('notebooks.pages.edit', [$notebook, $newPage]));
});

it('forbids a viewer from creating a page', function () {
    $notebook = Notebook::factory()->create();

    $this->actingAs(User::factory()->create())
        ->postJson(route('notebooks.pages.store', $notebook), ['title' => 'X'])
        ->assertForbidden();

    expect($notebook->pages()->count())->toBe(0);
});

it('renames a page without changing its slug', function () {
    $notebook = Notebook::factory()->create();
    $page = notebookPage($notebook);
    $originalSlug = $page->slug;

    $this->actingAs(docsAdmin())
        ->patchJson(route('notebooks.pages.rename', [$notebook, $page]), ['title' => 'Novo título'])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($page->fresh())
        ->title->toBe('Novo título')
        ->slug->toBe($originalSlug);
});

it('moves a page up and down among its siblings', function () {
    $notebook = Notebook::factory()->create();
    $first = notebookPage($notebook);
    $second = notebookPage($notebook);
    $first->update(['position' => 0]);
    $second->update(['position' => 1]);

    $this->actingAs(docsAdmin())
        ->patchJson(route('notebooks.pages.move', [$notebook, $second]), ['direction' => 'up'])
        ->assertOk();

    expect($second->fresh()->position)->toBe(0)
        ->and($first->fresh()->position)->toBe(1);
});

it('deletes a page and redirects to the next remaining one', function () {
    $notebook = Notebook::factory()->create();
    $first = notebookPage($notebook);
    $second = notebookPage($notebook);
    $first->update(['position' => 0]);
    $second->update(['position' => 1]);

    $response = $this->actingAs(docsAdmin())
        ->deleteJson(route('notebooks.pages.destroy', [$notebook, $first]))
        ->assertOk();

    $this->assertModelMissing($first);
    expect($response->json('redirect'))->toBe(route('notebooks.pages.edit', [$notebook, $second]));
});

it('deletes the last page and redirects back to the caderno', function () {
    $notebook = Notebook::factory()->create();
    $page = notebookPage($notebook);

    $response = $this->actingAs(docsAdmin())
        ->deleteJson(route('notebooks.pages.destroy', [$notebook, $page]))
        ->assertOk();

    expect($response->json('redirect'))->toBe(route('notebooks.show', $notebook));
});

/*
|--------------------------------------------------------------------------
| Solution — save content / authorization / screen
|--------------------------------------------------------------------------
*/

it('lets an admin save a page documentation', function () {
    $notebook = Notebook::factory()->create();
    $page = notebookPage($notebook);

    $this->actingAs(docsAdmin())
        ->patchJson(route('notebooks.pages.update', [$notebook, $page]), ['documentation' => "# Título\n\nCorpo."])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($page->fresh()->documentation)->toBe("# Título\n\nCorpo.");
});

it('returns a cadernos slot for every solution the caderno documents, after saving', function () {
    // One save, N slots — a caderno describing three systems changes what all
    // three detail cards should say about coverage, and there is no other
    // moment at which they would learn.
    $notebook = Notebook::factory()->create();
    $page = notebookPage($notebook);
    $notebook->solutions()->attach(Solution::factory()->count(2)->create());

    $response = $this->actingAs(docsAdmin())
        ->patchJson(route('notebooks.pages.update', [$notebook, $page]), ['documentation' => 'Oi'])
        ->assertOk();

    expect($response->json('updatableSlots'))->toHaveCount(2)
        ->and($response->json('updatableSlots.0.id'))->toBe('solution-notebooks-slot');
});

it('forbids a viewer from saving page documentation', function () {
    $notebook = Notebook::factory()->create();
    $page = notebookPage($notebook);

    $this->actingAs(User::factory()->create()) // viewer
        ->patchJson(route('notebooks.pages.update', [$notebook, $page]), ['documentation' => 'x'])
        ->assertForbidden();

    expect($page->fresh()->documentation)->toBeNull();
});

it('shows the block editor to an admin on the page docs screen', function () {
    $notebook = Notebook::factory()->create();
    $page = notebookPage($notebook, '# Oi');

    $this->actingAs(docsAdmin())
        ->get(route('notebooks.pages.edit', [$notebook, $page]))
        ->assertOk()
        ->assertSee('data-ak-docs-editor', false);
});

it('shows read-only rendered html to a viewer on the page docs screen', function () {
    $notebook = Notebook::factory()->create();
    $page = notebookPage($notebook, '# Olá mundo');

    $response = $this->actingAs(User::factory()->create())
        ->get(route('notebooks.pages.edit', [$notebook, $page]))
        ->assertOk();

    expect($response->getContent())
        ->toContain('html-content')
        ->toContain('<h1>Olá mundo')
        ->toContain('heading-permalink')
        ->not->toContain('data-ak-docs-editor');
});

it('404s a page that does not belong to the caderno in the url', function () {
    $notebook = Notebook::factory()->create();
    $other = Notebook::factory()->create();
    $page = notebookPage($other, '# Doc');

    $this->actingAs(docsAdmin())
        ->get(route('notebooks.pages.edit', [$notebook, $page]))
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| The page↔diagram link (what replaced integration documentation)
|--------------------------------------------------------------------------
*/

it('links a page back to its caderno and lists it in the pages rail', function () {
    $notebook = Notebook::factory()->create(['name' => 'AllStrategy']);
    $page = notebookPage($notebook, '# Doc');
    $page->update(['title' => 'Visão geral']);

    $response = $this->actingAs(docsAdmin())
        ->get(route('notebooks.pages.edit', [$notebook, $page]))
        ->assertOk();

    expect($response->getContent())
        // The ↗ beside the caderno's name (rail title + collapsed crumb) is
        // what replaced the old back arrow.
        ->toContain('href="' . route('notebooks.show', $notebook) . '"')
        // The current page is listed (and linked) in the collapsible pages rail.
        ->toMatch('/>\s*Visão geral\s*<\/a>/')
        // Collapsible pages rail (mirrors flowSpec): the aside + its toggle.
        ->toContain('id="docs-sidebar"')
        ->toContain('data-ak-toggle="docs-sidebar"')
        // One button, both mechanics: `md:` collapses the in-flow rail by
        // width, `max-md:` slides the off-canvas overlay in. Each set is inert
        // on the other's side of the breakpoint, so toggling them together can
        // never leave the two states disagreeing.
        ->toContain('data-ak-toggle-classes="md:!w-0 md:!border-r-0 max-md:!translate-x-0"');
});

it('titles the pages rail with the caderno, not the generic word "Páginas"', function () {
    $notebook = Notebook::factory()->create(['name' => 'Active Directory / Entra ID']);
    $page = notebookPage($notebook, '# Doc');
    $page->update(['title' => 'Visão geral']);

    $content = $this->actingAs(docsAdmin())
        ->get(route('notebooks.pages.edit', [$notebook, $page]))
        ->assertOk()
        ->getContent();

    expect($content)
        ->toContain('Active Directory / Entra ID')
        ->not->toContain('>Páginas<')
        // Collapsed-rail crumb: same name again in the top bar, hidden until
        // toggle.js flips it in (`docs-sidebar-closed-state`).
        ->toContain('id="docs-sidebar-closed-state"')
        // …and the back arrow it replaced is gone.
        ->not->toContain('aria-label="Voltar"');
});

it('gives the pages rail a working mobile affordance instead of hiding it outright', function () {
    // Regression test: the rail used to be `max-md:hidden` (display:none),
    // so on a phone the toggle button was visible and clickable but could
    // never reveal anything — leaving no way to switch pages from inside
    // the editor. It's now an off-canvas overlay, which needs BOTH a
    // trigger and its own dismiss (the overlay covers the top bar).
    $notebook = Notebook::factory()->create();
    $page = notebookPage($notebook, '# Doc');

    $content = $this->actingAs(docsAdmin())
        ->get(route('notebooks.pages.edit', [$notebook, $page]))
        ->assertOk()
        ->getContent();

    // Scoped to the rail's own opening tag: `max-md:hidden` is legitimately
    // used elsewhere on this page (e.g. the desktop-only crumb).
    preg_match('/<aside id="docs-sidebar"[^>]*>/', $content, $aside);

    expect($aside[0] ?? '')
        ->not->toContain('max-md:hidden') // the rail itself is no longer display:none'd
        ->toContain('max-md:-translate-x-full')
        // Exactly two controls drive the overlay: the top-bar trigger and
        // the dismiss inside it. Both carry the SAME full class list, so the
        // desktop and mobile collapse states can't drift apart.
        ->and(substr_count($content, 'data-ak-toggle-classes="md:!w-0 md:!border-r-0 max-md:!translate-x-0"'))->toBe(2);
});

it('lists the solution diagrams as plain links, without the F3 canvas, on the solution page', function () {
    $solution = Solution::factory()->create();
    $diagram = Diagram::factory()->create([
        'name'  => 'SAP -> AllStrategy',
        'chain' => ['nodes' => [['solution_id' => $solution->id, 'label' => null]], 'edges' => []],
    ]);
    attachParticipants($diagram, [[$solution, 0]]);

    $response = $this->actingAs(docsAdmin())
        ->get(route('solutions.show', $solution))
        ->assertOk();

    expect($response->getContent())
        ->not->toContain('data-ak-chain-viz')
        ->toContain('href="' . route('diagrams.show', $diagram) . '"')
        ->toContain('SAP -&gt; AllStrategy');
});
/*
|--------------------------------------------------------------------------
| Media — upload + serving (per page, now)
|--------------------------------------------------------------------------
*/

it('uploads documentation media and serves it via /files/{id}', function () {
    Storage::fake('public');
    $notebook = Notebook::factory()->create();
    $page = notebookPage($notebook);

    $response = $this->actingAs(docsAdmin())
        ->post(route('notebooks.pages.media', [$notebook, $page]), [
            'file' => UploadedFile::fake()->image('diagrama.png', 200, 120),
        ])
        ->assertOk()
        ->assertJson(['success' => 1]);

    $mediaId = $response->json('file.mediaId');
    expect($mediaId)->toBeInt()
        ->and($response->json('file.url'))->toContain('/files/' . $mediaId);

    expect($page->fresh()->getMedia('docs'))->toHaveCount(1);

    // The authenticated route serves the file.
    $this->actingAs(docsAdmin())
        ->get(route('files.show', $mediaId))
        ->assertOk()
        ->assertHeader('content-type', 'image/png');
});

it('forbids a viewer from uploading documentation media', function () {
    Storage::fake('public');
    $notebook = Notebook::factory()->create();
    $page = notebookPage($notebook);

    $this->actingAs(User::factory()->create())
        ->post(route('notebooks.pages.media', [$notebook, $page]), [
            'file' => UploadedFile::fake()->image('x.png'),
        ])
        ->assertForbidden();
});

it('rejects a media upload with neither file nor url', function () {
    // Pasting an image from an external site sends `url`; upload sends `file`.
    // With neither, the reciprocal `required_without` rule blocks with a 422.
    $notebook = Notebook::factory()->create();
    $page = notebookPage($notebook);

    $this->actingAs(docsAdmin())
        ->postJson(route('notebooks.pages.media', [$notebook, $page]), [])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);
});

it('rejects an external image url that is not http(s)', function () {
    $notebook = Notebook::factory()->create();
    $page = notebookPage($notebook);

    $this->actingAs(docsAdmin())
        ->postJson(route('notebooks.pages.media', [$notebook, $page]), [
            'url' => 'ftp://example.com/x.png',
        ])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);
});

it('rejects a paste-image-url pointing at a private/loopback/link-local address', function (string $url) {
    // Blocked by App\Rules\PublicUrl before the controller ever calls
    // addMediaFromUrl() — no outbound request happens for any of these.
    $notebook = Notebook::factory()->create();
    $page = notebookPage($notebook);

    $this->actingAs(docsAdmin())
        ->postJson(route('notebooks.pages.media', [$notebook, $page]), ['url' => $url])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    expect($page->fresh()->getMedia('docs'))->toHaveCount(0);
})->with([
    'loopback'                    => 'http://127.0.0.1/x.png',
    'link-local / cloud metadata' => 'http://169.254.169.254/latest/meta-data/',
    'private RFC1918'             => 'http://10.0.0.5/x.png',
    'IPv6 loopback'               => 'http://[::1]/x.png',
]);

/*
|--------------------------------------------------------------------------
| GitbookRenderer — extended notation
|--------------------------------------------------------------------------
*/

it('renders gitbook notation to html-content markup', function () {
    $md = <<<'MD'
    # Título

    {% hint style="warning" %}
    Cuidado **aqui**.
    {% endhint %}

    | A | B |
    | - | - |
    | 1 | 2 |

    {% tabs %}
    {% tab title="Um" %}
    Conteúdo um.
    {% endtab %}
    {% tab title="Dois" %}
    Conteúdo dois.
    {% endtab %}
    {% endtabs %}

    ***
    MD;

    $html = app(GitbookRenderer::class)->render($md);

    expect($html)
        ->toContain('<h1>Título')
        ->toContain('heading-permalink')
        ->toContain('data-callout="warning"')
        ->toContain('<strong>aqui</strong>')
        ->toContain('<table>')
        ->toContain('data-ak-tabs=')
        ->toContain('<hr />');
});

it('gives every table its own horizontal scroller', function () {
    // A table is the one block whose min-content width can exceed the width it
    // was given — `width: 100%` does not bind it — so without this a wide one
    // painted over the "Nesta página" rail beside it instead of clipping.
    $html = app(GitbookRenderer::class)->render("| A | B |\n| - | - |\n| 1 | 2 |");

    expect($html)
        ->toContain('<div class="ak-table-scroll" tabindex="0"><table>')
        ->toContain('</table></div>');
});

it('leaves no line between an active tab and its panel', function () {
    $html = app(GitbookRenderer::class)->render(<<<'MD'
    {% tabs %}
    {% tab title="Um" %}
    Conteudo um.
    {% endtab %}
    {% tab title="Dois" %}
    Conteudo dois.
    {% endtab %}
    {% endtabs %}
    MD);

    // The three pieces that make an open tab read as open. Drop any one and the
    // active tab keeps a closed tab's underline: the panel's own top border is
    // a SECOND line, below the tablist's rail, which the tab's one pixel of
    // negative margin can never reach.
    expect($html)
        ->toContain('role="tablist" class="flex flex-wrap gap-1 border-b border-line"')   // the rail
        ->toContain('relative -mb-px')                                                     // the tab covers it
        ->toContain('rounded-b-md border border-t-0 border-line')                          // the panel doesn't redraw it
        ->not->toContain('border-b-surface');                                              // a border the tab doesn't have

    // And the state the client swaps to on a click has to agree with the state
    // the server rendered — they are the same two lists (TAB_*_CLASSES).
    expect($html)->toContain('&quot;activeClasses&quot;:[&quot;bg-surface&quot;,&quot;text-ink&quot;,&quot;border-line&quot;]');
});

it('renders an outline heroicon badge in hints, with a per-style default and an author override', function () {
    $default = app(GitbookRenderer::class)->render("{% hint style=\"info\" %}\nOi\n{% endhint %}");
    $override = app(GitbookRenderer::class)->render("{% hint style=\"info\" icon=\"fire\" %}\nOi\n{% endhint %}");
    $bogus = app(GitbookRenderer::class)->render("{% hint style=\"info\" icon=\"nao-existe\" %}\nOi\n{% endhint %}");

    // The icon badge is emitted as inline SVG (no longer an emoji in CSS ::before).
    expect($default)
        ->toContain('<span class="callout-icon"')
        ->toContain('<svg');

    // An icon chosen by the author changes the SVG; an invalid name falls back to the style's default.
    expect($override)->not->toBe($default);
    expect($bogus)->toBe($default);
});

it('renders url embeds as responsive iframes', function () {
    $md = "{% embed url=\"https://www.youtube.com/watch?v=abc123\" %}\n\n{% embed url=\"https://www.figma.com/file/xyz/Design\" %}";

    $html = app(GitbookRenderer::class)->render($md);

    expect($html)
        ->toContain('ak-embed--youtube')
        ->toContain('https://www.youtube.com/embed/abc123')
        ->toContain('ak-embed--figma')
        ->toContain('figma.com/embed');
});

it('falls back to a link for unsupported embed urls', function () {
    $html = app(GitbookRenderer::class)->render('{% embed url="https://example.com/thing" %}');

    expect($html)
        ->toContain('href="https://example.com/thing"')
        ->not->toContain('ak-embed');
});

it('renders nothing for empty documentation', function () {
    expect(app(GitbookRenderer::class)->render(null))->toBe('')
        ->and(app(GitbookRenderer::class)->render('   '))->toBe('');
});

/*
|--------------------------------------------------------------------------
| Sub-page navigation — what GitBook draws for a parent page
|--------------------------------------------------------------------------
*/

it('lists a page\'s sub-pages as navigation cards', function () {
    $notebook = Notebook::factory()->create();
    $parent = notebookPage($notebook, '# Seção');
    $parent->update(['title' => 'Camada Raw']);
    $child = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Material', 'documentation' => '# M']);

    $response = $this->actingAs(docsAdmin())
        ->get(route('notebooks.pages.edit', [$notebook, $parent]))
        ->assertOk();

    expect($response->getContent())
        ->toContain('aria-label="Sub-páginas"')
        ->toContain('Nesta seção')
        ->toContain(route('notebooks.pages.edit', [$notebook, $child]));
});

it('draws no sub-page block on a page that has none', function () {
    $notebook = Notebook::factory()->create();
    $page = notebookPage($notebook, '# Folha');

    $this->actingAs(docsAdmin())
        ->get(route('notebooks.pages.edit', [$notebook, $page]))
        ->assertOk()
        ->assertDontSee('aria-label="Sub-páginas"', escape: false);
});

it('does not call an empty parent page undocumented when it has sub-pages', function () {
    // A GitBook parent is usually just its own title — the children ARE the
    // content. Printing "nothing here yet" above a list of them is a lie, and
    // it is the state most of the imported corpus lands in.
    $notebook = Notebook::factory()->create();
    $parent = notebookPage($notebook, null);
    DocumentationPage::factory()->childOf($parent)->create(['title' => 'Material']);

    $this->actingAs(User::factory()->create()) // viewer: sees the read-only branch
        ->get(route('notebooks.pages.edit', [$notebook, $parent]))
        ->assertOk()
        ->assertDontSee('Nenhuma documentação cadastrada')
        ->assertSee('Nesta seção');
});

it('still says a page is undocumented when it has neither text nor sub-pages', function () {
    $notebook = Notebook::factory()->create();
    $page = notebookPage($notebook, null);

    $this->actingAs(User::factory()->create())
        ->get(route('notebooks.pages.edit', [$notebook, $page]))
        ->assertOk()
        ->assertSee('Nenhuma documentação cadastrada');
});

it('collapses the authenticated rail to the branch being edited', function () {
    $notebook = Notebook::factory()->create();
    $open = notebookPage($notebook, '# A');
    $open->update(['title' => 'Aberta']);
    $child = DocumentationPage::factory()->childOf($open)->create(['title' => 'Filha visível']);
    $shut = notebookPage($notebook, '# B');
    $shut->update(['title' => 'Fechada']);
    $hidden = DocumentationPage::factory()->childOf($shut)->create(['title' => 'Filha escondida']);

    $content = $this->actingAs(docsAdmin())
        ->get(route('notebooks.pages.edit', [$notebook, $open]))
        ->assertOk()
        ->getContent();

    expect($content)->toMatch('/data-page-id="' . $child->id . '"(?![^>]*\\bhidden\\b)/')
        ->and($content)->toMatch('/data-page-id="' . $hidden->id . '"[^>]*\\bhidden\\b/');
});

/*
|--------------------------------------------------------------------------
| Citing a diagram — the block that replaced the page↔diagram FK
|--------------------------------------------------------------------------
*/

it('renders a cited diagram with its name and a link that opens in a new tab', function () {
    $diagram = Diagram::factory()->create(['name' => 'SAP ↔ SVL']);

    $html = app(GitbookRenderer::class)->render('{% diagram slug="' . $diagram->slug . '" %}');

    expect($html)
        ->toContain('ak-doc-diagram')
        ->toContain('SAP ↔ SVL')
        ->toContain('href="' . route('diagrams.show', $diagram) . '"')
        // A citation must never cost the reader the page they were on.
        ->toContain('target="_blank"')
        ->toContain('rel="noopener"');
});

it('says the picture is missing rather than dropping the citation', function () {
    // The PNG is posted by the browser after a layout save, so a drawing nobody
    // has opened since that feature landed has none. The card still renders —
    // the link is the point, and a citation that vanished because a DERIVED
    // file is absent would read as a broken document.
    $diagram = Diagram::factory()->create();

    expect(app(GitbookRenderer::class)->render('{% diagram slug="' . $diagram->slug . '" %}'))
        ->toContain('Sem imagem ainda')
        ->toContain(route('diagrams.show', $diagram));
});

it('degrades a citation of a deleted diagram instead of breaking the page', function () {
    $html = app(GitbookRenderer::class)->render("Antes.\n\n{% diagram slug=\"sumiu\" %}\n\nDepois.");

    expect($html)
        ->toContain('Diagrama removido')
        ->toContain('sumiu')
        // The prose around it survives untouched.
        ->toContain('<p>Antes.</p>')
        ->toContain('<p>Depois.</p>');
});

it('hands the editor a picture URL only for a drawing that has one', function () {
    // What makes the editor's diagram block a PREVIEW rather than a label. The
    // null is as load-bearing as the URL: it is what tells the block to draw
    // "sem imagem ainda" instead of an <img> that would 404.
    \Illuminate\Support\Facades\Storage::fake('public');

    $drawn = Diagram::factory()->create(['name' => 'Com imagem']);
    $drawn->addMedia(\Illuminate\Http\UploadedFile::fake()->image('canvas.png', 800, 600))
        ->toMediaCollection(Diagram::DIAGRAM_COLLECTION);
    Diagram::factory()->create(['name' => 'Sem imagem']);

    $entries = collect($this->actingAs(docsAdmin())
        ->getJson(route('diagrams.catalog'))
        ->assertOk()
        ->json('groups'))
        ->flatMap(fn (array $group) => $group['diagrams'])
        ->keyBy('name');

    expect($entries['Com imagem']['pictureUrl'])->toBe(route('diagrams.picture.show', $drawn))
        ->and($entries['Sem imagem']['pictureUrl'])->toBeNull();
});

it('groups the diagram catalog by solution, loose drawings last', function () {
    $solution = Solution::factory()->create(['name' => 'SAP']);
    $placed = Diagram::factory()->create([
        'name'  => 'Desenho do SAP',
        'chain' => ['nodes' => [
            ['solution_id' => $solution->id, 'label' => null, 'kind' => 'system'],
            ['solution_id' => null, 'label' => 'Parceiro', 'kind' => 'system'],
        ], 'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]]],
    ]);
    app(SyncDiagramFromChain::class)->handle($placed);

    Diagram::factory()->create(['name' => 'Só texto livre']);

    $groups = $this->actingAs(docsAdmin())
        ->getJson(route('diagrams.catalog'))
        ->assertOk()
        ->json('groups');

    // Each entry carries what the editor's block needs to PREVIEW the citation
    // — the drawing's name, its picture (null until the canvas has been saved
    // with one) and its canvas URL, all built with `route()` here so the tool
    // never assembles a path of its own.
    expect(collect($groups)->firstWhere('solution', 'SAP')['diagrams'])
        ->toContain([
            'slug'       => $placed->slug,
            'name'       => 'Desenho do SAP',
            'pictureUrl' => null,
            'url'        => route('diagrams.show', $placed),
        ]);

    // A drawing that names no catalog solution is still citable — it lands in
    // a trailing group of its own rather than being hidden from the picker.
    expect(end($groups)['solution'])->toBe('Sem solução no catálogo');
    expect(collect(end($groups)['diagrams'])->pluck('name'))->toContain('Só texto livre');
});
