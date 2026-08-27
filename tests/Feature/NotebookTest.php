<?php

use App\Enums\UserRole;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\Solution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

function notebookAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

/*
|--------------------------------------------------------------------------
| Caderno — CRUD (admin-only)
|--------------------------------------------------------------------------
*/

it('lets an admin create a caderno and opens its first page automatically', function () {
    $response = $this->actingAs(notebookAdmin())
        ->postJson(route('notebooks.store'), ['name' => 'Integrações Leo'])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    $notebook = Notebook::where('name', 'Integrações Leo')->sole();
    expect($response->json('redirect'))->toBe(route('notebooks.show', $notebook));

    $this->actingAs(notebookAdmin())
        ->get(route('notebooks.show', $notebook))
        ->assertRedirect();

    $page = $notebook->pages()->sole();
    expect($page->title)->toBe('Página inicial');
});

it('never creates a page for a viewer browsing an empty caderno, sending them back to the catalog', function () {
    // The catalog links here for empty cadernos, and a GET must not write.
    $notebook = Notebook::factory()->create();

    $this->actingAs(User::factory()->create()) // viewer
        ->get(route('notebooks.show', $notebook))
        ->assertRedirect(route('notebooks.index'));

    expect($notebook->pages()->count())->toBe(0);
});

it('forbids a viewer from creating a caderno', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('notebooks.store'), ['name' => 'X'])
        ->assertForbidden();

    expect(Notebook::count())->toBe(0);
});

it('lets an admin rename a caderno without changing its slug', function () {
    $notebook = Notebook::factory()->create();
    $originalSlug = $notebook->slug;

    $response = $this->actingAs(notebookAdmin())
        ->patchJson(route('notebooks.update', $notebook), ['name' => 'Novo nome'])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($notebook->fresh())->name->toBe('Novo nome')->slug->toBe($originalSlug)
        ->and($response->json('updatableSlots.0.id'))->toBe('notebooks-index-slot');
});

it('lets an admin delete a caderno, cascading its pages', function () {
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create();

    $this->actingAs(notebookAdmin())
        ->deleteJson(route('notebooks.destroy', $notebook))
        ->assertOk()
        ->assertJson(['type' => 'success']);

    $this->assertModelMissing($notebook);
    $this->assertModelMissing($page);
});

