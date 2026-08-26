<?php

use App\Enums\UserRole;
use App\Models\DocumentationGroup;
use App\Models\DocumentationPage;
use App\Models\Solution;
use App\Models\User;
use App\Services\DocumentationPageService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

function treeAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

/** A root page of `$solution`, at the given position in the root list. */
function rootPage(Solution $solution, string $title, int $position = 1): DocumentationPage
{
    return DocumentationPage::factory()->for($solution, 'container')->create([
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
    $solution = Solution::factory()->create();
    $parent = rootPage($solution, 'Operação');

    $response = $this->actingAs(treeAdmin())
        ->postJson(route('solutions.docs.pages.store', $solution), [
            'title'  => 'Rotina de backup',
            'parent' => $parent->id,
        ])
        ->assertOk()
        ->assertJson(['type' => 'success', 'message' => 'Página criada.']);

    $child = DocumentationPage::where('title', 'Rotina de backup')->firstOrFail();

    expect($child->parent_id)->toBe($parent->id)
        ->and($child->container_id)->toBe($solution->id)
        // First of its own sibling list, not "after everything in the solution".
        ->and($child->position)->toBe(1);

    expect($response->json('redirect'))->toBe(route('solutions.docs.page.edit', [$solution, $child]));
});

it('creates a sub-subpage — three levels are allowed', function () {
    $solution = Solution::factory()->create();
    $parent = rootPage($solution, 'Operação');
    $child = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Backup', 'position' => 1]);

    $this->actingAs(treeAdmin())
        ->postJson(route('solutions.docs.pages.store', $solution), [
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
    $solution = Solution::factory()->create();
    $deepest = rootPage($solution, 'Nível 1');

    // Build a chain exactly as deep as the cap allows, whatever the cap is.
    foreach (range(2, DocumentationPage::MAX_DEPTH) as $level) {
        $deepest = DocumentationPage::factory()->childOf($deepest)->create([
            'title'    => 'Nível ' . $level,
            'position' => 1,
        ]);
    }

    expect($deepest->depth())->toBe(DocumentationPage::MAX_DEPTH - 1);

    $response = $this->actingAs(treeAdmin())
        ->postJson(route('solutions.docs.pages.store', $solution), [
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
    $solution = Solution::factory()->create();
    $parent = rootPage($solution, 'Operação');
    $first = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Backup', 'position' => 1]);
    $second = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Retenção', 'position' => 2]);

    $this->actingAs(treeAdmin())
        ->patchJson(route('solutions.docs.pages.move', [$solution, $second]), ['direction' => 'in'])
        ->assertOk()
        ->assertJson(['message' => 'Página aninhada.']);

    expect($second->fresh())
        ->parent_id->toBe($first->id)
        ->and($second->fresh()->depth())->toBe(2);
});

it('refuses to nest a page whose own subpages would pass the last level', function () {
    $solution = Solution::factory()->create();
    $parent = rootPage($solution, 'Operação');
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
        ->patchJson(route('solutions.docs.pages.move', [$solution, $second]), ['direction' => 'in'])
        ->assertStatus(422);

    expect($response->json('message'))->toContain('passariam do último nível');
    expect($second->fresh()->parent_id)->toBe($parent->id);
});

it('refuses to nest a page that is already at the last level', function () {
    $solution = Solution::factory()->create();
    $page = rootPage($solution, 'Nível 1');

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
        ->patchJson(route('solutions.docs.pages.move', [$solution, $sibling]), ['direction' => 'in'])
        ->assertStatus(422);

    expect($response->json('message'))->toContain('último nível');
    expect($sibling->fresh()->parent_id)->toBe($page->parent_id);
});

it('promotes a sub-subpage one level, into its grandparent instead of the root list', function () {
    $solution = Solution::factory()->create();
    $parent = rootPage($solution, 'Operação');
    $child = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Backup', 'position' => 1]);
    $childSibling = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Suporte', 'position' => 2]);
    $grandchild = DocumentationPage::factory()->childOf($child)->create(['title' => 'Retenção', 'position' => 1]);

    $this->actingAs(treeAdmin())
        ->patchJson(route('solutions.docs.pages.move', [$solution, $grandchild]), ['direction' => 'out'])
        ->assertOk()
        ->assertJson(['message' => 'Página promovida.']);

    expect($grandchild->fresh())
        // One step up: a subpage of the grandparent, not a top-level page.
        ->parent_id->toBe($parent->id)
        ->and($grandchild->fresh()->depth())->toBe(1);

    // …and it lands right after the page it left, pushing that page's siblings down.
    expect(app(DocumentationPageService::class)->tree($solution)->pluck('page.title')->all())
        ->toBe(['Operação', 'Backup', 'Retenção', 'Suporte']);
    expect($childSibling->fresh()->position)->toBe(3);
});

it('refuses a parent that belongs to another container', function () {
    $solution = Solution::factory()->create();
    $foreign = rootPage(Solution::factory()->create(), 'Página de outra solução');

    $this->actingAs(treeAdmin())
        ->postJson(route('solutions.docs.pages.store', $solution), [
            'title'  => 'Tentativa',
            'parent' => $foreign->id,
        ])
        ->assertStatus(422);

    expect($foreign->children()->count())->toBe(0);
});

it('creates a root page when no parent is given', function () {
    $solution = Solution::factory()->create();
    rootPage($solution, 'Visão geral');

    $this->actingAs(treeAdmin())
        ->postJson(route('solutions.docs.pages.store', $solution), ['title' => 'Operação'])
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
    $solution = Solution::factory()->create();
    $first = rootPage($solution, 'Operação', 1);
    $second = rootPage($solution, 'Backup', 2);

    $this->actingAs(treeAdmin())
        ->patchJson(route('solutions.docs.pages.move', [$solution, $second]), ['direction' => 'in'])
        ->assertOk()
        ->assertJson(['message' => 'Página aninhada.']);

    expect($second->fresh()->parent_id)->toBe($first->id);
});

it('promotes a subpage back to the root list, right after the page it left', function () {
    $solution = Solution::factory()->create();
    $first = rootPage($solution, 'Operação', 1);
    $last = rootPage($solution, 'Suporte', 2);
    $child = DocumentationPage::factory()->childOf($first)->create(['title' => 'Backup', 'position' => 1]);

    $this->actingAs(treeAdmin())
        ->patchJson(route('solutions.docs.pages.move', [$solution, $child]), ['direction' => 'out'])
        ->assertOk()
        ->assertJson(['message' => 'Página promovida.']);

    expect($child->fresh()->parent_id)->toBeNull();

    // Right after its former parent — not at the bottom of the rail, where a
    // promoted page reads as lost.
    expect(app(DocumentationPageService::class)->tree($solution)->pluck('page.title')->all())
        ->toBe(['Operação', 'Backup', 'Suporte']);
    expect($last->fresh()->position)->toBe(3);
});

it('refuses to nest the first page of the list, which has nothing above it', function () {
    $solution = Solution::factory()->create();
    $first = rootPage($solution, 'Operação', 1);
    rootPage($solution, 'Backup', 2);

    $response = $this->actingAs(treeAdmin())
        ->patchJson(route('solutions.docs.pages.move', [$solution, $first]), ['direction' => 'in'])
        ->assertStatus(422);

    expect($response->json('message'))->toContain('Não há página acima');
    expect($first->fresh()->parent_id)->toBeNull();
});

it('nests a root page that already has subpages, carrying them one level down', function () {
    $solution = Solution::factory()->create();
    $first = rootPage($solution, 'Operação', 1);
    $second = rootPage($solution, 'Backup', 2);
    $child = DocumentationPage::factory()->childOf($second)->create(['title' => 'Retenção', 'position' => 1]);

    $this->actingAs(treeAdmin())
        ->patchJson(route('solutions.docs.pages.move', [$solution, $second]), ['direction' => 'in'])
        ->assertOk();

    expect($second->fresh()->parent_id)->toBe($first->id);
    // The subpage came along and is now on the third level, still under `second`.
    expect($child->fresh()->parent_id)->toBe($second->id);
    expect($child->fresh()->depth())->toBe(2);
});

it('refuses to promote a page that is already at the first level', function () {
    $solution = Solution::factory()->create();
    $page = rootPage($solution, 'Operação');

    $response = $this->actingAs(treeAdmin())
        ->patchJson(route('solutions.docs.pages.move', [$solution, $page]), ['direction' => 'out'])
        ->assertStatus(422);

    expect($response->json('message'))->toContain('já está no primeiro nível');
});

it('reorders a subpage among its siblings without touching the root list', function () {
    $solution = Solution::factory()->create();
    $parent = rootPage($solution, 'Operação', 1);
    $other = rootPage($solution, 'Suporte', 2);
    $backup = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Backup', 'position' => 1]);
    $retention = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Retenção', 'position' => 2]);

    $this->actingAs(treeAdmin())
        ->patchJson(route('solutions.docs.pages.move', [$solution, $retention]), ['direction' => 'up'])
        ->assertOk();

    expect(app(DocumentationPageService::class)->tree($solution)->pluck('page.title')->all())
        ->toBe(['Operação', 'Retenção', 'Backup', 'Suporte']);
    expect($other->fresh()->position)->toBe(2);
});

it('leaves a first subpage where it is instead of promoting it out of its parent', function () {
    $solution = Solution::factory()->create();
    $parent = rootPage($solution, 'Operação');
    $child = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Backup', 'position' => 1]);

    $this->actingAs(treeAdmin())
        ->patchJson(route('solutions.docs.pages.move', [$solution, $child]), ['direction' => 'up'])
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
    $solution = Solution::factory()->create();
    $survivor = rootPage($solution, 'Visão geral', 1);
    $parent = rootPage($solution, 'Operação', 2);
    $child = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Backup', 'position' => 1]);

    $response = $this->actingAs(treeAdmin())
        ->deleteJson(route('solutions.docs.pages.destroy', [$solution, $parent]))
        ->assertOk();

    $this->assertModelMissing($parent);
    $this->assertModelMissing($child);
    $this->assertModelExists($survivor);

    expect($response->json('redirect'))->toBe(route('solutions.docs.page.edit', [$solution, $survivor]));
});

it('sends the reader to the parent after deleting one of its subpages', function () {
    $solution = Solution::factory()->create();
    rootPage($solution, 'Visão geral', 1);
    $parent = rootPage($solution, 'Operação', 2);
    $child = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Backup', 'position' => 1]);

    $response = $this->actingAs(treeAdmin())
        ->deleteJson(route('solutions.docs.pages.destroy', [$solution, $child]))
        ->assertOk();

    expect($response->json('redirect'))->toBe(route('solutions.docs.page.edit', [$solution, $parent]));
});

/*
|--------------------------------------------------------------------------
| Where a container opens
|--------------------------------------------------------------------------
*/

it('opens the docs on the first root page, never on a subpage', function () {
    $solution = Solution::factory()->create();
    $root = rootPage($solution, 'Visão geral', 2);
    // A subpage's position only orders it among its siblings, so this 1 would
    // win a flat `orderBy('position')` — and land the reader inside a subtree.
    $child = DocumentationPage::factory()->childOf($root)->create(['title' => 'Backup', 'position' => 1]);

    $this->actingAs(treeAdmin())
        ->get(route('solutions.docs.edit', $solution))
        ->assertRedirect(route('solutions.docs.page.edit', [$solution, $root]));

    expect($child->fresh()->parent_id)->toBe($root->id);
});

/*
|--------------------------------------------------------------------------
| Re-filing a nested page under another container
|--------------------------------------------------------------------------
*/

it('carries the whole subtree along when a parent moves to another container', function () {
    $group = DocumentationGroup::factory()->create();
    $parent = DocumentationPage::factory()->for($group, 'container')->create(['title' => 'Espaço importado', 'position' => 1]);
    $child = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Detalhe', 'position' => 1]);
    // A third level: moving only the first one would leave this row filed under
    // a container it was never in.
    $grandchild = DocumentationPage::factory()->childOf($child)->create(['title' => 'Detalhe do detalhe', 'position' => 1]);
    $solution = Solution::factory()->create();

    $this->actingAs(treeAdmin())
        ->patchJson(route('documentation.groups.pages.container', [$group, $parent]), [
            'container' => 'solution:' . $solution->id,
        ])
        ->assertOk();

    expect($child->fresh())
        ->container_type->toBe(Solution::class)
        ->container_id->toBe($solution->id)
        // Still its parent's subpage — the nesting survives the move.
        ->parent_id->toBe($parent->id);

    expect($grandchild->fresh())
        ->container_id->toBe($solution->id)
        ->parent_id->toBe($child->id);

    expect($group->pages()->count())->toBe(0);
});

it('promotes a subpage moved on its own, since its parent stays behind', function () {
    $group = DocumentationGroup::factory()->create();
    $parent = DocumentationPage::factory()->for($group, 'container')->create(['title' => 'Espaço importado', 'position' => 1]);
    $child = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Detalhe', 'position' => 1]);
    $solution = Solution::factory()->create();
    rootPage($solution, 'Visão geral', 1);

    $this->actingAs(treeAdmin())
        ->patchJson(route('documentation.groups.pages.container', [$group, $child]), [
            'container' => 'solution:' . $solution->id,
        ])
        ->assertOk();

    expect($child->fresh())
        ->parent_id->toBeNull()
        ->container_id->toBe($solution->id)
        // End of the DESTINATION's root list, not of everything in it.
        ->position->toBe(2);

    expect($parent->fresh()->container_type)->toBe(DocumentationGroup::class);
});

/*
|--------------------------------------------------------------------------
| The rail and the public index render the tree
|--------------------------------------------------------------------------
*/

it('renders the pages rail as a three-level tree, one indent step per level', function () {
    $solution = Solution::factory()->create();
    $parent = rootPage($solution, 'Operação');
    $child = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Rotina de backup', 'position' => 1]);
    DocumentationPage::factory()->childOf($child)->create(['title' => 'Retenção de cópias', 'position' => 1]);

    $content = $this->actingAs(treeAdmin())
        ->get(route('solutions.docs.page.edit', [$solution, $parent]))
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
    $solution = Solution::factory()->create();
    $page = rootPage($solution, 'Nível 1');

    foreach (range(2, DocumentationPage::MAX_DEPTH) as $level) {
        $page = DocumentationPage::factory()->childOf($page)->create(['title' => 'Nível ' . $level, 'position' => 1]);
    }

    $content = $this->actingAs(treeAdmin())
        ->get(route('solutions.docs.page.edit', [$solution, $page]))
        ->assertOk()
        ->getContent();

    // Every row in the chain can take a child except the deepest one.
    expect(substr_count($content, 'Nova subpágina'))->toBe(DocumentationPage::MAX_DEPTH - 1);
    // …and it is the deepest that also can't be nested any further.
    expect(substr_count($content, 'Aninhar na página acima'))->toBe(0);
});

it('indents a subpage in the public documentation index', function () {
    $solution = Solution::factory()->create(['public_token' => 'tok-' . uniqid()]);
    $parent = rootPage($solution, 'Operação');
    DocumentationPage::factory()->childOf($parent)->create([
        'title'         => 'Rotina de backup',
        'position'      => 1,
        'documentation' => '# Backup',
    ]);

    $content = $this->get(route('public.docs.solution', $solution->public_token))
        ->assertOk()
        ->getContent();

    expect($content)
        ->toContain('Rotina de backup')
        ->toContain('ml-3 border-l border-line px-2.5');
});

/*
|--------------------------------------------------------------------------
| A standalone group nests the same way
|--------------------------------------------------------------------------
*/

it('deletes a group holding a nested page, subpage included', function () {
    // The group deletes its pages one by one through their models (for Spatie's
    // media cleanup), and a parent already took its subpage with it — so the
    // loop reaches a row that is gone. That has to stay a no-op, not a crash.
    $group = DocumentationGroup::factory()->create();
    $parent = DocumentationPage::factory()->for($group, 'container')->create(['title' => 'Processo', 'position' => 1]);
    $child = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Exceções', 'position' => 1]);

    $this->actingAs(treeAdmin())
        ->deleteJson(route('documentation.groups.destroy', $group))
        ->assertOk();

    $this->assertModelMissing($parent);
    $this->assertModelMissing($child);
});

it('nests pages inside a standalone documentation group too', function () {
    $group = DocumentationGroup::factory()->create();
    $first = DocumentationPage::factory()->for($group, 'container')->create(['title' => 'Processo', 'position' => 1]);
    $second = DocumentationPage::factory()->for($group, 'container')->create(['title' => 'Exceções', 'position' => 2]);

    $this->actingAs(treeAdmin())
        ->patchJson(route('documentation.groups.pages.move', [$group, $second]), ['direction' => 'in'])
        ->assertOk()
        ->assertJson(['message' => 'Página aninhada.']);

    expect($second->fresh()->parent_id)->toBe($first->id);

    // Levels keep going, up to the cap…
    $parent = $second;
    foreach (range(3, DocumentationPage::MAX_DEPTH) as $level) {
        $this->actingAs(treeAdmin())
            ->postJson(route('documentation.groups.pages.store', $group), [
                'title'  => 'Nível ' . $level,
                'parent' => $parent->id,
            ])
            ->assertOk();

        $parent = DocumentationPage::where('title', 'Nível ' . $level)->firstOrFail();
    }

    expect($parent->depth())->toBe(DocumentationPage::MAX_DEPTH - 1);

    // …and one past it is refused.
    $this->actingAs(treeAdmin())
        ->postJson(route('documentation.groups.pages.store', $group), [
            'title'  => 'Um nível a mais',
            'parent' => $parent->id,
        ])
        ->assertStatus(422);
});
