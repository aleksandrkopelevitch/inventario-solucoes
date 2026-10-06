<?php

use App\Enums\AccessLevel;
use App\Enums\AccessModule;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Diagram;
use App\Models\DocumentationPage;
use App\Models\FlowspecChat;
use App\Models\Notebook;
use App\Models\Person;
use App\Models\Solution;
use App\Models\Submission;
use App\Models\User;
use Database\Seeders\AttributeOptionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Access levels per module
|--------------------------------------------------------------------------
|
| Every account READS every module. A module's Editor level CREATES and
| EDITS inside it (App\Enums\AccessModule × AccessLevel, on `users.access`),
| and the admin keeps everything that is not content — accounts and their
| levels, the attribute vocabulary, every DELETE, publishing a caderno and its
| protected values.
|
*/

function writer(): User
{
    return User::factory()->editor()->create();
}

function viewerUser(): User
{
    return User::factory()->create(['role' => UserRole::Member->value]);
}

function editorOf(AccessModule ...$modules): User
{
    return User::factory()->editor(...$modules)->create();
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

    $this->actingAs(writer())->postJson(route('notebooks.diagrams.store', $notebook), ['name' => 'Fluxo novo'])->assertOk();
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
        ->postJson(route('users.store'), ['name' => 'X', 'email' => 'x@leomadeiras.com.br', 'role' => 'member'])
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
| A Reader (the default) reads and writes nothing
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
    $this->actingAs(viewerUser())->postJson(route('notebooks.diagrams.store', $notebook), ['name' => 'X'])->assertForbidden();
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
| One module's Editor is nobody else's
|--------------------------------------------------------------------------
*/

it('lets a catalog Editor edit the catalog and nothing else', function () {
    $this->seed(AttributeOptionSeeder::class);
    $user = editorOf(AccessModule::Catalog);
    $notebook = Notebook::factory()->create();

    $this->actingAs($user)->postJson(route('people.store'), ['name' => 'Maria'])->assertOk();

    $this->actingAs($user)->postJson(route('notebooks.store'), ['name' => 'X'])->assertForbidden();
    $this->actingAs($user)->postJson(route('notebooks.pages.store', $notebook), ['title' => 'X'])->assertForbidden();
});

it('lets a documentation Editor write cadernos and diagrams but not the catalog', function () {
    $this->seed(AttributeOptionSeeder::class);
    $user = editorOf(AccessModule::Documentation);

    $this->actingAs($user)->postJson(route('notebooks.store'), ['name' => 'Caderno'])->assertOk();
    $notebook = Notebook::where('name', 'Caderno')->sole();
    $this->actingAs($user)->postJson(route('notebooks.diagrams.store', $notebook), ['name' => 'Fluxo'])->assertOk();

    $this->actingAs($user)
        ->postJson(route('solutions.store'), ['name' => 'X', 'category' => 'erp', 'status' => 'active'])
        ->assertForbidden();
});

it('keeps deleting a page with the admin, even for a documentation Editor', function () {
    $page = DocumentationPage::factory()->create();

    $this->actingAs(editorOf(AccessModule::Documentation))
        ->deleteJson(route('notebooks.pages.destroy', [$page->notebook, $page]))
        ->assertForbidden();
    $this->assertModelExists($page);

    $this->actingAs(User::factory()->admin()->create())
        ->deleteJson(route('notebooks.pages.destroy', [$page->notebook, $page]))
        ->assertOk();
    $this->assertModelMissing($page);
});

it('hides the page delete action from a documentation Editor', function () {
    $page = DocumentationPage::factory()->create(['title' => 'Página']);

    // The destroy URL is the page's own URL (DELETE vs GET), so the action is
    // recognised by its confirmation instead.
    $this->actingAs(editorOf(AccessModule::Documentation))
        ->get(route('notebooks.pages.edit', [$page->notebook, $page]))
        ->assertOk()
        ->assertDontSee('Esta ação não pode ser desfeita');

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('notebooks.pages.edit', [$page->notebook, $page]))
        ->assertSee('Esta ação não pode ser desfeita');
});

it('gives a new account Reader in the catalog and the documentation, None elsewhere', function () {
    $user = User::factory()->create();

    expect($user->canView(AccessModule::Catalog))->toBeTrue()
        ->and($user->canView(AccessModule::Documentation))->toBeTrue()
        ->and($user->canEdit(AccessModule::Catalog))->toBeFalse()
        ->and($user->canView(AccessModule::Integrations))->toBeFalse()
        ->and($user->canView(AccessModule::Committee))->toBeFalse();

    $this->actingAs($user)->get(route('solutions.index'))->assertOk();
    $this->actingAs($user)->get(route('notebooks.index'))->assertOk();
    $this->actingAs($user)->get(route('flowspec.index'))->assertForbidden();
    $this->actingAs($user)->get(route('submissions.index'))->assertForbidden();
});

