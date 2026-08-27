<?php

use App\Enums\UserRole;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\User;
use App\Services\DocumentationPageService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

function treeAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

/** A root page of `$notebook`, at the given position in the root list. */
function rootPage(Notebook $notebook, string $title, int $position = 1): DocumentationPage
{
    return DocumentationPage::factory()->for($notebook)->create([
        'title'    => $title,
        'slug'     => Str::slug($title),
        'position' => $position,
    ]);
}

/*
|--------------------------------------------------------------------------
| Creating a subpage
|--------------------------------------------------------------------------
*/

it('creates a subpage under an existing page', function () {
    $notebook = Notebook::factory()->create();
    $parent = rootPage($notebook, 'Operação');

    $response = $this->actingAs(treeAdmin())
        ->postJson(route('notebooks.pages.store', $notebook), [
            'title'  => 'Rotina de backup',
            'parent' => $parent->id,
        ])
        ->assertOk()
        ->assertJson(['type' => 'success', 'message' => 'Página criada.']);

    $child = DocumentationPage::where('title', 'Rotina de backup')->firstOrFail();

    expect($child->parent_id)->toBe($parent->id)
        ->and($child->notebook_id)->toBe($notebook->id)
        // First of its own sibling list, not "after everything in the solution".
        ->and($child->position)->toBe(1);

    expect($response->json('redirect'))->toBe(route('notebooks.pages.edit', [$notebook, $child]));
});

it('creates a sub-subpage — three levels are allowed', function () {
    $notebook = Notebook::factory()->create();
    $parent = rootPage($notebook, 'Operação');
    $child = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Backup', 'position' => 1]);

    $this->actingAs(treeAdmin())
        ->postJson(route('notebooks.pages.store', $notebook), [
            'title'  => 'Retenção',
            'parent' => $child->id,
        ])
        ->assertOk();

    $grandchild = DocumentationPage::where('title', 'Retenção')->firstOrFail();

    expect($grandchild->parent_id)->toBe($child->id)
        ->and($grandchild->depth())->toBe(2)
        ->and($parent->subtreeHeight())->toBe(3);
});

it('stops at the last level, refusing one past MAX_DEPTH', function () {
    $notebook = Notebook::factory()->create();
    $deepest = rootPage($notebook, 'Nível 1');

    // Build a chain exactly as deep as the cap allows, whatever the cap is.
    foreach (range(2, DocumentationPage::MAX_DEPTH) as $level) {
        $deepest = DocumentationPage::factory()->childOf($deepest)->create([
            'title'    => 'Nível ' . $level,
            'position' => 1,
        ]);
    }

    expect($deepest->depth())->toBe(DocumentationPage::MAX_DEPTH - 1);

    $response = $this->actingAs(treeAdmin())
        ->postJson(route('notebooks.pages.store', $notebook), [
            'title'  => 'Um nível a mais',
            'parent' => $deepest->id,
        ])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    expect($response->json('message'))->toContain('último nível');
    expect(DocumentationPage::where('title', 'Um nível a mais')->exists())->toBeFalse();
    expect(DocumentationPage::MAX_DEPTH)->toBe(5);
});

it('nests a subpage under the subpage above it, reaching the third level', function () {
    $notebook = Notebook::factory()->create();
    $parent = rootPage($notebook, 'Operação');
    $first = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Backup', 'position' => 1]);
    $second = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Retenção', 'position' => 2]);

    $this->actingAs(treeAdmin())
        ->patchJson(route('notebooks.pages.move', [$notebook, $second]), ['direction' => 'in'])
        ->assertOk()
        ->assertJson(['message' => 'Página aninhada.']);

    expect($second->fresh())
        ->parent_id->toBe($first->id)
        ->and($second->fresh()->depth())->toBe(2);
});

