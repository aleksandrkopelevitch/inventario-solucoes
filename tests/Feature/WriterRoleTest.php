<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Diagram;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\Person;
use App\Models\Solution;
use App\Models\User;
use Database\Seeders\AttributeOptionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The Writer ("Editor") role
|--------------------------------------------------------------------------
|
| The line this whole file draws: an editor CREATES and EDITS content, and the
| admin keeps everything that is not content — accounts, the attribute
| vocabulary, deletion, publishing a caderno and its protected values. The
| policies express it through `UserRole::canWrite()` / `canDelete()` /
| `isAdmin()`, so these tests are what stop a fourth spelling appearing.
|
*/

function writer(): User
{
    return User::factory()->create(['role' => UserRole::Writer->value]);
}

function viewerUser(): User
{
    return User::factory()->create(['role' => UserRole::Viewer->value]);
}

/*
|--------------------------------------------------------------------------
| What an editor may do
|--------------------------------------------------------------------------
*/

it('lets an editor create and edit a solution', function () {
    $this->seed(AttributeOptionSeeder::class);

    $this->actingAs(writer())
        ->postJson(route('solutions.store'), [
            'name'     => 'Nova Solução',
            'category' => 'erp',
            'status'   => 'active',
        ])
        ->assertOk();

    $solution = Solution::where('name', 'Nova Solução')->sole();

    $this->actingAs(writer())
        ->patchJson(route('solutions.update', $solution), [
            'name'     => 'Solução Renomeada',
            'category' => 'erp',
            'status'   => 'active',
        ])
        ->assertOk();

    expect($solution->fresh()->name)->toBe('Solução Renomeada');
});

it('lets an editor create a person and a company', function () {
    $this->actingAs(writer())->postJson(route('people.store'), ['name' => 'Maria'])->assertOk();
    $this->actingAs(writer())
        ->postJson(route('companies.store'), ['name' => 'Fornecedora', 'kind' => 'vendor'])
        ->assertOk();

    expect(Person::where('name', 'Maria')->exists())->toBeTrue()
        ->and(Company::where('name', 'Fornecedora')->exists())->toBeTrue();
});

it('lets an editor create a caderno, write a page and draw a diagram', function () {
    $this->actingAs(writer())->postJson(route('notebooks.store'), ['name' => 'Caderno do Editor'])->assertOk();
    $notebook = Notebook::where('name', 'Caderno do Editor')->sole();

    $this->actingAs(writer())
        ->postJson(route('notebooks.pages.store', $notebook), ['title' => 'Primeira página'])
        ->assertOk();

    $page = $notebook->pages()->sole();

    $this->actingAs(writer())
        ->patchJson(route('notebooks.pages.update', [$notebook, $page]), ['documentation' => '# Conteúdo'])
        ->assertOk();

    expect($page->fresh()->documentation)->toContain('# Conteúdo');

    $this->actingAs(writer())->postJson(route('diagrams.store'), ['name' => 'Fluxo novo'])->assertOk();
    expect(Diagram::where('name', 'Fluxo novo')->exists())->toBeTrue();
});

it('lets an editor see the editor on a page, not the read-only render', function () {
    $page = DocumentationPage::factory()->create(['documentation' => '# Algo']);

    $this->actingAs(writer())
        ->get(route('notebooks.pages.edit', [$page->notebook, $page]))
        ->assertOk()
        // The Editor.js mount point, which only whoever can write receives.
        ->assertSee('data-ak-docs-editor', false);
});

/*
|--------------------------------------------------------------------------
| What stays with the admin
|--------------------------------------------------------------------------
*/

it('refuses an editor every kind of DELETE', function () {
    $notebook = Notebook::factory()->create();
    $diagram = Diagram::factory()->create();

    $this->actingAs(writer())->deleteJson(route('notebooks.destroy', $notebook))->assertForbidden();
    $this->actingAs(writer())->deleteJson(route('diagrams.destroy', $diagram))->assertForbidden();

    $this->assertModelExists($notebook);
    $this->assertModelExists($diagram);
});

it('refuses an editor the account list and an invitation', function () {
    $this->actingAs(writer())
        ->postJson(route('users.store'), ['name' => 'X', 'email' => 'x@leomadeiras.com.br', 'role' => 'viewer'])
        ->assertForbidden();

    expect(User::where('email', 'x@leomadeiras.com.br')->exists())->toBeFalse();
});

it('refuses an editor the attribute vocabulary', function () {
    $this->seed(AttributeOptionSeeder::class);

    $this->actingAs(writer())
        ->postJson(route('attribute-options.store', 'category'), ['label' => 'Categoria nova'])
        ->assertForbidden();
});

it('refuses an editor the public link and the secret code', function () {
    $notebook = Notebook::factory()->create();

    $this->actingAs(writer())->postJson(route('notebooks.share', $notebook))->assertForbidden();
    $this->actingAs(writer())->postJson(route('notebooks.secret-code', $notebook))->assertForbidden();

    expect($notebook->fresh()->public_token)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The viewer is unchanged
|--------------------------------------------------------------------------
*/

it('still refuses a viewer any write', function () {
    $this->seed(AttributeOptionSeeder::class);
    $notebook = Notebook::factory()->create();

    $this->actingAs(viewerUser())
        ->postJson(route('solutions.store'), ['name' => 'X', 'category' => 'erp', 'status' => 'active'])
        ->assertForbidden();
    $this->actingAs(viewerUser())->postJson(route('people.store'), ['name' => 'X'])->assertForbidden();
    $this->actingAs(viewerUser())
        ->postJson(route('notebooks.pages.store', $notebook), ['title' => 'X'])
        ->assertForbidden();
    $this->actingAs(viewerUser())->postJson(route('diagrams.store'), ['name' => 'X'])->assertForbidden();
});

it('gives a viewer the read-only render rather than the editor', function () {
    $page = DocumentationPage::factory()->create(['documentation' => '# Algo']);

    $this->actingAs(viewerUser())
        ->get(route('notebooks.pages.edit', [$page->notebook, $page]))
        ->assertOk()
        ->assertDontSee('data-ak-docs-editor', false)
        ->assertSee('html-content', false);
});

/*
|--------------------------------------------------------------------------
| Inviting one
|--------------------------------------------------------------------------
*/

it('accepts writer as an invitable role', function () {
    // The rule used to name Viewer and Admin explicitly — a leftover from the
    // removed `agent` case, and exactly what refused `writer` on day one.
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->postJson(route('users.store'), [
            'name'  => 'Editor Novo',
            'email' => 'editor@leomadeiras.com.br',
            'role'  => 'writer',
        ])
        ->assertOk();

    expect(User::where('email', 'editor@leomadeiras.com.br')->sole()->role)->toBe(UserRole::Writer);
});

it('offers all three roles on the invitation form', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->get(route('users.index'))
        ->assertOk()
        ->assertSee('Visualizador')
        ->assertSee('Editor')
        ->assertSee('Administrador');
});