it('lets every account read the map', function () {
    $this->actingAs(viewerUser())->get(route('solutions.map'))->assertOk();
});

/*
|--------------------------------------------------------------------------
| Comitê de Arquitetura
|--------------------------------------------------------------------------
*/

it('lets only a committee Editor open a submission', function () {
    $this->actingAs(viewerUser())->get(route('submissions.create'))->assertForbidden();
    $this->actingAs(editorOf(AccessModule::Catalog))->get(route('submissions.create'))->assertForbidden();

    $this->actingAs(editorOf(AccessModule::Committee))->get(route('submissions.create'))->assertOk();
});

it('lets a committee Editor change only the submissions they created', function () {
    $author = editorOf(AccessModule::Committee);
    $mine = Submission::factory()->create(['created_by_id' => $author->id]);
    $theirs = Submission::factory()->create();

    expect($author->can('update', $mine))->toBeTrue()
        ->and($author->can('update', $theirs))->toBeFalse()
        ->and(User::factory()->admin()->create()->can('update', $theirs))->toBeTrue();
});

it('stops an author who is no longer a committee Editor from changing their own', function () {
    $author = User::factory()->reader()->create();
    $submission = Submission::factory()->create(['created_by_id' => $author->id]);

    expect($author->can('view', $submission))->toBeTrue()
        ->and($author->can('update', $submission))->toBeFalse();
});

it('keeps deleting a submission with the admin, even for its author', function () {
    $author = editorOf(AccessModule::Committee);
    $submission = Submission::factory()->create(['created_by_id' => $author->id]);

    $this->actingAs($author)->deleteJson(route('submissions.destroy', $submission))->assertForbidden();
    $this->assertModelExists($submission);
});

/*
|--------------------------------------------------------------------------
| Especialista em Integrações
|--------------------------------------------------------------------------
*/

it('lets every account read every conversation, but write only in their own as an Editor', function () {
    $owner = editorOf(AccessModule::Integrations);
    $chat = FlowspecChat::factory()->for($owner)->create(['title' => 'Integração SAP']);
    $reader = User::factory()->reader()->create();

    $this->actingAs($reader)->get(route('flowspec.index'))->assertOk()->assertSee('Integração SAP');
    $this->actingAs($reader)->get(route('flowspec.show', $chat))->assertOk()->assertSee('somente leitura');

    expect($reader->can('create', FlowspecChat::class))->toBeFalse()
        ->and($reader->can('update', $chat))->toBeFalse()
        ->and($reader->can('run', $chat))->toBeFalse()
        ->and($owner->can('update', $chat))->toBeTrue()
        ->and($owner->can('run', $chat))->toBeTrue()
        // Another Editor reads it and does not continue it.
        ->and(editorOf(AccessModule::Integrations)->can('update', $chat))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Setting the levels (admin)
|--------------------------------------------------------------------------
*/

it('lets an admin set a member\'s level in one module', function () {
    $member = viewerUser();

    $this->actingAs(User::factory()->admin()->create())
        ->patchJson(route('users.access.update', $member), ['committee' => 'editor'])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($member->fresh()->canEdit(AccessModule::Committee))->toBeTrue()
        ->and($member->fresh()->canEdit(AccessModule::Catalog))->toBeFalse();

    // Back to the module's default (None for the Comitê): nothing stored.
    $this->actingAs(User::factory()->admin()->create())
        ->patchJson(route('users.access.update', $member), ['committee' => 'none'])
        ->assertOk();

    expect($member->fresh()->access)->toBeNull();
});

it('refuses setting levels to anybody but an admin', function () {
    $member = viewerUser();

    $this->actingAs(writer())
        ->patchJson(route('users.access.update', $member), ['catalog' => 'editor'])
        ->assertForbidden();

    expect($member->fresh()->access)->toBeNull();
});

it('refuses an unknown module or level, and levels on an admin', function () {
    $admin = User::factory()->admin()->create();
    $member = viewerUser();

    $this->actingAs($admin)->patchJson(route('users.access.update', $member), ['billing' => 'editor'])->assertStatus(422);
    $this->actingAs($admin)->patchJson(route('users.access.update', $member), ['catalog' => 'owner'])->assertStatus(422);
    $this->actingAs($admin)
        ->patchJson(route('users.access.update', User::factory()->admin()->create()), ['catalog' => 'reader'])
        ->assertStatus(422);
});

it('shows each member\'s levels on the accounts roster', function () {
    editorOf(AccessModule::Committee);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('people.accounts'))
        ->assertOk()
        ->assertSee('Comitê')
        ->assertSee('Leitor')
        ->assertSee('Editor')
        // JSON-encoded, the way the inline editor carries it.
        ->assertSee(trim(json_encode(route('users.access.update', User::where('role', 'member')->first())), '"'), false);
});

it('offers member and admin on the invitation form', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('people.accounts'))
        ->assertOk()
        ->assertSee('Usuário')
        ->assertSee('Administrador');
});