it('refuses to nest a page whose own subpages would pass the last level', function () {
    $notebook = Notebook::factory()->create();
    $parent = rootPage($notebook, 'Operação');
    DocumentationPage::factory()->childOf($parent)->create(['title' => 'Backup', 'position' => 1]);
    $second = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Retenção', 'position' => 2]);

    // `second` already reaches the last level through its own descendants, so
    // it fits where it is but has nowhere to go: one step down would take the
    // deepest of them past the cap. Built from the constant, so the fixture
    // stays true whatever the cap is.
    $deepest = $second;
    foreach (range(1, DocumentationPage::MAX_DEPTH - 2) as $level) {
        $deepest = DocumentationPage::factory()->childOf($deepest)->create([
            'title'    => 'Cofre ' . $level,
            'position' => 1,
        ]);
    }
    expect($deepest->depth())->toBe(DocumentationPage::MAX_DEPTH - 1);

    $response = $this->actingAs(treeAdmin())
        ->patchJson(route('notebooks.pages.move', [$notebook, $second]), ['direction' => 'in'])
        ->assertStatus(422);

    expect($response->json('message'))->toContain('passariam do último nível');
    expect($second->fresh()->parent_id)->toBe($parent->id);
});

it('refuses to nest a page that is already at the last level', function () {
    $notebook = Notebook::factory()->create();
    $page = rootPage($notebook, 'Nível 1');

    // A chain down to the cap, then two siblings on that last level: nesting one
    // under the other would need a level that doesn't exist.
    foreach (range(2, DocumentationPage::MAX_DEPTH) as $level) {
        $page = DocumentationPage::factory()->childOf($page)->create(['title' => 'Nível ' . $level, 'position' => 1]);
    }
    $sibling = DocumentationPage::factory()->childOf($page->parent()->first())->create([
        'title'    => 'Vizinho no último nível',
        'position' => 2,
    ]);

    $response = $this->actingAs(treeAdmin())
        ->patchJson(route('notebooks.pages.move', [$notebook, $sibling]), ['direction' => 'in'])
        ->assertStatus(422);

    expect($response->json('message'))->toContain('último nível');
    expect($sibling->fresh()->parent_id)->toBe($page->parent_id);
});

it('promotes a sub-subpage one level, into its grandparent instead of the root list', function () {
    $notebook = Notebook::factory()->create();
    $parent = rootPage($notebook, 'Operação');
    $child = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Backup', 'position' => 1]);
    $childSibling = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Suporte', 'position' => 2]);
    $grandchild = DocumentationPage::factory()->childOf($child)->create(['title' => 'Retenção', 'position' => 1]);

    $this->actingAs(treeAdmin())
        ->patchJson(route('notebooks.pages.move', [$notebook, $grandchild]), ['direction' => 'out'])
        ->assertOk()
        ->assertJson(['message' => 'Página promovida.']);

    expect($grandchild->fresh())
        // One step up: a subpage of the grandparent, not a top-level page.
        ->parent_id->toBe($parent->id)
        ->and($grandchild->fresh()->depth())->toBe(1);

    // …and it lands right after the page it left, pushing that page's siblings down.
    expect(app(DocumentationPageService::class)->tree($notebook)->pluck('page.title')->all())
        ->toBe(['Operação', 'Backup', 'Retenção', 'Suporte']);
    expect($childSibling->fresh()->position)->toBe(3);
});

it('refuses a parent that belongs to another caderno', function () {
    $notebook = Notebook::factory()->create();
    $foreign = rootPage(Notebook::factory()->create(), 'Página de outro caderno');

    $this->actingAs(treeAdmin())
        ->postJson(route('notebooks.pages.store', $notebook), [
            'title'  => 'Tentativa',
            'parent' => $foreign->id,
        ])
        ->assertStatus(422);

    expect($foreign->children()->count())->toBe(0);
});

