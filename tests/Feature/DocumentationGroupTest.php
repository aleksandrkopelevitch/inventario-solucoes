<?php

use App\Enums\UserRole;
use App\Models\DocumentationGroup;
use App\Models\DocumentationPage;
use App\Models\Solution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

function groupAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

/*
|--------------------------------------------------------------------------
| Group — CRUD (admin-only)
|--------------------------------------------------------------------------
*/

it('lets an admin create a group and opens its first page automatically', function () {
    $response = $this->actingAs(groupAdmin())
        ->postJson(route('documentation.groups.store'), ['name' => 'Integrações Leo'])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    $group = DocumentationGroup::where('name', 'Integrações Leo')->sole();
    expect($response->json('redirect'))->toBe(route('documentation.groups.show', $group));

    $this->actingAs(groupAdmin())
        ->get(route('documentation.groups.show', $group))
        ->assertRedirect();

    $page = $group->pages()->sole();
    expect($page->title)->toBe('Página inicial');
});

it('forbids a viewer from creating a group', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('documentation.groups.store'), ['name' => 'X'])
        ->assertForbidden();

    expect(DocumentationGroup::count())->toBe(0);
});

it('lets an admin rename a group without changing its slug', function () {
    $group = DocumentationGroup::factory()->create();
    $originalSlug = $group->slug;

    $response = $this->actingAs(groupAdmin())
        ->patchJson(route('documentation.groups.update', $group), ['name' => 'Novo nome'])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($group->fresh())->name->toBe('Novo nome')->slug->toBe($originalSlug)
        ->and($response->json('updatableSlots.0.id'))->toBe('documentation-groups-slot');
});

it('lets an admin delete a group, cascading its pages', function () {
    $group = DocumentationGroup::factory()->create();
    $page = DocumentationPage::factory()->for($group, 'container')->create();

    $this->actingAs(groupAdmin())
        ->deleteJson(route('documentation.groups.destroy', $group))
        ->assertOk()
        ->assertJson(['type' => 'success']);

    $this->assertModelMissing($group);
    $this->assertModelMissing($page);
});

it('forbids a viewer from renaming or deleting a group', function () {
    $group = DocumentationGroup::factory()->create();

    $this->actingAs(User::factory()->create())
        ->patchJson(route('documentation.groups.update', $group), ['name' => 'X'])
        ->assertForbidden();

    $this->actingAs(User::factory()->create())
        ->deleteJson(route('documentation.groups.destroy', $group))
        ->assertForbidden();

    expect($group->fresh())->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Group pages — create / rename / move / delete / media
|--------------------------------------------------------------------------
*/

it('lets an admin create, rename, move and delete pages inside a group', function () {
    $group = DocumentationGroup::factory()->create();
    $first = DocumentationPage::factory()->for($group, 'container')->create(['position' => 0]);

    $store = $this->actingAs(groupAdmin())
        ->postJson(route('documentation.groups.pages.store', $group), ['title' => 'SAP S/4'])
        ->assertOk();

    $second = $group->pages()->where('title', 'SAP S/4')->sole();
    expect($store->json('redirect'))->toBe(route('documentation.groups.pages.edit', [$group, $second]));

    $originalSlug = $second->slug;
    $this->actingAs(groupAdmin())
        ->patchJson(route('documentation.groups.pages.rename', [$group, $second]), ['title' => 'SAP S/4HANA'])
        ->assertOk();
    expect($second->fresh())->title->toBe('SAP S/4HANA')->slug->toBe($originalSlug);

    $this->actingAs(groupAdmin())
        ->patchJson(route('documentation.groups.pages.move', [$group, $second]), ['direction' => 'up'])
        ->assertOk();
    expect($second->fresh()->position)->toBe(0)
        ->and($first->fresh()->position)->toBe(1);

    $destroy = $this->actingAs(groupAdmin())
        ->deleteJson(route('documentation.groups.pages.destroy', [$group, $first]))
        ->assertOk();
    $this->assertModelMissing($first);
    expect($destroy->json('redirect'))->toBe(route('documentation.groups.pages.edit', [$group, $second]));
});

it('links a group page back to the documentation hub and lists it in the pages rail', function () {
    $group = DocumentationGroup::factory()->create(['name' => 'Integrações Leo']);
    $page = DocumentationPage::factory()->for($group, 'container')->create(['title' => 'SAP S/4']);

    $response = $this->actingAs(groupAdmin())
        ->get(route('documentation.groups.pages.edit', [$group, $page]))
        ->assertOk();

    expect($response->getContent())
        // Top-bar back link points at the documentation hub.
        ->toContain('href="' . route('documentation.index') . '"')
        // The current page is listed (and linked) in the collapsible pages rail.
        ->toMatch('/>\s*SAP S\/4\s*<\/a>/');
});

it('saves a group page documentation and shows the read-only render to a viewer', function () {
    $group = DocumentationGroup::factory()->create();
    $page = DocumentationPage::factory()->for($group, 'container')->create();

    $this->actingAs(groupAdmin())
        ->patchJson(route('documentation.groups.pages.update', [$group, $page]), ['documentation' => '# Doc do grupo'])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($page->fresh()->documentation)->toBe('# Doc do grupo');

    $this->actingAs(User::factory()->create())
        ->get(route('documentation.groups.pages.edit', [$group, $page]))
        ->assertOk()
        ->assertSee('<h1>Doc do grupo', false)
        ->assertDontSee('data-ak-docs-editor', false);
});

it('404s a group page that does not belong to the group in the url', function () {
    $group = DocumentationGroup::factory()->create();
    $other = DocumentationGroup::factory()->create();
    $page = DocumentationPage::factory()->for($other, 'container')->create();

    $this->actingAs(groupAdmin())
        ->get(route('documentation.groups.pages.edit', [$group, $page]))
        ->assertNotFound();
});

it('404s a group page url built from a solution page (different container types never cross-match)', function () {
    $group = DocumentationGroup::factory()->create();
    $solution = Solution::factory()->create();
    $solutionPage = DocumentationPage::factory()->for($solution, 'container')->create();

    $this->actingAs(groupAdmin())
        ->get(route('documentation.groups.pages.edit', [$group, $solutionPage]))
        ->assertNotFound();
});

it('uploads media for a group page', function () {
    Storage::fake('public');
    $group = DocumentationGroup::factory()->create();
    $page = DocumentationPage::factory()->for($group, 'container')->create();

    $response = $this->actingAs(groupAdmin())
        ->post(route('documentation.groups.pages.media', [$group, $page]), [
            'file' => UploadedFile::fake()->image('diagrama.png'),
        ])
        ->assertOk()
        ->assertJson(['success' => 1]);

    expect($page->fresh()->getMedia('docs'))->toHaveCount(1)
        ->and($response->json('file.url'))->toContain('/files/' . $response->json('file.mediaId'));
});

it('forbids a viewer from creating or uploading media to a group page', function () {
    $group = DocumentationGroup::factory()->create();
    $page = DocumentationPage::factory()->for($group, 'container')->create();

    $this->actingAs(User::factory()->create())
        ->postJson(route('documentation.groups.pages.store', $group), ['title' => 'X'])
        ->assertForbidden();

    $this->actingAs(User::factory()->create())
        ->post(route('documentation.groups.pages.media', [$group, $page]), [
            'file' => UploadedFile::fake()->image('x.png'),
        ])
        ->assertForbidden();
});