it('forbids a viewer from renaming or deleting a caderno', function () {
    $notebook = Notebook::factory()->create();

    $this->actingAs(User::factory()->create())
        ->patchJson(route('notebooks.update', $notebook), ['name' => 'X'])
        ->assertForbidden();

    $this->actingAs(User::factory()->create())
        ->deleteJson(route('notebooks.destroy', $notebook))
        ->assertForbidden();

    expect($notebook->fresh())->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Caderno pages — create / rename / move / delete / media
|--------------------------------------------------------------------------
*/

it('lets an admin create, rename, move and delete pages inside a caderno', function () {
    $notebook = Notebook::factory()->create();
    $first = DocumentationPage::factory()->for($notebook)->create(['position' => 0]);

    $store = $this->actingAs(notebookAdmin())
        ->postJson(route('notebooks.pages.store', $notebook), ['title' => 'SAP S/4'])
        ->assertOk();

    $second = $notebook->pages()->where('title', 'SAP S/4')->sole();
    expect($store->json('redirect'))->toBe(route('notebooks.pages.edit', [$notebook, $second]));

    $originalSlug = $second->slug;
    $this->actingAs(notebookAdmin())
        ->patchJson(route('notebooks.pages.rename', [$notebook, $second]), ['title' => 'SAP S/4HANA'])
        ->assertOk();
    expect($second->fresh())->title->toBe('SAP S/4HANA')->slug->toBe($originalSlug);

    $this->actingAs(notebookAdmin())
        ->patchJson(route('notebooks.pages.move', [$notebook, $second]), ['direction' => 'up'])
        ->assertOk();
    expect($second->fresh()->position)->toBe(0)
        ->and($first->fresh()->position)->toBe(1);

    $destroy = $this->actingAs(notebookAdmin())
        ->deleteJson(route('notebooks.pages.destroy', [$notebook, $first]))
        ->assertOk();
    $this->assertModelMissing($first);
    expect($destroy->json('redirect'))->toBe(route('notebooks.pages.edit', [$notebook, $second]));
});

it('links a page back to the cadernos catalog and lists it in the pages rail', function () {
    $notebook = Notebook::factory()->create(['name' => 'Integrações Leo']);
    $page = DocumentationPage::factory()->for($notebook)->create(['title' => 'SAP S/4']);

    $response = $this->actingAs(notebookAdmin())
        ->get(route('notebooks.pages.edit', [$notebook, $page]))
        ->assertOk();

    expect($response->getContent())
        // Top-bar breadcrumb points at the cadernos catalog.
        ->toContain('href="' . route('notebooks.index') . '"')
        // The current page is listed (and linked) in the collapsible pages rail.
        ->toMatch('/>\s*SAP S\/4\s*<\/a>/');
});

it('saves a page documentation and shows the read-only render to a viewer', function () {
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create();

    $this->actingAs(notebookAdmin())
        ->patchJson(route('notebooks.pages.update', [$notebook, $page]), ['documentation' => '# Doc do caderno'])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($page->fresh()->documentation)->toBe('# Doc do caderno');

    $this->actingAs(User::factory()->create())
        ->get(route('notebooks.pages.edit', [$notebook, $page]))
        ->assertOk()
        ->assertSee('<h1>Doc do caderno', false)
        ->assertDontSee('data-ak-docs-editor', false);
});

it('404s a page that does not belong to the caderno in the url', function () {
    $notebook = Notebook::factory()->create();
    $other = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($other)->create();

    $this->actingAs(notebookAdmin())
        ->get(route('notebooks.pages.edit', [$notebook, $page]))
        ->assertNotFound();
});

it('lets a caderno document several solutions at once', function () {
    // The point of the whole module: one body of text, read from every system
    // it describes.
    $notebook = Notebook::factory()->create();
    $solutions = Solution::factory()->count(3)->create();

    $this->actingAs(notebookAdmin())
        ->patchJson(route('notebooks.solutions', $notebook), [
            'solutions' => $solutions->map(fn (Solution $s) => ['value' => $s->id, 'label' => $s->name])->all(),
        ])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($notebook->solutions()->pluck('solutions.id')->sort()->values()->all())
        ->toBe($solutions->pluck('id')->sort()->values()->all());

    // …and each of those solutions reaches the caderno back.
    expect($solutions->first()->notebooks()->pluck('notebooks.id')->all())->toBe([$notebook->id]);
});

it('refreshes the card of every solution a link touched, including the ones removed', function () {
    // Linking is the one mutation whose effect shows up on a screen the user
    // isn't looking at — the "Cadernos" card on each solution's detail page. A
    // solution just UNLINKED needs it as much as one just linked: its card has
    // to stop showing this caderno.
    $notebook = Notebook::factory()->create();
    $dropped = Solution::factory()->create();
    $kept = Solution::factory()->create();
    $added = Solution::factory()->create();
    $notebook->solutions()->attach([$dropped->id, $kept->id]);

    $response = $this->actingAs(notebookAdmin())
        ->patchJson(route('notebooks.solutions', $notebook), [
            'solutions' => [
                ['value' => $kept->id, 'label' => $kept->name],
                ['value' => $added->id, 'label' => $added->name],
            ],
        ])
        ->assertOk();

    // The chips' own slot, plus one card per solution touched — dropped, kept
    // and added alike.
    expect($response->json('updatableSlots'))->toHaveCount(4)
        ->and($response->json('updatableSlots.0.id'))->toBe('notebook-solutions-slot');

    // …and the dropped one's card no longer names the caderno.
    $this->actingAs(notebookAdmin())
        ->get(route('solutions.show', $dropped))
        ->assertOk()
        ->assertDontSee($notebook->name);
});

it('unlinks every solution when the chips field is cleared', function () {
    // `x-forms.chips` submits NOTHING when its last chip is removed, so the
    // absent key has to mean the empty set — otherwise removing the last link
    // silently never persists.
    $notebook = Notebook::factory()->create();
    $notebook->solutions()->attach(Solution::factory()->count(2)->create());

    $this->actingAs(notebookAdmin())
        ->patchJson(route('notebooks.solutions', $notebook), [])
        ->assertOk();

    expect($notebook->solutions()->count())->toBe(0);
});

it('forbids a viewer from linking solutions to a caderno', function () {
    $notebook = Notebook::factory()->create();
    $solution = Solution::factory()->create();

    $this->actingAs(User::factory()->create())
        ->patchJson(route('notebooks.solutions', $notebook), [
            'solutions' => [['value' => $solution->id, 'label' => $solution->name]],
        ])
        ->assertForbidden();

    expect($notebook->solutions()->count())->toBe(0);
});

it('uploads media for a page', function () {
    Storage::fake('public');
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create();

    $response = $this->actingAs(notebookAdmin())
        ->post(route('notebooks.pages.media', [$notebook, $page]), [
            'file' => UploadedFile::fake()->image('diagrama.png'),
        ])
        ->assertOk()
        ->assertJson(['success' => 1]);

    expect($page->fresh()->getMedia('docs'))->toHaveCount(1)
        ->and($response->json('file.url'))->toContain('/files/' . $response->json('file.mediaId'));
});

it('forbids a viewer from creating or uploading media to a page', function () {
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create();

    $this->actingAs(User::factory()->create())
        ->postJson(route('notebooks.pages.store', $notebook), ['title' => 'X'])
        ->assertForbidden();

    $this->actingAs(User::factory()->create())
        ->post(route('notebooks.pages.media', [$notebook, $page]), [
            'file' => UploadedFile::fake()->image('x.png'),
        ])
        ->assertForbidden();
});

it('renames the caderno from the rail header and hands the rail back', function () {
    // The header is where a caderno is renamed — it is its own page, so there
    // is no ↗ to somewhere else. `?page=` tells the endpoint which page the
    // rail is showing, so it can rebuild it around the right active row.
    $notebook = Notebook::factory()->create(['name' => 'Antigo']);
    $page = DocumentationPage::factory()->for($notebook)->create(['title' => 'Visão geral']);

    $response = $this->actingAs(notebookAdmin())
        ->patchJson(route('notebooks.update', ['notebook' => $notebook, 'page' => $page->slug]), ['name' => 'Novo'])
        ->assertOk();

    expect($notebook->fresh()->name)->toBe('Novo');

    $rail = collect($response->json('updatableSlots'))->firstWhere('id', 'documentation-pages-nav-slot');
    expect($rail)->not->toBeNull()
        ->and($rail['content'])->toContain('Novo')
        // …and the ↗ that used to sit beside the name is gone.
        ->and($rail['content'])->not->toContain('data-ak-inline-edit-link');
});

it('renames a page in place, refreshing both the top bar and the rail', function () {
    // A rename never changes the slug, so there is nothing to navigate to —
    // but the name shows in two places that are not in the same subtree.
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create(['title' => 'Antigo', 'slug' => 'antigo']);

    $response = $this->actingAs(notebookAdmin())
        ->patchJson(route('notebooks.pages.rename', [$notebook, $page]), ['title' => 'Novo título'])
        ->assertOk();

    expect($page->fresh())->title->toBe('Novo título')->slug->toBe('antigo');

    $ids = collect($response->json('updatableSlots'))->pluck('id');
    expect($ids)->toContain('documentation-page-title-slot')
        ->and($ids)->toContain('documentation-pages-nav-slot')
        // No redirect: the URL still points at this page.
        ->and($response->json('redirect'))->toBeNull();
});

it('offers the caderno name and the page title as inline editors', function () {
    $notebook = Notebook::factory()->create(['name' => 'Meu caderno']);
    $page = DocumentationPage::factory()->for($notebook)->create(['title' => 'Visão geral']);

    $content = $this->actingAs(notebookAdmin())
        ->get(route('notebooks.pages.edit', [$notebook, $page]))
        ->assertOk()
        ->getContent();

    expect($content)
        ->toContain('data-ak-inline-edit-field="name"')
        ->toContain('data-ak-inline-edit-field="title"')
        ->toContain('id="documentation-page-title-slot"');
});
