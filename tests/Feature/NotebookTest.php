<?php

use App\Enums\UserRole;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\Solution;
use App\Models\User;
use App\View\Components\Notebooks\Index as NotebooksIndex;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

function notebookAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

function notebookEditor(): User
{
    return User::factory()->create(['role' => UserRole::Writer->value]);
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

it('lets an admin delete a caderno, cascading its whole page tree', function () {
    $notebook = Notebook::factory()->create();

    // Three levels deep on purpose: a delete that only reached the roots would
    // pass with one page and leave the rest of the tree orphaned.
    $root = DocumentationPage::factory()->for($notebook)->create(['title' => 'Raiz']);
    $child = DocumentationPage::factory()->for($notebook)->create(['title' => 'Filha']);
    $child->parent()->associate($root)->save();
    $grandchild = DocumentationPage::factory()->for($notebook)->create(['title' => 'Neta']);
    $grandchild->parent()->associate($child)->save();

    $this->actingAs(notebookAdmin())
        ->deleteJson(route('notebooks.destroy', $notebook))
        ->assertOk()
        ->assertJson(['type' => 'success']);

    $this->assertModelMissing($notebook);
    $this->assertModelMissing($root);
    $this->assertModelMissing($child);
    $this->assertModelMissing($grandchild);
});

/*
|--------------------------------------------------------------------------
| …from the CATALOG CARD, which is where the delete became reachable
|--------------------------------------------------------------------------
|
| `notebooks.destroy` existed with no caller at all — it appeared in no view, so
| the only way to remove a caderno was the database.
|
*/

it('answers a delete with the catalog slot, not a redirect', function () {
    // From the catalog, a redirect to the catalog is a full reload of the page
    // you are already on — and it throws away the filters the URL shows.
    $notebook = Notebook::factory()->create();
    Notebook::factory()->create(['name' => 'Fica']);

    $response = $this->actingAs(notebookAdmin())
        ->deleteJson(route('notebooks.destroy', $notebook))
        ->assertOk();

    expect($response->json('redirect'))->toBeNull()
        ->and($response->json('updatableSlots.0.id'))->toBe(NotebooksIndex::DOM_ID)
        ->and($response->json('updatableSlots.0.content'))->toContain('Fica');
});

it('rebuilds the slot with the filters that were active', function () {
    $kept = Notebook::factory()->create(['name' => 'Integração SAP']);
    $target = Notebook::factory()->create(['name' => 'Integração antiga']);
    Notebook::factory()->create(['name' => 'Nada a ver com o termo']);

    $response = $this->actingAs(notebookAdmin())
        ->deleteJson(route('notebooks.destroy', [
            'notebook' => $target,
            'filter'   => ['search' => 'integracao'],
        ]))
        ->assertOk();

    expect($response->json('updatableSlots.0.content'))
        ->toContain($kept->name)
        ->not->toContain('Nada a ver com o termo');
});

it('names the caderno in the message, since the card it was on is gone', function () {
    $notebook = Notebook::factory()->create(['name' => 'Caderno do IAM']);

    $response = $this->actingAs(notebookAdmin())
        ->deleteJson(route('notebooks.destroy', $notebook))
        ->assertOk();

    expect($response->json('message'))->toContain('Caderno do IAM');
});

it('refuses a delete to an editor, who may write every page in it', function () {
    $notebook = Notebook::factory()->create();

    $this->actingAs(notebookEditor())
        ->deleteJson(route('notebooks.destroy', $notebook))
        ->assertForbidden();

    $this->assertModelExists($notebook);
});

it('offers the trash to an admin and withholds it from an editor', function () {
    // The affordance and the rule have to agree: a button that refuses is a
    // worse answer than a button that is not there.
    //
    // Asserted on the trash's own markers, deliberately NOT on the delete URL:
    // `notebooks.destroy` is DELETE on `notebooks/{notebook}`, the same path
    // every card title links to, so a URL assertion passes for both roles and
    // proves nothing.
    $notebook = Notebook::factory()->create();

    $this->actingAs(notebookAdmin());
    $asAdmin = (string) (new NotebooksIndex)->render()->render();

    $this->actingAs(notebookEditor());
    $asEditor = (string) (new NotebooksIndex)->render()->render();

    expect($asAdmin)->toContain('aria-label="Excluir caderno"')
        ->and($asAdmin)->toContain('data-ak-confirm')
        ->and($asEditor)->not->toContain('aria-label="Excluir caderno"')
        ->and($asEditor)->not->toContain('data-ak-confirm')
        // The editor still gets the pencil — this is a split, not a lockout.
        ->and($asEditor)->toContain(route('notebooks.panel.edit', $notebook));
});

it('states what a delete costs before it happens', function () {
    // A confirm naming only the caderno reads the same whether it holds nothing
    // or holds an imported GitBook space, and the page tree is what separates
    // them. Both consequences are COUNTED, never guessed.
    $notebook = Notebook::factory()->create(['name' => 'Com páginas']);
    DocumentationPage::factory()->count(3)->for($notebook)->create();
    $notebook->update(['public_token' => 'abc123abc123']);

    Notebook::factory()->create(['name' => 'Vazio']);

    $this->actingAs(notebookAdmin());
    $html = (string) (new NotebooksIndex)->render()->render();

    expect($html)->toContain('As 3 páginas dele vão junto.')
        ->and($html)->toContain('O link público para de funcionar.')
        // The empty one says neither — there is nothing to warn about.
        ->and($html)->toContain('Excluir o caderno &quot;Vazio&quot;? Isso não pode ser desfeito.');
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

    // The chips' own slot, the rail's "Esse caderno contempla…" sentence, and
    // one card per solution touched — dropped, kept and added alike. The
    // sentence is the reader's only statement of the link now that the top
    // bar's icon is gone, so leaving it out would show yesterday's systems
    // beside today's pages until the next full page load.
    expect($response->json('updatableSlots'))->toHaveCount(5)
        ->and(collect($response->json('updatableSlots'))->pluck('id')->take(2)->all())
        ->toBe(['notebook-solutions-slot', 'notebook-documented-systems-slot']);

    // …and the dropped one's card no longer names the caderno.
    $this->actingAs(notebookAdmin())
        ->get(route('solutions.show', $dropped))
        ->assertOk()
        ->assertDontSee($notebook->name);
});

it('states the systems a caderno documents in the reading rail, not in the toolbar', function () {
    // The link moved out of the top bar: what a caderno documents is a FACT
    // about the page being read, so the rail says it in words and the sentence
    // is what opens the editor for it. The icon that used to open that popover
    // is gone — and it is the aria-label, not the popover, that has to be
    // absent: the popover itself simply moved.
    $notebook = Notebook::factory()->create(['name' => 'Provisionamento']);
    $page = DocumentationPage::factory()->for($notebook)->create();
    $notebook->solutions()->attach([
        Solution::factory()->create(['name' => 'Alfa'])->id,
        Solution::factory()->create(['name' => 'Beta'])->id,
    ]);

    $this->actingAs(notebookAdmin())
        ->get(route('notebooks.pages.edit', [$notebook, $page]))
        ->assertOk()
        ->assertSee('Esse caderno contempla o(s) sistema(s):')
        ->assertSee('Alfa, Beta')
        ->assertSee('notebook-documented-systems-slot')
        ->assertSee('docs-solutions-dropdown')          // the popover came along
        ->assertDontSee('Soluções documentadas"');      // …its top-bar icon did not
});

it('says so plainly when a caderno documents no system at all', function () {
    // Zero is a normal state for a caderno (a cross-cutting process, a freshly
    // imported space nobody has filed), so the rail states it rather than
    // rendering an empty sentence with a dangling colon.
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create();

    $this->actingAs(notebookAdmin())
        ->get(route('notebooks.pages.edit', [$notebook, $page]))
        ->assertOk()
        ->assertSee('Esse caderno ainda não contempla nenhum sistema.', false);
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
