<?php

use App\Contracts\Documentable;
use App\Enums\UserRole;
use App\Models\DocumentationGroup;
use App\Models\DocumentationPage;
use App\Models\Solution;
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

/** A page sitting in a standalone group — where the GitBook import lands one. */
function groupPage(string $title = 'Visão geral', string $slug = 'visao-geral'): DocumentationPage
{
    $group = DocumentationGroup::factory()->create(['name' => 'Legado GitBook', 'slug' => 'legado-gitbook']);

    return DocumentationPage::factory()->create([
        'container_type' => DocumentationGroup::class,
        'container_id'   => $group->id,
        'title'          => $title,
        'slug'           => $slug,
        'position'       => 1,
    ]);
}

/*
|--------------------------------------------------------------------------
| Moving a page between containers
|--------------------------------------------------------------------------
*/

it('moves a page out of a group and into a solution', function () {
    $page = groupPage();
    $group = $page->container;
    $solution = Solution::factory()->create();

    $response = $this->actingAs(moveAdmin())
        ->patchJson(route('documentation.groups.pages.container', [$group, $page]), [
            'container' => 'solution:' . $solution->id,
        ])
        ->assertOk()
        ->assertJson(['type' => 'success', 'message' => 'Página movida.']);

    $page->refresh();
    expect($page->container_type)->toBe(Solution::class);
    expect($page->container_id)->toBe($solution->id);
    expect($group->pages()->count())->toBe(0);
    expect($solution->pages()->pluck('id')->all())->toBe([$page->id]);

    // The url changed container, so the response has to navigate — a slot swap
    // would leave the browser on a page that no longer exists there.
    expect($response->json('redirect'))->toBe(route('solutions.docs.page.edit', [$solution, $page]));
});

it('moves a page out of a solution and into a group', function () {
    $solution = Solution::factory()->create();
    $page = DocumentationPage::factory()->create([
        'container_type' => Solution::class,
        'container_id'   => $solution->id,
        'title'          => 'Processo de deploy',
        'slug'           => 'processo-de-deploy',
    ]);
    $group = DocumentationGroup::factory()->create();

    $response = $this->actingAs(moveAdmin())
        ->patchJson(route('solutions.docs.pages.container', [$solution, $page]), [
            'container' => 'group:' . $group->id,
        ])
        ->assertOk();

    $page->refresh();
    expect($page->container_type)->toBe(DocumentationGroup::class);
    expect($page->container_id)->toBe($group->id);
    expect($response->json('redirect'))->toBe(route('documentation.groups.pages.edit', [$group, $page]));
});

it('lands the page at the end of the destination order, not at its old position', function () {
    $page = groupPage();
    $solution = Solution::factory()->create();
    DocumentationPage::factory()->count(3)->sequence(
        ['position' => 1], ['position' => 2], ['position' => 3],
    )->create([
        'container_type' => Solution::class,
        'container_id'   => $solution->id,
    ]);

    $this->actingAs(moveAdmin())
        ->patchJson(route('documentation.groups.pages.container', [$page->container, $page]), [
            'container' => 'solution:' . $solution->id,
        ])
        ->assertOk();

    expect($page->refresh()->position)->toBe(4);
    expect($solution->pages()->get()->last()->id)->toBe($page->id);
});

it('keeps the slug when it is free in the destination', function () {
    $page = groupPage(slug: 'visao-geral');
    $solution = Solution::factory()->create();

    $this->actingAs(moveAdmin())
        ->patchJson(route('documentation.groups.pages.container', [$page->container, $page]), [
            'container' => 'solution:' . $solution->id,
        ])
        ->assertOk();

    expect($page->refresh()->slug)->toBe('visao-geral');
});

it('suffixes the slug when the destination already has one like it', function () {
    $page = groupPage(slug: 'visao-geral');
    $solution = Solution::factory()->create();
    DocumentationPage::factory()->create([
        'container_type' => Solution::class,
        'container_id'   => $solution->id,
        'slug'           => 'visao-geral',
    ]);

    $this->actingAs(moveAdmin())
        ->patchJson(route('documentation.groups.pages.container', [$page->container, $page]), [
            'container' => 'solution:' . $solution->id,
        ])
        ->assertOk();

    // The unique index is (container_type, container_id, slug) — without this
    // the move would hit it and 500.
    expect($page->refresh()->slug)->toBe('visao-geral-2');
});

