<?php

use App\Contracts\Documentable;
use App\Enums\UserRole;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\User;
use App\Services\DocumentationPageService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

function moveAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

/** A page sitting in an imported caderno — where the GitBook import lands one. */
function importedPage(string $title = 'Visão geral', string $slug = 'visao-geral'): DocumentationPage
{
    $notebook = Notebook::factory()->create(['name' => 'Legado GitBook', 'slug' => 'legado-gitbook']);

    return DocumentationPage::factory()->for($notebook)->create([
        'title'    => $title,
        'slug'     => $slug,
        'position' => 1,
    ]);
}

/*
|--------------------------------------------------------------------------
| Moving a page between cadernos
|--------------------------------------------------------------------------
*/

it('moves a page out of one caderno and into another', function () {
    $page = importedPage();
    $source = $page->notebook;
    $destination = Notebook::factory()->create();

    $response = $this->actingAs(moveAdmin())
        ->patchJson(route('notebooks.pages.notebook', [$source, $page]), [
            'notebook' => $destination->id,
        ])
        ->assertOk()
        ->assertJson(['type' => 'success', 'message' => 'Página movida.']);

    $page->refresh();
    expect($page->notebook_id)->toBe($destination->id);
    expect($source->pages()->count())->toBe(0);
    expect($destination->pages()->pluck('id')->all())->toBe([$page->id]);

    // The url changed caderno, so the response has to navigate — a slot swap
    // would leave the browser on a page that no longer exists there.
    expect($response->json('redirect'))->toBe(route('notebooks.pages.edit', [$destination, $page]));
});

it('lands the page at the end of the destination order, not at its old position', function () {
    $page = importedPage();
    $destination = Notebook::factory()->create();
    DocumentationPage::factory()->count(3)->sequence(
        ['position' => 1], ['position' => 2], ['position' => 3],
    )->for($destination)->create();

    $this->actingAs(moveAdmin())
        ->patchJson(route('notebooks.pages.notebook', [$page->notebook, $page]), [
            'notebook' => $destination->id,
        ])
        ->assertOk();

    expect($page->refresh()->position)->toBe(4);
    expect($destination->pages()->get()->last()->id)->toBe($page->id);
});

it('keeps the slug when it is free in the destination', function () {
    $page = importedPage(slug: 'visao-geral');
    $destination = Notebook::factory()->create();

    $this->actingAs(moveAdmin())
        ->patchJson(route('notebooks.pages.notebook', [$page->notebook, $page]), [
            'notebook' => $destination->id,
        ])
        ->assertOk();

    expect($page->refresh()->slug)->toBe('visao-geral');
});

it('suffixes the slug when the destination already has one like it', function () {
    $page = importedPage(slug: 'visao-geral');
    $destination = Notebook::factory()->create();
    DocumentationPage::factory()->for($destination)->create(['slug' => 'visao-geral']);

    $this->actingAs(moveAdmin())
        ->patchJson(route('notebooks.pages.notebook', [$page->notebook, $page]), [
            'notebook' => $destination->id,
        ])
        ->assertOk();

    // The unique index is (notebook_id, slug) — without this the move would hit
    // it and 500.
    expect($page->refresh()->slug)->toBe('visao-geral-2');
});

it('carries the content and its embedded media across the move', function () {
    Storage::fake('public');
    $page = importedPage();
    $source = $page->notebook;
    $destination = Notebook::factory()->create();

    $media = $this->actingAs(moveAdmin())
        ->post(route('notebooks.pages.media', [$source, $page]), [
            'file' => UploadedFile::fake()->image('diagrama.png'),
        ])
        ->assertOk()
        ->json('file.mediaId');

    $page->update(['documentation' => '<figure><img src="/files/' . $media . '" alt=""><figcaption></figcaption></figure>']);

    $this->actingAs(moveAdmin())
        ->patchJson(route('notebooks.pages.notebook', [$source, $page]), [
            'notebook' => $destination->id,
        ])
        ->assertOk();

    $page->refresh();
    expect($page->documentation)->toContain('/files/' . $media);
    expect($page->getMedia(Documentable::DOCS_COLLECTION))->toHaveCount(1);

    // MediaController authorizes on the collection, not the caderno, so the
    // link inside the content keeps resolving after the move.
    $this->actingAs(moveAdmin())->get('/files/' . $media)->assertOk();
});

