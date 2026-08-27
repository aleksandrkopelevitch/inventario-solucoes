<?php

use App\Enums\UserRole;
use App\Models\Diagram;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\User;
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
