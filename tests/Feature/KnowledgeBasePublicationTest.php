<?php

use App\Enums\UserRole;
use App\Models\Notebook;
use App\Models\User;
use App\View\Components\Docs\PublicationList;
use App\View\Components\Notebooks\SharePanel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

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
    // The settings list and the caderno's own share panel are different
    // screens; `ajax-slot.js` no-ops on an id that is not on the current page,
    // so sending both is safe and sending one leaves the other stale.
    $notebook = Notebook::factory()->create();

    $response = $this->actingAs(publisher())
        ->patchJson(route('notebooks.publication', $notebook), ['published' => true])
        ->assertOk();

    expect(array_column($response->json('updatableSlots'), 'id'))
        ->toContain(PublicationList::DOM_ID)
        ->toContain(SharePanel::DOM_ID);
});

it('refuses everybody but an admin', function () {
    $notebook = Notebook::factory()->create();

    // An EDITOR is the one that matters: they may rewrite every page of this
    // caderno and must not be able to decide the whole company reads it.
    foreach ([UserRole::Reader, UserRole::Viewer, UserRole::Writer] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]))
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
    $this->actingAs(User::factory()->create(['role' => UserRole::Writer]))
        ->patchJson(route('notebooks.update', $notebook), [
            'name'         => 'Renomeado',
            'published_at' => now()->toDateTimeString(),
        ])
        ->assertOk();

    expect($notebook->fresh()->isPublished())->toBeFalse()
        ->and($notebook->fresh()->name)->toBe('Renomeado');
});

it('shows the publication state and the filters on the settings screen', function () {
    Notebook::factory()->published()->create(['name' => 'Caderno publicado']);
    Notebook::factory()->create(['name' => 'Caderno guardado']);

    $this->actingAs(publisher())
        ->get(route('docs.settings'))
        ->assertOk()
        ->assertSee('Caderno publicado')
        ->assertSee('Caderno guardado');

    $this->actingAs(publisher())
        ->get(route('docs.settings', ['filter' => ['status' => 'published']]))
        ->assertOk()
        ->assertSee('Caderno publicado')
        ->assertDontSee('Caderno guardado');
});

it('narrows the settings list with the same folded search the catalog uses', function () {
    Notebook::factory()->create(['name' => 'Integração de Pedidos']);
    Notebook::factory()->create(['name' => 'Outro assunto']);

    // Folded on both sides: written without accents, found with them.
    $this->actingAs(publisher())
        ->get(route('docs.settings', ['filter' => ['search' => 'integracao']]))
        ->assertOk()
        ->assertSee('Integração de Pedidos')
        ->assertDontSee('Outro assunto');
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