/*
|--------------------------------------------------------------------------
| What it refuses
|--------------------------------------------------------------------------
*/

it('refuses to move a page to the caderno it is already in', function () {
    $page = importedPage();
    $notebook = $page->notebook;

    $response = $this->actingAs(moveAdmin())
        ->patchJson(route('notebooks.pages.notebook', [$notebook, $page]), [
            'notebook' => $notebook->id,
        ])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    expect($response->json('message'))->toContain('já está neste caderno');
});

it('rejects a destination that does not exist', function () {
    $page = importedPage();

    $response = $this->actingAs(moveAdmin())
        ->patchJson(route('notebooks.pages.notebook', [$page->notebook, $page]), [
            'notebook' => 99999,
        ])
        ->assertStatus(422);

    expect($response->json('message'))->toContain('não existe mais');
});

it('rejects a destination that is not an id at all', function () {
    $page = importedPage();

    $this->actingAs(moveAdmin())
        ->patchJson(route('notebooks.pages.notebook', [$page->notebook, $page]), [
            'notebook' => 'company:1',
        ])
        ->assertStatus(422);
});

it('forbids a viewer from moving a page', function () {
    $page = importedPage();
    $source = $page->notebook;
    $destination = Notebook::factory()->create();
    $viewer = User::factory()->create(['role' => UserRole::Viewer->value]);

    $this->actingAs($viewer)
        ->patchJson(route('notebooks.pages.notebook', [$source, $page]), [
            'notebook' => $destination->id,
        ])
        ->assertStatus(403);

    expect($page->refresh()->notebook_id)->toBe($source->id);
});

it('404s when the page does not belong to the caderno in the url', function () {
    $page = importedPage();
    $otherNotebook = Notebook::factory()->create();
    $destination = Notebook::factory()->create();

    // scopeBindings: {page} resolves through Notebook::pages().
    $this->actingAs(moveAdmin())
        ->patchJson(route('notebooks.pages.notebook', [$otherNotebook, $page]), [
            'notebook' => $destination->id,
        ])
        ->assertStatus(404);
});

/*
|--------------------------------------------------------------------------
| The destination list and the rail
|--------------------------------------------------------------------------
*/

it('offers every other caderno as a destination, never the current one', function () {
    $notebook = Notebook::factory()->create(['name' => 'Atual']);
    $other = Notebook::factory()->create(['name' => 'Outro']);

    // A flat list now — it was `<optgroup>`s keyed by "Soluções"/"Grupos" while
    // a destination could be either kind of container.
    expect(app(DocumentationPageService::class)->destinationsFor($notebook))
        ->toBe([['value' => $other->id, 'label' => 'Outro']]);
});

it('offers nothing when there is no other caderno to move to', function () {
    $notebook = Notebook::factory()->create();

    // Nothing to offer at all, which is what hides the "Mover para…" entry in
    // the rail.
    expect(app(DocumentationPageService::class)->destinationsFor($notebook))->toBe([]);
});

it('renders the move-to-caderno form in the pages rail', function () {
    $page = importedPage();
    $destination = Notebook::factory()->create(['name' => 'Caderno destino']);

    $response = $this->actingAs(moveAdmin())
        ->get(route('notebooks.pages.edit', [$page->notebook, $page]))
        ->assertOk();

    $response->assertSee('Mover para', escape: false)
        ->assertSee('doc-page-notebook-0', escape: false)
        ->assertSee(route('notebooks.pages.notebook', [$page->notebook, $page]), escape: false)
        ->assertSee('value="' . $destination->id . '"', escape: false)
        ->assertSee('Caderno destino', escape: false);
});

it('hides the move entry when there is nowhere to move the page to', function () {
    $page = importedPage();

    $this->actingAs(moveAdmin())
        ->get(route('notebooks.pages.edit', [$page->notebook, $page]))
        ->assertOk()
        ->assertDontSee('doc-page-notebook-0', escape: false);
});
