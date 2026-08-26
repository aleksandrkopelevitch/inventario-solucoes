<?php

use App\Enums\UserRole;
use App\Models\DocumentationPage;
use App\Models\Diagram;
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

/** Creates a documentation page already hanging off a Solution. */
function solutionPage(Solution $solution, ?string $documentation = null): DocumentationPage
{
    return DocumentationPage::factory()->for($solution, 'container')->create(['documentation' => $documentation]);
}

/*
|--------------------------------------------------------------------------
| Solution — page tree: create / rename / move / delete
|--------------------------------------------------------------------------
*/

it('opens the first page automatically from the docs index, creating one if none exists', function () {
    $solution = Solution::factory()->create();

    $response = $this->actingAs(docsAdmin())
        ->get(route('solutions.docs.edit', $solution))
        ->assertRedirect();

    $page = $solution->pages()->sole();
    expect($page->title)->toBe('Visão geral')
        ->and($response->headers->get('Location'))->toBe(route('solutions.docs.page.edit', [$solution, $page]));
});

it('never creates a page for a viewer browsing an undocumented solution, sending them to its empty state', function () {
    // A GET must not write. The documentation hub links here for solutions
    // with zero pages too, so a viewer following that link used to silently
    // trigger the placeholder-page creation.
    $solution = Solution::factory()->create();

    $this->actingAs(User::factory()->create()) // viewer
        ->get(route('solutions.docs.edit', $solution))
        ->assertRedirect(route('solutions.show', $solution));

    expect($solution->pages()->count())->toBe(0);
});

it('reuses the existing first page instead of creating another one', function () {
    $solution = Solution::factory()->create();
    $page = solutionPage($solution, '# Oi');

    $this->actingAs(docsAdmin())->get(route('solutions.docs.edit', $solution));

    expect($solution->pages()->count())->toBe(1)
        ->and($solution->pages()->first()->is($page))->toBeTrue();
});

it('lets an admin create a second page for a solution', function () {
    $solution = Solution::factory()->create();
    solutionPage($solution);

    $response = $this->actingAs(docsAdmin())
        ->postJson(route('solutions.docs.pages.store', $solution), ['title' => 'Guia de troubleshooting'])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($solution->pages()->count())->toBe(2);
    $newPage = $solution->pages()->where('title', 'Guia de troubleshooting')->sole();
    expect($response->json('redirect'))->toBe(route('solutions.docs.page.edit', [$solution, $newPage]));
});

it('forbids a viewer from creating a page', function () {
    $solution = Solution::factory()->create();

    $this->actingAs(User::factory()->create())
        ->postJson(route('solutions.docs.pages.store', $solution), ['title' => 'X'])
        ->assertForbidden();

    expect($solution->pages()->count())->toBe(0);
});

it('renames a page without changing its slug', function () {
    $solution = Solution::factory()->create();
    $page = solutionPage($solution);
    $originalSlug = $page->slug;

    $this->actingAs(docsAdmin())
        ->patchJson(route('solutions.docs.pages.rename', [$solution, $page]), ['title' => 'Novo título'])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($page->fresh())
        ->title->toBe('Novo título')
        ->slug->toBe($originalSlug);
});

it('moves a page up and down among its siblings', function () {
    $solution = Solution::factory()->create();
    $first = solutionPage($solution);
    $second = solutionPage($solution);
    $first->update(['position' => 0]);
    $second->update(['position' => 1]);

    $this->actingAs(docsAdmin())
        ->patchJson(route('solutions.docs.pages.move', [$solution, $second]), ['direction' => 'up'])
        ->assertOk();

    expect($second->fresh()->position)->toBe(0)
        ->and($first->fresh()->position)->toBe(1);
});

it('deletes a page and redirects to the next remaining one', function () {
    $solution = Solution::factory()->create();
    $first = solutionPage($solution);
    $second = solutionPage($solution);
    $first->update(['position' => 0]);
    $second->update(['position' => 1]);

    $response = $this->actingAs(docsAdmin())
        ->deleteJson(route('solutions.docs.pages.destroy', [$solution, $first]))
        ->assertOk();

    $this->assertModelMissing($first);
    expect($response->json('redirect'))->toBe(route('solutions.docs.page.edit', [$solution, $second]));
});

