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

it('keeps the tree two levels deep by refusing a subpage of a subpage', function () {
    $solution = Solution::factory()->create();
    $parent = rootPage($solution, 'Operação');
    $child = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Backup', 'position' => 1]);

    $response = $this->actingAs(treeAdmin())
        ->postJson(route('solutions.docs.pages.store', $solution), [
            'title'  => 'Retenção',
            'parent' => $child->id,
        ])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    expect($response->json('message'))->toContain('subpágina não pode receber outra subpágina');
    expect(DocumentationPage::where('title', 'Retenção')->exists())->toBeFalse();
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

it('refuses to nest a page that has subpages of its own', function () {
    $solution = Solution::factory()->create();
    rootPage($solution, 'Operação', 1);
    $second = rootPage($solution, 'Backup', 2);
    DocumentationPage::factory()->childOf($second)->create(['title' => 'Retenção', 'position' => 1]);

    $response = $this->actingAs(treeAdmin())
        ->patchJson(route('solutions.docs.pages.move', [$solution, $second]), ['direction' => 'in'])
        ->assertStatus(422);

    expect($response->json('message'))->toContain('subpáginas não pode ser aninhada');
    expect($second->fresh()->parent_id)->toBeNull();
});

it('refuses to nest a page that is already a subpage', function () {
    $solution = Solution::factory()->create();
    $parent = rootPage($solution, 'Operação');
    $child = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Backup', 'position' => 1]);

    $response = $this->actingAs(treeAdmin())
        ->patchJson(route('solutions.docs.pages.move', [$solution, $child]), ['direction' => 'in'])
        ->assertStatus(422);

    expect($response->json('message'))->toContain('já é uma subpágina');
    expect($child->fresh()->parent_id)->toBe($parent->id);
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

it('carries the subpages along when their parent moves to another container', function () {
    $group = DocumentationGroup::factory()->create();
    $parent = DocumentationPage::factory()->for($group, 'container')->create(['title' => 'Espaço importado', 'position' => 1]);
    $child = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Detalhe', 'position' => 1]);
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

it('renders the pages rail as a two-level tree', function () {
    $solution = Solution::factory()->create();
    $parent = rootPage($solution, 'Operação');
    DocumentationPage::factory()->childOf($parent)->create(['title' => 'Rotina de backup', 'position' => 1]);

    $content = $this->actingAs(treeAdmin())
        ->get(route('solutions.docs.page.edit', [$solution, $parent]))
        ->assertOk()
        ->getContent();

    expect($content)
        ->toContain('Rotina de backup')
        // The subpage's row hangs off a guide line, indented under its page.
        ->toContain('ml-3 border-l border-line pl-1.5')
        // A root page offers a subpage; the nesting gestures are offered only
        // where they're possible (this parent has nothing above it, and its
        // child can be promoted).
        ->toContain('Nova subpágina')
        ->toContain('Promover a página')
        ->not->toContain('Aninhar na página acima');
});

it('offers a subpage no way to nest deeper', function () {
    $solution = Solution::factory()->create();
    $parent = rootPage($solution, 'Operação');
    $child = DocumentationPage::factory()->childOf($parent)->create(['title' => 'Backup', 'position' => 1]);

    $content = $this->actingAs(treeAdmin())
        ->get(route('solutions.docs.page.edit', [$solution, $child]))
        ->assertOk()
        ->getContent();

    // One "Nova subpágina" — the parent's. The child's row doesn't offer it.
    expect(substr_count($content, 'Nova subpágina'))->toBe(1);
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

    $this->actingAs(treeAdmin())
        ->postJson(route('documentation.groups.pages.store', $group), [
            'title'  => 'Terceiro nível',
            'parent' => $second->id,
        ])
        ->assertStatus(422);
});