it('carries the content and its embedded media across the move', function () {
    Storage::fake('public');
    $page = groupPage();
    $group = $page->container;
    $solution = Solution::factory()->create();

    $media = $this->actingAs(moveAdmin())
        ->post(route('documentation.groups.pages.media', [$group, $page]), [
            'file' => UploadedFile::fake()->image('diagrama.png'),
        ])
        ->assertOk()
        ->json('file.mediaId');

    $page->update(['documentation' => '<figure><img src="/files/' . $media . '" alt=""><figcaption></figcaption></figure>']);

    $this->actingAs(moveAdmin())
        ->patchJson(route('documentation.groups.pages.container', [$group, $page]), [
            'container' => 'solution:' . $solution->id,
        ])
        ->assertOk();

    $page->refresh();
    expect($page->documentation)->toContain('/files/' . $media);
    expect($page->getMedia(Documentable::DOCS_COLLECTION))->toHaveCount(1);

    // MediaController authorizes on the collection, not the container, so the
    // link inside the content keeps resolving after the move.
    $this->actingAs(moveAdmin())->get('/files/' . $media)->assertOk();
});

/*
|--------------------------------------------------------------------------
| What it refuses
|--------------------------------------------------------------------------
*/

it('refuses to move a page to the container it is already in', function () {
    $page = groupPage();
    $group = $page->container;

    $response = $this->actingAs(moveAdmin())
        ->patchJson(route('documentation.groups.pages.container', [$group, $page]), [
            'container' => 'group:' . $group->id,
        ])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    expect($response->json('message'))->toContain('já está neste destino');
});

it('rejects a destination that does not exist', function () {
    $page = groupPage();

    $response = $this->actingAs(moveAdmin())
        ->patchJson(route('documentation.groups.pages.container', [$page->container, $page]), [
            'container' => 'solution:99999',
        ])
        ->assertStatus(422);

    expect($response->json('message'))->toContain('não existe mais');
});

it('rejects a destination of an unknown type', function () {
    $page = groupPage();

    $this->actingAs(moveAdmin())
        ->patchJson(route('documentation.groups.pages.container', [$page->container, $page]), [
            'container' => 'company:1',
        ])
        ->assertStatus(422);
});

it('forbids a viewer from moving a page', function () {
    $page = groupPage();
    $solution = Solution::factory()->create();
    $viewer = User::factory()->create(['role' => UserRole::Viewer->value]);

    $this->actingAs($viewer)
        ->patchJson(route('documentation.groups.pages.container', [$page->container, $page]), [
            'container' => 'solution:' . $solution->id,
        ])
        ->assertStatus(403);

    expect($page->refresh()->container_type)->toBe(DocumentationGroup::class);
});

it('404s when the page does not belong to the container in the url', function () {
    $page = groupPage();
    $otherGroup = DocumentationGroup::factory()->create();
    $solution = Solution::factory()->create();

    // scopeBindings: {page} resolves through DocumentationGroup::pages().
    $this->actingAs(moveAdmin())
        ->patchJson(route('documentation.groups.pages.container', [$otherGroup, $page]), [
            'container' => 'solution:' . $solution->id,
        ])
        ->assertStatus(404);
});

/*
|--------------------------------------------------------------------------
| The destination list and the rail
|--------------------------------------------------------------------------
*/

it('offers every other container as a destination, never the current one', function () {
    $group = DocumentationGroup::factory()->create(['name' => 'Atual']);
    $otherGroup = DocumentationGroup::factory()->create(['name' => 'Outro']);
    $solution = Solution::factory()->create(['name' => 'Uma solução']);

    $destinations = app(DocumentationPageService::class)->destinationsFor($group);

    expect(array_keys($destinations))->toBe(['Soluções', 'Grupos']);
    expect($destinations['Soluções'])->toBe([['value' => 'solution:' . $solution->id, 'label' => 'Uma solução']]);
    expect($destinations['Grupos'])->toBe([['value' => 'group:' . $otherGroup->id, 'label' => 'Outro']]);
});

it('omits an empty optgroup instead of rendering an empty one', function () {
    $group = DocumentationGroup::factory()->create();

    // No solutions and no other group — nothing to offer at all, which is what
    // hides the "Mover para…" entry in the rail.
    expect(app(DocumentationPageService::class)->destinationsFor($group))->toBe([]);
});

it('renders the move-to-container form in the pages rail', function () {
    $page = groupPage();
    $solution = Solution::factory()->create(['name' => 'Solução destino']);

    $response = $this->actingAs(moveAdmin())
        ->get(route('documentation.groups.pages.edit', [$page->container, $page]))
        ->assertOk();

    $response->assertSee('Mover para', escape: false)
        ->assertSee('doc-page-container-0', escape: false)
        ->assertSee(route('documentation.groups.pages.container', [$page->container, $page]), escape: false)
        ->assertSee('<optgroup label="Soluções">', escape: false)
        ->assertSee('value="solution:' . $solution->id . '"', escape: false)
        ->assertSee('Solução destino', escape: false);
});

it('hides the move entry when there is nowhere to move the page to', function () {
    $page = groupPage();

    $this->actingAs(moveAdmin())
        ->get(route('documentation.groups.pages.edit', [$page->container, $page]))
        ->assertOk()
        ->assertDontSee('doc-page-container-0', escape: false);
});
