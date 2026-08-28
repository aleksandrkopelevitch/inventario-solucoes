<?php

use App\Enums\UserRole;
use App\Http\Controllers\NotebookController;
use App\Models\Diagram;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\User;
use App\Services\DocumentationSearchService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

function shareAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

/** Creates a documentation page already attached to a caderno. */
function publicPage(Notebook $notebook, ?string $documentation = null): DocumentationPage
{
    return DocumentationPage::factory()->for($notebook)->create(['documentation' => $documentation]);
}

/*
|--------------------------------------------------------------------------
| Generate / revoke the public link (admin)
|--------------------------------------------------------------------------
*/

it('lets an admin generate a public documentation link', function () {
    $notebook = Notebook::factory()->create(['public_token' => null]);

    $response = $this->actingAs(shareAdmin())
        ->postJson(route('notebooks.share', $notebook))
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($notebook->fresh()->public_token)->not->toBeNull()
        ->and($response->json('updatableSlots.0.id'))->toBe('docs-share-slot');
});

// The token is the ONLY authorization on public-docs/{token} (no auth
// middleware, no throttle), so its length is a security property rather than a
// style choice. Pinned at a floor, not at NotebookController::TOKEN_LENGTH
// itself, so making links longer stays free and making them guessable does not.
it('generates a public link token long enough to be unguessable', function () {
    $notebook = Notebook::factory()->create(['public_token' => null]);

    $this->actingAs(shareAdmin())->postJson(route('notebooks.share', $notebook))->assertOk();

    expect(NotebookController::TOKEN_LENGTH)->toBeGreaterThanOrEqual(12)
        ->and($notebook->fresh()->public_token)
        ->toMatch('/^[A-Za-z0-9]{12,}$/');
});

it('keeps the same token when sharing is generated twice', function () {
    $notebook = Notebook::factory()->create(['public_token' => null]);
    $admin = shareAdmin();

    $this->actingAs($admin)->postJson(route('notebooks.share', $notebook))->assertOk();
    $token = $notebook->fresh()->public_token;

    $this->actingAs($admin)->postJson(route('notebooks.share', $notebook))->assertOk();

    expect($notebook->fresh()->public_token)->toBe($token);
});

it('lets an admin revoke the public link', function () {
    $notebook = Notebook::factory()->create(['public_token' => 'tok-123456']);

    $this->actingAs(shareAdmin())
        ->deleteJson(route('notebooks.unshare', $notebook))
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($notebook->fresh()->public_token)->toBeNull();
});