it('invites as a member who reads every module', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->postJson(route('users.store'), ['name' => 'Nova', 'email' => 'nova@leomadeiras.com.br', 'role' => 'member'])
        ->assertOk();

    $user = User::where('email', 'nova@leomadeiras.com.br')->sole();

    expect($user->role)->toBe(UserRole::Member)
        ->and($user->access)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| None: the module does not exist for this account
|--------------------------------------------------------------------------
*/

function closedTo(AccessModule ...$modules): User
{
    $user = User::factory()->reader()->create();
    foreach ($modules as $module) {
        $user->setAccessLevel($module, AccessLevel::None);
    }
    $user->save();

    return $user;
}

it('lets an admin close a module to a member', function () {
    $member = viewerUser();

    $this->actingAs(User::factory()->admin()->create())
        ->patchJson(route('users.access.update', $member), ['catalog' => 'none'])
        ->assertOk();

    expect($member->fresh()->access)->toBe(['catalog' => 'none'])
        ->and($member->fresh()->canView(AccessModule::Catalog))->toBeFalse();
});

it('removes a closed module from the sidebar and leaves the others', function () {
    $html = $this->actingAs(closedTo(AccessModule::Catalog, AccessModule::Committee))
        ->get(route('profile.show'))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain(route('solutions.index'))
        ->and($html)->not->toContain(route('people.index'))
        ->and($html)->not->toContain(route('submissions.index'))
        ->and($html)->toContain(route('notebooks.index'))
        ->and($html)->toContain(route('flowspec.index'))
        // The map belongs to no module.
        ->and($html)->toContain(route('solutions.map'));
});

it('lets a documentation reader with no catalog read cadernos and nothing of the catalog', function () {
    $user = closedTo(AccessModule::Catalog);
    $page = DocumentationPage::factory()->create(['title' => 'Arquitetura']);

    $this->actingAs($user)->get(route('notebooks.pages.edit', [$page->notebook, $page]))->assertOk();
    $this->actingAs($user)->get(route('solutions.index'))->assertForbidden();
    $this->actingAs($user)->get(route('solutions.spreadsheet'))->assertForbidden();
    $this->actingAs($user)->getJson(route('solutions.search', ['q' => 'abc']))->assertForbidden();
});

it('drops the documentation card and columns for a catalog reader with no documentation', function () {
    $this->seed(AttributeOptionSeeder::class);
    $solution = Solution::factory()->create();
    $user = closedTo(AccessModule::Documentation);

    $this->actingAs($user)->get(route('solutions.show', $solution))
        ->assertOk()
        ->assertDontSee('solution-notebooks-slot', false);

    $sheet = $this->actingAs($user)->get(route('solutions.spreadsheet'))->assertOk()->getContent();
    expect($sheet)->not->toContain('"key":"notebooks"')
        ->and($sheet)->not->toContain('"key":"diagrams"');

    $csv = $this->actingAs($user)
        ->get(route('solutions.spreadsheet.export', ['format' => 'csv', 'columns' => ['name', 'notebooks']]))
        ->streamedContent();
    expect($csv)->not->toContain('Cadernos');
});

it('refuses attaching documentation in the Especialista without the documentation module', function () {
    $user = User::factory()->editor(AccessModule::Integrations)->create();
    $user->setAccessLevel(AccessModule::Documentation, AccessLevel::None);
    $user->save();
    $page = DocumentationPage::factory()->create(['documentation' => '# x']);

    $this->actingAs($user)
        ->postJson(route('flowspec.store'), ['message' => 'Gere algo', 'documents' => ['page:' . $page->id]])
        ->assertStatus(422);
    $this->actingAs($user)->getJson(route('flowspec.attachments.picker'))->assertForbidden();
    $this->actingAs($user)
        ->getJson(route('flowspec.documents.search', ['q' => 'x']))
        ->assertOk()
        ->assertJson(['results' => []]);
});

it('shows a None badge on the roster', function () {
    closedTo(AccessModule::Catalog);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('people.accounts'))
        ->assertSee('Nenhum');
});