it('deletes the last page and redirects back to the docs index', function () {
    $solution = Solution::factory()->create();
    $page = solutionPage($solution);

    $response = $this->actingAs(docsAdmin())
        ->deleteJson(route('solutions.docs.pages.destroy', [$solution, $page]))
        ->assertOk();

    expect($response->json('redirect'))->toBe(route('solutions.docs.edit', $solution));
});

/*
|--------------------------------------------------------------------------
| Solution — save content / authorization / screen
|--------------------------------------------------------------------------
*/

it('lets an admin save a solution page documentation', function () {
    $solution = Solution::factory()->create();
    $page = solutionPage($solution);

    $this->actingAs(docsAdmin())
        ->patchJson(route('solutions.docs.update', [$solution, $page]), ['documentation' => "# Título\n\nCorpo."])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($page->fresh()->documentation)->toBe("# Título\n\nCorpo.");
});

it('returns the solution documentation slot after saving', function () {
    $solution = Solution::factory()->create();
    $page = solutionPage($solution);

    $response = $this->actingAs(docsAdmin())
        ->patchJson(route('solutions.docs.update', [$solution, $page]), ['documentation' => 'Oi'])
        ->assertOk();

    expect($response->json('updatableSlots.0.id'))->toBe('solution-documentation-slot');
});

it('forbids a viewer from saving solution page documentation', function () {
    $solution = Solution::factory()->create();
    $page = solutionPage($solution);

    $this->actingAs(User::factory()->create()) // viewer
        ->patchJson(route('solutions.docs.update', [$solution, $page]), ['documentation' => 'x'])
        ->assertForbidden();

    expect($page->fresh()->documentation)->toBeNull();
});

it('shows the block editor to an admin on the solution page docs screen', function () {
    $solution = Solution::factory()->create();
    $page = solutionPage($solution, '# Oi');

    $this->actingAs(docsAdmin())
        ->get(route('solutions.docs.page.edit', [$solution, $page]))
        ->assertOk()
        ->assertSee('data-ak-docs-editor', false);
});

it('shows read-only rendered html to a viewer on the solution page docs screen', function () {
    $solution = Solution::factory()->create();
    $page = solutionPage($solution, '# Olá mundo');

    $response = $this->actingAs(User::factory()->create())
        ->get(route('solutions.docs.page.edit', [$solution, $page]))
        ->assertOk();

    expect($response->getContent())
        ->toContain('html-content')
        ->toContain('<h1>Olá mundo')
        ->toContain('heading-permalink')
        ->not->toContain('data-ak-docs-editor');
});