it('forbids a viewer from generating or revoking a public link', function () {
    $notebook = Notebook::factory()->create(['public_token' => null]);
    $viewer = User::factory()->create();

    $this->actingAs($viewer)->postJson(route('notebooks.share', $notebook))->assertForbidden();
    $this->actingAs($viewer)->deleteJson(route('notebooks.unshare', $notebook))->assertForbidden();

    expect($notebook->fresh()->public_token)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Public page (no auth)
|--------------------------------------------------------------------------
*/

it('renders the public solution documentation without auth for a valid token', function () {
    $notebook = Notebook::factory()->create(['public_token' => 'valid-token-xyz']);
    publicPage($notebook, '# Olá público');

    $this->get(route('public.docs.notebook', $notebook->public_token))
        ->assertOk()
        ->assertSee('html-content', false)
        ->assertSee('<h1>Olá público', false)
        ->assertSee($notebook->name);
});

it('404s the public page for an unknown or revoked token', function () {
    Notebook::factory()->create(['public_token' => null]);

    $this->get(route('public.docs.notebook', 'nope-not-a-token'))->assertNotFound();
});

it('renders a non-first page of the solution tree publicly', function () {
    $notebook = Notebook::factory()->create(['public_token' => 'tok-tree']);
    publicPage($notebook, '# Primeira')->update(['position' => 0]);
    $second = publicPage($notebook, '# Segunda');
    $second->update(['position' => 1]);

    $this->get(route('public.docs.page', [$notebook->public_token, $second]))
        ->assertOk()
        ->assertSee('<h1>Segunda', false);
});

it('resolves the right page when two different cadernos each have a page with the same slug', function () {
    // Slug is only unique WITHIN the caderno (composite unique on
    // documentation_pages) — two cadernos can each have a page named "teste".
    // The public route can't rely on global model binding by slug, or it'll
    // grab the lowest-id one and 404 for the wrong owner. The lowest-id page
    // deliberately belongs to ANOTHER caderno — a global binding by slug would
    // grab that one first.
    $notebookB = Notebook::factory()->create(['public_token' => 'tok-dup-b']);
    DocumentationPage::factory()->for($notebookB)->create(['slug' => 'teste', 'documentation' => '# Do caderno B']);

    $notebookA = Notebook::factory()->create(['public_token' => 'tok-dup-a']);
    $pageA = DocumentationPage::factory()->for($notebookA)->create(['slug' => 'teste', 'documentation' => '# Do caderno A']);

    $this->get(route('public.docs.page', [$notebookA->public_token, $pageA]))
        ->assertOk()
        ->assertSee('<h1>Do caderno A', false);
});

it('404s a page that belongs to a different caderno', function () {
    $notebook = Notebook::factory()->create(['public_token' => 'tok-a']);
    $other = Notebook::factory()->create();
    $foreignPage = publicPage($other, '# Alheia');

    $this->get(route('public.docs.page', [$notebook->public_token, $foreignPage]))
        ->assertNotFound();
});

it('lists the solution pages in the public sidebar, and nothing else', function () {
    $notebook = Notebook::factory()->create(['public_token' => 'tok-side', 'name' => 'Minha Solução']);
    $page = publicPage($notebook, '# Visão geral');

    // A drawing the solution takes part in is deliberately NOT an entry here:
    // the public surface renders documentation, and a diagram carries none. It
    // reaches a visitor only as an image embedded in a page.
    $diagram = Diagram::factory()->create([
        'name'  => 'Integração Alfa',
        'chain' => ['nodes' => [['solution_id' => $notebook->id, 'label' => null]], 'edges' => []],
    ]);
    attachParticipants($diagram, [[$notebook, 0]]);

    $this->get(route('public.docs.notebook', $notebook->public_token))
        ->assertOk()
        ->assertSee('Minha Solução')
        ->assertSee($page->title)
        ->assertDontSee('Integração Alfa');
});

/*
|--------------------------------------------------------------------------
| Public media
|--------------------------------------------------------------------------
*/

it('serves owned docs media publicly and rewrites /files/ urls to the public route', function () {
    Storage::fake('public');
    $notebook = Notebook::factory()->create(['public_token' => 'tok-media']);
    $page = publicPage($notebook);
    $media = $page->addMedia(UploadedFile::fake()->image('d.png', 120, 80))->toMediaCollection('docs');

    $page->update(['documentation' => "<figure><img src=\"/files/{$media->id}\" alt=\"x\"></figure>"]);

    // The /files/ url is rewritten to the public route in the rendered HTML.
    $this->get(route('public.docs.notebook', $notebook->public_token))
        ->assertOk()
        ->assertSee(route('public.docs.file', [$notebook->public_token, $media->id]), false)
        ->assertDontSee('src="/files/', false);

    // And the public route serves the file without auth.
    $this->get(route('public.docs.file', [$notebook->public_token, $media->id]))
        ->assertOk()
        ->assertHeader('content-type', 'image/png');
});

it('404s public media that does not belong to the shared caderno', function () {
    Storage::fake('public');
    $notebook = Notebook::factory()->create(['public_token' => 'tok-a']);
    $other = Notebook::factory()->create(['public_token' => 'tok-b']);
    $otherPage = publicPage($other);
    $foreignMedia = $otherPage->addMedia(UploadedFile::fake()->image('o.png'))->toMediaCollection('docs');

    $this->get(route('public.docs.file', [$notebook->public_token, $foreignMedia->id]))
        ->assertNotFound();
});

it('lists sub-pages as navigation cards on the public documentation too', function () {
    // A visitor has only the side index otherwise, and an imported section page
    // carries no text of its own — so without these cards it is a dead end.
    $notebook = Notebook::factory()->create(['public_token' => 'tok-children']);
    $parent = publicPage($notebook, null);
    $parent->update(['title' => 'Camada Raw', 'slug' => 'camada-raw']);
    $child = DocumentationPage::factory()->childOf($parent)->create([
        'title' => 'Material', 'slug' => 'material', 'documentation' => '# M',
    ]);

    $response = $this->get(route('public.docs.page', ['tok-children', 'camada-raw']))
        ->assertOk();

    expect($response->getContent())
        ->toContain('Nesta seção')
        ->toContain(route('public.docs.page', ['tok-children', $child->slug]))
        ->not->toContain('Nenhuma documentação cadastrada');
});

it('prints the page title once when the text already opens with it', function () {
    // Every one of the 133 imported "Dados • BigQuery • GCP" pages starts with
    // `# <título>` — GitBook writes the title into the body — and the shell
    // printed its own above it, so each page said its name twice.
    $notebook = Notebook::factory()->create(['public_token' => 'tok-dedup']);
    $page = publicPage($notebook, "# Material\n\nCorpo.");
    $page->update(['title' => 'Material', 'slug' => 'material']);

    $content = $this->get(route('public.docs.page', ['tok-dedup', 'material']))
        ->assertOk()
        ->getContent();

    // Exactly one <h1> on the page. Counting the TAG, not the text: the
    // rendered heading also carries its `heading-permalink` anchor inside it.
    expect(substr_count($content, '<h1'))->toBe(1)
        ->and($content)->toContain('Material');
});

it('still prints the title when the text does not open with it', function () {
    $notebook = Notebook::factory()->create(['public_token' => 'tok-nodedup']);
    $page = publicPage($notebook, 'Só um parágrafo, sem heading.');
    $page->update(['title' => 'Material', 'slug' => 'material']);

    $content = $this->get(route('public.docs.page', ['tok-nodedup', 'material']))
        ->assertOk()
        ->getContent();

    // The shell supplies the only <h1> here — a page whose text has no heading
    // must still say what it is.
    expect(substr_count($content, '<h1'))->toBe(1)
        ->and($content)->toContain('Material');
});

it('sees through GitBook front matter when deciding the title is a duplicate', function () {
    // Plenty of imported pages carry a `---description---` block ahead of the
    // heading; a naive "starts with #" check would miss it and print both.
    $notebook = Notebook::factory()->create(['public_token' => 'tok-front']);
    $page = publicPage($notebook, "---\ndescription: Uma descrição\n---\n\n# Material\n\nCorpo.");
    $page->update(['title' => 'Material', 'slug' => 'material']);

    $content = $this->get(route('public.docs.page', ['tok-front', 'material']))
        ->assertOk()
        ->getContent();

    expect(substr_count($content, '<h1'))->toBe(1);
});

it('never hands a visitor the link to open a cited diagram', function () {
    // The canvas is behind auth. Showing the link to someone reading a magic
    // link is both a dead end — it lands them on the login screen — and a
    // disclosure: it tells them the drawing exists and what its slug is.
    $diagram = Diagram::factory()->create(['name' => 'SAP ↔ SVL']);
    $notebook = Notebook::factory()->create(['public_token' => 'tok-diag-guest']);
    $page = publicPage($notebook, "# Fluxo\n\n{% diagram slug=\"{$diagram->slug}\" %}");
    $page->update(['slug' => 'fluxo']);

    $content = $this->get(route('public.docs.page', ['tok-diag-guest', 'fluxo']))
        ->assertOk()
        ->getContent();

    // The card itself stays — the picture and the name are documentation.
    expect($content)
        ->toContain('ak-doc-diagram')
        ->toContain('SAP ↔ SVL')
        // …the editing affordance does not.
        ->not->toContain(route('diagrams.show', $diagram))
        ->not->toContain('Abrir diagrama');
});

it('still gives an authenticated reader the link to the canvas', function () {
    // The other half of the rule: withholding it from guests must not withhold
    // it from the people the citation exists for.
    $diagram = Diagram::factory()->create(['name' => 'SAP ↔ SVL']);
    $notebook = Notebook::factory()->create();
    $page = publicPage($notebook, "# Fluxo\n\n{% diagram slug=\"{$diagram->slug}\" %}");

    $this->actingAs(User::factory()->create()) // a viewer, not an admin
        ->get(route('notebooks.pages.edit', [$notebook, $page]))
        ->assertOk()
        ->assertSee(route('diagrams.show', $diagram), false)
        ->assertSee('Abrir diagrama');
});

it('keeps chrome out of the search index', function () {
    // The index is CACHED, so a render that varied by the viewer's auth state
    // would bake one audience's chrome into everybody's results — and "Abrir
    // diagrama" is a button, not something anyone should be able to search for.
    $diagram = Diagram::factory()->create(['name' => 'SAP ↔ SVL']);
    $notebook = Notebook::factory()->create();
    publicPage($notebook, "# Fluxo\n\n{% diagram slug=\"{$diagram->slug}\" %}");

    expect(app(DocumentationSearchService::class)->search($notebook, 'Abrir diagrama')['total'])
        ->toBe(0);
});

it('prints no eyebrow over either title', function () {
    // There were two — "DOCUMENTAÇÃO" over the caderno's name in the topbar and
    // "CADERNO" over the page title — and each labelled something the screen
    // already says. The names themselves stay; the small-caps words over them
    // do not.
    $notebook = Notebook::factory()->create(['name' => 'Dados • BigQuery', 'public_token' => 'tok-labels']);
    $page = publicPage($notebook, "# Material\n\nTexto.");
    $page->update(['title' => 'Material', 'slug' => 'material']);

    $content = $this->get(route('public.docs.page', ['tok-labels', 'material']))
        ->assertOk()
        ->getContent();

    // Both names are still on screen…
    expect($content)
        ->toContain('Dados • BigQuery')
        ->toContain('Material')
        // …and neither carries a label above it.
        ->not->toContain('>Documentação</p>')
        ->not->toContain('>Caderno</p>');
});

it('serves a cited diagram\'s picture to a visitor, through the token', function () {
    // Withholding the "Abrir diagrama" link was right; letting the PICTURE 302
    // to the login screen was not the same thing, and it left every citation on
    // a shared link showing a broken image.
    Storage::fake('public');
    $diagram = Diagram::factory()->create(['name' => 'SAP ↔ SVL']);
    $diagram->addMedia(UploadedFile::fake()->image('canvas.png'))->toMediaCollection(Diagram::DIAGRAM_COLLECTION);

    $notebook = Notebook::factory()->create(['public_token' => 'tok-pic']);
    $page = publicPage($notebook, "# Fluxo\n\n{% diagram slug=\"{$diagram->slug}\" %}");
    $page->update(['slug' => 'fluxo']);

    // The page points the image at the token-scoped route, never at the
    // authenticated one.
    $content = $this->get(route('public.docs.page', ['tok-pic', 'fluxo']))->assertOk()->getContent();

    expect($content)
        ->toContain(route('public.docs.diagram', ['tok-pic', $diagram->slug]))
        ->not->toContain(route('diagrams.picture.show', $diagram));

    // …and that route actually serves the bytes.
    $this->get(route('public.docs.diagram', ['tok-pic', $diagram]))
        ->assertOk()
        ->assertHeader('content-type', 'image/png');
});

it('404s the picture of a diagram this caderno never cited', function () {
    // Authorised by CITATION, not by the diagram: a valid token must not become
    // a way to walk the whole drawing catalog.
    Storage::fake('public');
    $cited = Diagram::factory()->create();
    $uncited = Diagram::factory()->create();
    $uncited->addMedia(UploadedFile::fake()->image('other.png'))->toMediaCollection(Diagram::DIAGRAM_COLLECTION);

    $notebook = Notebook::factory()->create(['public_token' => 'tok-uncited']);
    publicPage($notebook, "# Fluxo\n\n{% diagram slug=\"{$cited->slug}\" %}");

    $this->get(route('public.docs.diagram', ['tok-uncited', $uncited]))->assertNotFound();
});
