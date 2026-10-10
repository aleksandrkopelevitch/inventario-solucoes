<?php

use App\Enums\UserRole;
use App\Models\Notebook;
use App\Models\User;
use App\View\Components\Notebooks\Index;
use App\View\Components\Notebooks\SharePanel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(LazilyRefreshDatabase::class);

function publisher(): User
{
    return User::factory()->create(['role' => UserRole::Admin]);
}

it('publishes a caderno and takes it back out', function () {
    $notebook = Notebook::factory()->create();

    $this->actingAs(publisher())
        ->patchJson(route('notebooks.publication', $notebook), ['published' => true])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($notebook->fresh()->isPublished())->toBeTrue();

    $this->actingAs(publisher())
        ->patchJson(route('notebooks.publication', $notebook), ['published' => false])
        ->assertOk();

    expect($notebook->fresh()->isPublished())->toBeFalse();
});

it('answers with both screens that can flip the switch', function () {
    // The cadernos catalog and the caderno's own share panel are different
    // screens; `ajax-slot.js` no-ops on an id that is not on the current page,
    // so sending both is safe and sending one leaves the other stale.
    $notebook = Notebook::factory()->create();

    $response = $this->actingAs(publisher())
        ->patchJson(route('notebooks.publication', $notebook), ['published' => true])
        ->assertOk();

    expect(array_column($response->json('updatableSlots'), 'id'))
        ->toContain(Index::DOM_ID)
        ->toContain(SharePanel::DOM_ID);
});

it('refuses everybody but an admin', function () {
    $notebook = Notebook::factory()->create();

    // An EDITOR is the one that matters: they may rewrite every page of this
    // caderno and must not be able to decide the whole company reads it.
    foreach ([User::factory()->create(), User::factory()->editor()->create()] as $user) {
        $this->actingAs($user)
            ->patchJson(route('notebooks.publication', $notebook), ['published' => true])
            ->assertForbidden();
    }

    expect($notebook->fresh()->isPublished())->toBeFalse();
});

it('cannot be published by posting the column to the panel an editor can reach', function () {
    $notebook = Notebook::factory()->create();

    // `published_at` is outside `$fillable` for exactly this reason: the rename
    // panel is `update` (editor) while publishing is `administer` (admin), and
    // a fillable column is one posted field away from collapsing the two.
    $this->actingAs(User::factory()->editor()->create())
        ->patchJson(route('notebooks.update', $notebook), [
            'name'         => 'Renomeado',
            'published_at' => now()->toDateTimeString(),
        ])
        ->assertOk();

    expect($notebook->fresh()->isPublished())->toBeFalse()
        ->and($notebook->fresh()->name)->toBe('Renomeado');
});

it('narrows the catalog to what is and is not published', function () {
    Notebook::factory()->published()->create(['name' => 'Caderno publicado']);
    Notebook::factory()->create(['name' => 'Caderno guardado']);

    $this->actingAs(publisher())
        ->get(route('notebooks.index'))
        ->assertOk()
        ->assertSee('Caderno publicado')
        ->assertSee('Caderno guardado');

    $this->actingAs(publisher())
        ->get(route('notebooks.index', ['filter' => ['status' => 'published']]))
        ->assertOk()
        ->assertSee('Caderno publicado')
        ->assertDontSee('Caderno guardado');

    $this->actingAs(publisher())
        ->get(route('notebooks.index', ['filter' => ['status' => 'unpublished']]))
        ->assertOk()
        ->assertSee('Caderno guardado')
        ->assertDontSee('Caderno publicado');
});

it('gives the catalog switch to an admin and only the state to everybody else', function () {
    $published = Notebook::factory()->published()->create(['name' => 'Caderno publicado']);
    $draft = Notebook::factory()->create(['name' => 'Caderno guardado']);

    $admin = $this->actingAs(publisher())->get(route('notebooks.index'))->assertOk()->getContent();

    expect($admin)
        ->toContain(route('notebooks.publication', $published))
        ->toContain(route('notebooks.publication', $draft))
        ->toContain($published->knowledgeBaseUrl());

    // An EDITOR writes every page and still does not decide what the whole
    // company reads: they see that it is published, with no switch.
    $editor = $this->actingAs(User::factory()->editor()->create())
        ->get(route('notebooks.index'))->assertOk()->getContent();

    expect($editor)
        ->not->toContain(route('notebooks.publication', $published))
        ->not->toContain(route('notebooks.publication', $draft))
        ->toContain($published->knowledgeBaseUrl());
});

it('keeps the catalog filters on the slot the switch rebuilds', function () {
    Notebook::factory()->published()->create(['name' => 'Caderno publicado']);
    $draft = Notebook::factory()->create(['name' => 'Caderno guardado']);

    // Publishing from the "Não publicados" view: the rebuilt slot is that
    // view, so the row the admin just published leaves it.
    $response = $this->actingAs(publisher())
        ->patchJson(route('notebooks.publication', ['notebook' => $draft, 'filter' => ['status' => 'unpublished']]), ['published' => true])
        ->assertOk();

    $slot = collect($response->json('updatableSlots'))->firstWhere('id', Index::DOM_ID)['content'];

    expect($slot)->not->toContain('Caderno guardado')
        ->not->toContain('Caderno publicado');
});

it('no longer has a settings screen of its own', function () {
    // Folded into the catalog. `docs/settings` now reads as a caderno slug,
    // and there is no caderno called that.
    expect(Route::has('docs.settings'))->toBeFalse();

    $this->actingAs(publisher())
        ->get('/docs/settings')
        ->assertNotFound();
});

it('rejects a payload without the boolean', function () {
    $notebook = Notebook::factory()->create();

    $this->actingAs(publisher())
        ->patchJson(route('notebooks.publication', $notebook), [])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);
});

it('states both audiences on the caderno share panel', function () {
    // The panel renders inside the documentation reader rather than at a route
    // of its own, so it is asserted through the slot it is served as. The two
    // switches sit side by side because they are constantly mistaken for each
    // other: one is every Leo account, the other is anybody holding a URL.
    $notebook = Notebook::factory()->published()->create();

    $this->actingAs(publisher());

    $html = SharePanel::slot($notebook)['content'];

    expect($html)
        ->toContain('Base de conhecimento')
        ->toContain('Link público (sem login)')
        ->toContain(route('notebooks.publication', $notebook))
        ->toContain($notebook->knowledgeBaseUrl())
        // Published, so the switch reads as on.
        ->toContain('aria-pressed="true"');
});