it('404s a page that does not belong to the solution in the url', function () {
    $solution = Solution::factory()->create();
    $other = Solution::factory()->create();
    $page = solutionPage($other, '# Doc');

    $this->actingAs(docsAdmin())
        ->get(route('solutions.docs.page.edit', [$solution, $page]))
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| The page↔diagram link (what replaced integration documentation)
|--------------------------------------------------------------------------
*/

it('links a solution doc page back to the solution and lists it in the pages rail', function () {
    $solution = Solution::factory()->create(['name' => 'AllStrategy']);
    $page = solutionPage($solution, '# Doc');
    $page->update(['title' => 'Visão geral']);

    $response = $this->actingAs(docsAdmin())
        ->get(route('solutions.docs.page.edit', [$solution, $page]))
        ->assertOk();

    expect($response->getContent())
        // The ↗ beside the solution's name (rail title + collapsed crumb) is
        // what replaced the old back arrow.
        ->toContain('href="' . route('solutions.show', $solution) . '"')
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

it('titles the pages rail with the solution, not the generic word "Páginas"', function () {
    $solution = Solution::factory()->create(['name' => 'Active Directory / Entra ID']);
    $page = solutionPage($solution, '# Doc');
    $page->update(['title' => 'Visão geral']);

    $content = $this->actingAs(docsAdmin())
        ->get(route('solutions.docs.page.edit', [$solution, $page]))
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
    $solution = Solution::factory()->create();
    $page = solutionPage($solution, '# Doc');

    $content = $this->actingAs(docsAdmin())
        ->get(route('solutions.docs.page.edit', [$solution, $page]))
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

it('offers the diagram picker on a page that has no diagram, and no canvas with it', function () {
    $solution = Solution::factory()->create();
    $page = solutionPage($solution, '# Visão geral');
    Diagram::factory()->create(['name' => 'SAP -> AllStrategy']);

    $content = $this->actingAs(docsAdmin())
        ->get(route('solutions.docs.page.edit', [$solution, $page]))
        ->assertOk()
        ->getContent();

    expect($content)
        // The picker is always there — "this page has no diagram" is
        // information, and the gesture that fixes it has to be reachable.
        // `x-ui.inline-edit` json_encodes its config, which escapes every
        // slash in the URL — assert the shape that actually reaches the page.
        ->toContain(str_replace('/', '\\/', route('solutions.docs.pages.diagram', [$solution, $page])))
        ->toContain('Sem diagrama')
        // Every diagram is a candidate, whether or not this solution is in it.
        ->toContain('SAP -&gt; AllStrategy')
        // …but nothing is mounted until one is linked.
        ->not->toContain('data-ak-chain-viz')
        ->not->toContain('page-tab-panels');
});

it('renders the Documentação/Diagrama tabs and mounts the canvas once a page has a diagram', function () {
    $solution = Solution::factory()->create();
    $page = solutionPage($solution, '# Visão geral');
    $diagram = Diagram::factory()->create([
        'name'  => 'SAP -> AllStrategy',
        'chain' => ['nodes' => [['solution_id' => $solution->id, 'label' => null]], 'edges' => []],
    ]);
    $page->diagram()->associate($diagram)->save();

    $content = $this->actingAs(docsAdmin())
        ->get(route('solutions.docs.page.edit', [$solution, $page]))
        ->assertOk()
        ->getContent();

    expect($content)
        ->toContain('page-tab-docs')
        ->toContain('page-tab-diagram')
        ->toContain('data-ak-chain-viz')
        ->toContain('data-ak-chain-graph=')
        // The canvas posts to the DIAGRAM's own endpoints wherever it is
        // mounted — the page it was opened from never appears in a chain URL.
        // The graph payload is json_encoded into an attribute, so its slashes
        // arrive escaped.
        ->toContain(str_replace('/', '\\/', route('diagrams.chain.node.add', $diagram)))
        // The doc-specific actions live inside the Documentação panel,
        // not the persistent top bar (only one Salvar visible per tab).
        ->toContain('data-ak-docs-save');
});

it('marks a page that carries a diagram in the pages rail', function () {
    $solution = Solution::factory()->create();
    $plain = solutionPage($solution, '# Sem desenho');
    $withDiagram = solutionPage($solution, '# Com desenho');
    $withDiagram->diagram()->associate(Diagram::factory()->create())->save();

    $content = $this->actingAs(docsAdmin())
        ->get(route('solutions.docs.page.edit', [$solution, $plain]))
        ->assertOk()
        ->getContent();

    // One marker for the one page that has a drawing — the rail is the only
    // place the whole tree is visible at once.
    expect(substr_count($content, 'title="Tem diagrama vinculado"'))->toBe(1);
});

it('lets an admin point a page at a diagram and clear it again', function () {
    $solution = Solution::factory()->create();
    $page = solutionPage($solution, '# Visão geral');
    $diagram = Diagram::factory()->create();

    $this->actingAs(docsAdmin())
        ->patchJson(route('solutions.docs.pages.diagram', [$solution, $page]), ['diagram_id' => $diagram->id])
        ->assertOk()
        ->assertJson(['type' => 'success', 'redirect' => route('solutions.docs.page.edit', [$solution, $page])]);

    expect($page->fresh()->diagram_id)->toBe($diagram->id);

    $this->actingAs(docsAdmin())
        ->patchJson(route('solutions.docs.pages.diagram', [$solution, $page]), ['diagram_id' => null])
        ->assertOk();

    expect($page->fresh()->diagram_id)->toBeNull();
});

it('forbids a viewer from pointing a page at a diagram', function () {
    $solution = Solution::factory()->create();
    $page = solutionPage($solution, '# Visão geral');
    $diagram = Diagram::factory()->create();

    $this->actingAs(User::factory()->create(['role' => UserRole::Viewer->value]))
        ->patchJson(route('solutions.docs.pages.diagram', [$solution, $page]), ['diagram_id' => $diagram->id])
        ->assertStatus(403);

    expect($page->fresh()->diagram_id)->toBeNull();
});

it('lets one diagram serve pages of more than one solution', function () {
    $diagram = Diagram::factory()->create(['name' => 'SAP -> AllStrategy']);
    $first = Solution::factory()->create(['name' => 'SAP']);
    $second = Solution::factory()->create(['name' => 'AllStrategy']);
    $firstPage = solutionPage($first, '# Lado SAP');
    $secondPage = solutionPage($second, '# Lado AllStrategy');

    $firstPage->diagram()->associate($diagram)->save();
    $secondPage->diagram()->associate($diagram)->save();

    expect($diagram->pages()->pluck('id')->all())->toBe([$firstPage->id, $secondPage->id]);

    // …and the diagram's own page names both of them, which is the only place
    // the 1..N side of the relation is visible.
    $content = $this->actingAs(docsAdmin())->get(route('diagrams.show', $diagram))->assertOk()->getContent();

    expect($content)
        ->toContain(route('solutions.docs.page.edit', [$first, $firstPage]))
        ->toContain(route('solutions.docs.page.edit', [$second, $secondPage]));
});

it('keeps a page and its text when the diagram it points at is deleted', function () {
    $solution = Solution::factory()->create();
    $page = solutionPage($solution, '# Conteúdo que deu trabalho');
    $diagram = Diagram::factory()->create();
    $page->diagram()->associate($diagram)->save();

    $diagram->delete();

    // `nullOnDelete`: deleting a drawing must never take documentation with it.
    expect($page->fresh())->not->toBeNull()
        ->and($page->fresh()->diagram_id)->toBeNull()
        ->and($page->fresh()->documentation)->toBe('# Conteúdo que deu trabalho');
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
    $solution = Solution::factory()->create();
    $page = solutionPage($solution);

    $response = $this->actingAs(docsAdmin())
        ->post(route('solutions.docs.media', [$solution, $page]), [
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
    $solution = Solution::factory()->create();
    $page = solutionPage($solution);

    $this->actingAs(User::factory()->create())
        ->post(route('solutions.docs.media', [$solution, $page]), [
            'file' => UploadedFile::fake()->image('x.png'),
        ])
        ->assertForbidden();
});

it('rejects a media upload with neither file nor url', function () {
    // Pasting an image from an external site sends `url`; upload sends `file`.
    // With neither, the reciprocal `required_without` rule blocks with a 422.
    $solution = Solution::factory()->create();
    $page = solutionPage($solution);

    $this->actingAs(docsAdmin())
        ->postJson(route('solutions.docs.media', [$solution, $page]), [])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);
});

it('rejects an external image url that is not http(s)', function () {
    $solution = Solution::factory()->create();
    $page = solutionPage($solution);

    $this->actingAs(docsAdmin())
        ->postJson(route('solutions.docs.media', [$solution, $page]), [
            'url' => 'ftp://example.com/x.png',
        ])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);
});

it('rejects a paste-image-url pointing at a private/loopback/link-local address', function (string $url) {
    // Blocked by App\Rules\PublicUrl before the controller ever calls
    // addMediaFromUrl() — no outbound request happens for any of these.
    $solution = Solution::factory()->create();
    $page = solutionPage($solution);

    $this->actingAs(docsAdmin())
        ->postJson(route('solutions.docs.media', [$solution, $page]), ['url' => $url])
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