it('creates a root page when no parent is given', function () {
    $notebook = Notebook::factory()->create();
    rootPage($notebook, 'Visão geral');

    $this->actingAs(treeAdmin())
        ->postJson(route('notebooks.pages.store', $notebook), ['title' => 'Operação'])
        ->assertOk();

    $page = DocumentationPage::where('title', 'Operação')->firstOrFail();

    expect($page->parent_id)->toBeNull()
        ->and($page->position)->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Changing a page's level — `in` / `out`
|--------------------------------------------------------------------------
*/

it('nests a page under the one above it', function () {
    $notebook = Notebook::factory()->create();
    $first = rootPage($notebook, 'Operação', 1);
    $second = rootPage($notebook, 'Backup', 2);

    $this->actingAs(treeAdmin())
        ->patchJson(route('notebooks.pages.move', [$notebook, $second]), ['direction' => 'in'])
        ->assertOk()
        ->assertJson(['message' => 'Página aninhada.']);

    expect($second->fresh()->parent_id)->toBe($first->id);
});

it('promotes a subpage back to the root list, right after the page it left', function () {
    $notebook = Notebook::factory()->create();
    $first = rootPage($notebook, 'Operação', 1);
    $last = rootPage($notebook, 'Suporte', 2);
    $child = DocumentationPage::factory()->childOf($first)->create(['title' => 'Backup', 'position' => 1]);

    $this->actingAs(treeAdmin())
        ->patchJson(route('notebooks.pages.move', [$notebook, $child]), ['direction' => 'out'])
        ->assertOk()
        ->assertJson(['message' => 'Página promovida.']);

    expect($child->fresh()->parent_id)->toBeNull();

    // Right after its former parent — not at the bottom of the rail, where a
    // promoted page reads as lost.
    expect(app(DocumentationPageService::class)->tree($notebook)->pluck('page.title')->all())
        ->toBe(['Operação', 'Backup', 'Suporte']);
    expect($last->fresh()->position)->toBe(3);
});

it('refuses to nest the first page of the list, which has nothing above it', function () {
    $notebook = Notebook::factory()->create();
    $first = rootPage($notebook, 'Operação', 1);
    rootPage($notebook, 'Backup', 2);

    $response = $this->actingAs(treeAdmin())
        ->patchJson(route('notebooks.pages.move', [$notebook, $first]), ['direction' => 'in'])
        ->assertStatus(422);

    expect($response->json('message'))->toContain('Não há página acima');
    expect($first->fresh()->parent_id)->toBeNull();
});

it('nests a root page that already has subpages, carrying them one level down', function () {
    $notebook = Notebook::factory()->create();
    $first = rootPage($notebook, 'Operação', 1);
    $second = rootPage($notebook, 'Backup', 2);
    $child = DocumentationPage::factory()->childOf($second)->create(['title' => 'Retenção', 'position' => 1]);

    $this->actingAs(treeAdmin())
        ->patchJson(route('notebooks.pages.move', [$notebook, $second]), ['direction' => 'in'])
        ->assertOk();

    expect($second->fresh()->parent_id)->toBe($first->id);
    // The subpage came along and is now on the third level, still under `second`.
    expect($child->fresh()->parent_id)->toBe($second->id);
    expect($child->fresh()->depth())->toBe(2);
});

it('refuses to promote a page that is already at the first level', function () {
    $notebook = Notebook::factory()->create();
    $page = rootPage($notebook, 'Operação');

    $response = $this->actingAs(treeAdmin())
        ->patchJson(route('notebooks.pages.move', [$notebook, $page]), ['direction' => 'out'])
        ->assertStatus(422);

    expect($response->json('message'))->toContain('já está no primeiro nível');
});

it('reorders a subpage among its siblings without touching the root list', function () {
    $notebook = Notebook::factory()->create();
    $parent = rootPage($notebook, 'Operação', 1);
    $other = rootPage($notebook, 'Suporte', 2);
    $backup = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Backup', 'position' => 1]);
    $retention = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Retenção', 'position' => 2]);

    $this->actingAs(treeAdmin())
        ->patchJson(route('notebooks.pages.move', [$notebook, $retention]), ['direction' => 'up'])
        ->assertOk();

    expect(app(DocumentationPageService::class)->tree($notebook)->pluck('page.title')->all())
        ->toBe(['Operação', 'Retenção', 'Backup', 'Suporte']);
    expect($other->fresh()->position)->toBe(2);
});

it('leaves a first subpage where it is instead of promoting it out of its parent', function () {
    $notebook = Notebook::factory()->create();
    $parent = rootPage($notebook, 'Operação');
    $child = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Backup', 'position' => 1]);

    $this->actingAs(treeAdmin())
        ->patchJson(route('notebooks.pages.move', [$notebook, $child]), ['direction' => 'up'])
        ->assertOk();

    expect($child->fresh())
        ->parent_id->toBe($parent->id)
        ->position->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Deleting, and what the reader lands on
|--------------------------------------------------------------------------
*/

it('deletes a page together with its subpages', function () {
    $notebook = Notebook::factory()->create();
    $survivor = rootPage($notebook, 'Visão geral', 1);
    $parent = rootPage($notebook, 'Operação', 2);
    $child = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Backup', 'position' => 1]);

    $response = $this->actingAs(treeAdmin())
        ->deleteJson(route('notebooks.pages.destroy', [$notebook, $parent]))
        ->assertOk();

    $this->assertModelMissing($parent);
    $this->assertModelMissing($child);
    $this->assertModelExists($survivor);

    expect($response->json('redirect'))->toBe(route('notebooks.pages.edit', [$notebook, $survivor]));
});

it('sends the reader to the parent after deleting one of its subpages', function () {
    $notebook = Notebook::factory()->create();
    rootPage($notebook, 'Visão geral', 1);
    $parent = rootPage($notebook, 'Operação', 2);
    $child = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Backup', 'position' => 1]);

    $response = $this->actingAs(treeAdmin())
        ->deleteJson(route('notebooks.pages.destroy', [$notebook, $child]))
        ->assertOk();

    expect($response->json('redirect'))->toBe(route('notebooks.pages.edit', [$notebook, $parent]));
});

/*
|--------------------------------------------------------------------------
| Where a caderno opens
|--------------------------------------------------------------------------
*/

it('opens the docs on the first root page, never on a subpage', function () {
    $notebook = Notebook::factory()->create();
    $root = rootPage($notebook, 'Visão geral', 2);
    // A subpage's position only orders it among its siblings, so this 1 would
    // win a flat `orderBy('position')` — and land the reader inside a subtree.
    $child = DocumentationPage::factory()->childOf($root)->create(['title' => 'Backup', 'position' => 1]);

    $this->actingAs(treeAdmin())
        ->get(route('notebooks.show', $notebook))
        ->assertRedirect(route('notebooks.pages.edit', [$notebook, $root]));

    expect($child->fresh()->parent_id)->toBe($root->id);
});

/*
|--------------------------------------------------------------------------
| Re-filing a nested page under another caderno
|--------------------------------------------------------------------------
*/

it('carries the whole subtree along when a parent moves to another caderno', function () {
    $source = Notebook::factory()->create();
    $parent = DocumentationPage::factory()->for($source)->create(['title' => 'Espaço importado', 'position' => 1]);
    $child = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Detalhe', 'position' => 1]);
    // A third level: moving only the first one would leave this row filed under
    // a caderno it was never in.
    $grandchild = DocumentationPage::factory()->childOf($child)->create(['title' => 'Detalhe do detalhe', 'position' => 1]);
    $destination = Notebook::factory()->create();

    $this->actingAs(treeAdmin())
        ->patchJson(route('notebooks.pages.notebook', [$source, $parent]), [
            'notebook' => $destination->id,
        ])
        ->assertOk();

    expect($child->fresh())
        ->notebook_id->toBe($destination->id)
        // Still its parent's subpage — the nesting survives the move.
        ->parent_id->toBe($parent->id);

    expect($grandchild->fresh())
        ->notebook_id->toBe($destination->id)
        ->parent_id->toBe($child->id);

    expect($source->pages()->count())->toBe(0);
});

it('promotes a subpage moved on its own, since its parent stays behind', function () {
    $source = Notebook::factory()->create();
    $parent = DocumentationPage::factory()->for($source)->create(['title' => 'Espaço importado', 'position' => 1]);
    $child = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Detalhe', 'position' => 1]);
    $destination = Notebook::factory()->create();
    rootPage($destination, 'Visão geral', 1);

    $this->actingAs(treeAdmin())
        ->patchJson(route('notebooks.pages.notebook', [$source, $child]), [
            'notebook' => $destination->id,
        ])
        ->assertOk();

    expect($child->fresh())
        ->parent_id->toBeNull()
        ->notebook_id->toBe($destination->id)
        // End of the DESTINATION's root list, not of everything in it.
        ->position->toBe(2);

    expect($parent->fresh()->notebook_id)->toBe($source->id);
});

/*
|--------------------------------------------------------------------------
| The rail and the public index render the tree
|--------------------------------------------------------------------------
*/

it('renders the pages rail as a three-level tree, one indent step per level', function () {
    $notebook = Notebook::factory()->create();
    $parent = rootPage($notebook, 'Operação');
    $child = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Rotina de backup', 'position' => 1]);
    DocumentationPage::factory()->childOf($child)->create(['title' => 'Retenção de cópias', 'position' => 1]);

    $content = $this->actingAs(treeAdmin())
        ->get(route('notebooks.pages.edit', [$notebook, $parent]))
        ->assertOk()
        ->getContent();

    expect($content)
        ->toContain('Rotina de backup')
        ->toContain('Retenção de cópias')
        // One guide line per level: the subpage at one step, the sub-subpage at two.
        // (`pages-nav` carries a step for every level up to MAX_DEPTH - 1.)
        ->toContain('ml-3 border-l border-line pl-1.5')
        ->toContain('ml-6 border-l border-line pl-1.5')
        ->toContain('Nova subpágina')
        ->toContain('Promover um nível')
        // Nothing sits above the root here, so nesting isn't offered anywhere.
        ->not->toContain('Aninhar na página acima');
});

it('offers no subpage on a page already at the last level', function () {
    $notebook = Notebook::factory()->create();
    $page = rootPage($notebook, 'Nível 1');

    foreach (range(2, DocumentationPage::MAX_DEPTH) as $level) {
        $page = DocumentationPage::factory()->childOf($page)->create(['title' => 'Nível ' . $level, 'position' => 1]);
    }

    $content = $this->actingAs(treeAdmin())
        ->get(route('notebooks.pages.edit', [$notebook, $page]))
        ->assertOk()
        ->getContent();

    // Every row in the chain can take a child except the deepest one.
    expect(substr_count($content, 'Nova subpágina'))->toBe(DocumentationPage::MAX_DEPTH - 1);
    // …and it is the deepest that also can't be nested any further.
    expect(substr_count($content, 'Aninhar na página acima'))->toBe(0);
});

it('indents a subpage in the public documentation index', function () {
    $notebook = Notebook::factory()->create(['public_token' => 'tok-' . uniqid()]);
    $parent = rootPage($notebook, 'Operação');
    DocumentationPage::factory()->childOf($parent)->create([
        'title'         => 'Rotina de backup',
        'position'      => 1,
        'documentation' => '# Backup',
    ]);

    $content = $this->get(route('public.docs.notebook', $notebook->public_token))
        ->assertOk()
        ->getContent();

    expect($content)
        ->toContain('Rotina de backup')
        // Indented one step, hanging off a guide line. Asserted as "indented at
        // all" rather than on the exact utility string: the previous version
        // pinned `px-2.5`, which broke the day the rail's padding changed
        // without anything about the tree being wrong.
        ->toMatch('/ml-3[^"]*border-l/');
});

it('opens only the branch leading to the page being read', function () {
    // The reason this exists: the imported "Dados • BigQuery • GCP" is 133
    // pages, and a rail that lists all of them at once is not an index.
    $notebook = Notebook::factory()->create(['public_token' => 'tok-collapse']);
    $open = rootPage($notebook, 'Catálogo', 1);
    $openChild = DocumentationPage::factory()->childOf($open)->create(['title' => 'Camada Raw', 'documentation' => '# Raw']);
    $shut = rootPage($notebook, 'Outra seção', 2);
    DocumentationPage::factory()->childOf($shut)->create(['title' => 'Filha escondida', 'documentation' => '# X']);

    $content = $this->get(route('public.docs.page', ['tok-collapse', $openChild->slug]))
        ->assertOk()
        ->getContent();

    // Both roots are always listed…
    expect($content)->toContain('Catálogo')->toContain('Outra seção');

    // …the child on the active path is rendered visible…
    expect($content)->toMatch('/data-page-id="' . $openChild->id . '"(?![^>]*\bhidden\b)/');

    // …and the one under the collapsed branch ships hidden.
    $other = DocumentationPage::where('title', 'Filha escondida')->sole();
    expect($content)->toMatch('/data-page-id="' . $other->id . '"[^>]*\bhidden\b/');
});

it('gives a branch a toggle and a leaf none', function () {
    $notebook = Notebook::factory()->create(['public_token' => 'tok-toggle']);
    $branch = rootPage($notebook, 'Com filhas', 1);
    DocumentationPage::factory()->childOf($branch)->create(['title' => 'Filha']);
    $leaf = rootPage($notebook, 'Sem filhas', 2);

    $content = $this->get(route('public.docs.page', ['tok-toggle', $branch->slug]))
        ->assertOk()
        ->getContent();

    // The branch carries a chevron, open because it is the page being read.
    expect($content)->toMatch('/data-page-id="' . $branch->id . '"[^>]*data-expanded="true"/');
    expect(substr_count($content, 'data-ak-docs-tree-toggle'))->toBe(1);
    expect($content)->not->toMatch('/data-page-id="' . $leaf->id . '"[^>]*data-expanded="true"/');
});

/*
|--------------------------------------------------------------------------
| Deleting and nesting inside a caderno
|--------------------------------------------------------------------------
*/

it('deletes a caderno holding a nested page, subpage included', function () {
    // The caderno deletes its pages one by one through their models (for
    // Spatie's media cleanup), and a parent already took its subpage with it —
    // so the loop reaches a row that is gone. That has to stay a no-op, not a
    // crash.
    $group = Notebook::factory()->create();
    $parent = DocumentationPage::factory()->for($group)->create(['title' => 'Processo', 'position' => 1]);
    $child = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Exceções', 'position' => 1]);

    $this->actingAs(treeAdmin())
        ->deleteJson(route('notebooks.destroy', $group))
        ->assertOk();

    $this->assertModelMissing($parent);
    $this->assertModelMissing($child);
});

it('nests pages inside a caderno with no solution linked to it too', function () {
    $group = Notebook::factory()->create();
    $first = DocumentationPage::factory()->for($group)->create(['title' => 'Processo', 'position' => 1]);
    $second = DocumentationPage::factory()->for($group)->create(['title' => 'Exceções', 'position' => 2]);

    $this->actingAs(treeAdmin())
        ->patchJson(route('notebooks.pages.move', [$group, $second]), ['direction' => 'in'])
        ->assertOk()
        ->assertJson(['message' => 'Página aninhada.']);

    expect($second->fresh()->parent_id)->toBe($first->id);

    // Levels keep going, up to the cap…
    $parent = $second;
    foreach (range(3, DocumentationPage::MAX_DEPTH) as $level) {
        $this->actingAs(treeAdmin())
            ->postJson(route('notebooks.pages.store', $group), [
                'title'  => 'Nível ' . $level,
                'parent' => $parent->id,
            ])
            ->assertOk();

        $parent = DocumentationPage::where('title', 'Nível ' . $level)->firstOrFail();
    }

    expect($parent->depth())->toBe(DocumentationPage::MAX_DEPTH - 1);

    // …and one past it is refused.
    $this->actingAs(treeAdmin())
        ->postJson(route('notebooks.pages.store', $group), [
            'title'  => 'Um nível a mais',
            'parent' => $parent->id,
        ])
        ->assertStatus(422);
});
