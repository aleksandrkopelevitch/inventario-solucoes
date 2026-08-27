<?php

use App\Enums\UserRole;
use App\Models\Diagram;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\Solution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/**
 * The solution detail page's "integrações + documentação" card: two columns in
 * one frame, each its own updatable slot, each able to create its own kind of
 * record — and each with an illustrated empty state when it has none.
 */
function workspaceAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

it('renders both columns as separate slots inside the card', function () {
    $solution = Solution::factory()->create();

    $this->actingAs(workspaceAdmin())
        ->get(route('solutions.show', $solution))
        ->assertOk()
        ->assertSee('id="solution-diagram-titles-slot"', false)
        ->assertSee('id="solution-notebooks-slot"', false);
});

it('offers both creation affordances to an admin and neither to a viewer', function () {
    // Documentation is no longer created FROM here: a page belongs to a
    // caderno, so the right gesture on a solution is "novo caderno", which
    // opens the panel where it is named and linked in one go.
    $solution = Solution::factory()->create();

    $this->actingAs(workspaceAdmin())
        ->get(route('solutions.show', $solution))
        ->assertOk()
        ->assertSee('id="diagram-create-form"', false)
        ->assertSee(route('diagrams.store'), false)
        ->assertSee('Novo caderno')
        ->assertSee(route('notebooks.panel.create'), false);

    $this->actingAs(User::factory()->create()) // viewer
        ->get(route('solutions.show', $solution))
        ->assertOk()
        ->assertDontSee('id="diagram-create-form"', false)
        ->assertDontSee('Novo caderno');
});

it('draws an illustrated empty state on each column that has nothing', function () {
    $solution = Solution::factory()->create();

    // `focusable="false"` is carried only by the inlined unDraw SVGs, so it's
    // what tells an actually-rendered illustration from its caption alone.
    $this->actingAs(workspaceAdmin())
        ->get(route('solutions.show', $solution))
        ->assertOk()
        ->assertSee('Nenhum diagrama ainda')
        ->assertSee('Nenhum caderno vinculado')
        ->assertSee('focusable="false"', false);
});

it('replaces an empty state with the real list once each side has content', function () {
    $solution = Solution::factory()->create();
    $diagram = Diagram::factory()->create(['name' => 'SVL ↔ Digibee', 'status' => 'active']);
    attachParticipants($diagram, [[$solution, 0]]);

    $notebook = Notebook::factory()->create(['name' => 'Integrações SVL']);
    $notebook->solutions()->attach($solution);
    DocumentationPage::factory()->for($notebook)->create(['title' => 'Visão geral', 'documentation' => '# Doc']);

    $this->actingAs(workspaceAdmin())
        ->get(route('solutions.show', $solution))
        ->assertOk()
        ->assertSee('SVL ↔ Digibee')
        ->assertSee('Ativa')
        // The CADERNO is what this card names, with how much of it is written —
        // the page titles live one level in, inside the caderno itself.
        ->assertSee('Integrações SVL')
        ->assertSee('1/1')
        ->assertDontSee('Nenhum diagrama ainda')
        ->assertDontSee('Nenhum caderno vinculado');
});

it('shows the same caderno on every solution it documents', function () {
    // The point of the module, from the reading side: one body of text, two
    // detail pages, no duplication.
    [$first, $second] = Solution::factory()->count(2)->create()->all();
    $notebook = Notebook::factory()->create(['name' => 'Integração SAP ↔ SVL']);
    $notebook->solutions()->attach([$first->id, $second->id]);
    DocumentationPage::factory()->for($notebook)->create(['documentation' => '# Doc']);

    foreach ([$first, $second] as $solution) {
        $this->actingAs(workspaceAdmin())
            ->get(route('solutions.show', $solution))
            ->assertOk()
            ->assertSee('Integração SAP ↔ SVL');
    }
});

it('shows the diagram status in the editor top bar, editable in place for an admin', function () {
    $solution = Solution::factory()->create();
    $diagram = Diagram::factory()->create(['name' => 'Integração X', 'status' => 'in_development']);
    attachParticipants($diagram, [[$solution, 0]]);

    $response = $this->actingAs(workspaceAdmin())
        ->get(route('diagrams.show', $diagram))
        ->assertOk()
        ->assertSee('id="diagram-meta-slot"', false)
        ->assertSee('Em desenvolvimento')
        ->assertSee('data-ak-inline-edit-field="status"', false)
        ->assertSee('data-ak-inline-edit-field="name"', false);

    // The editor's endpoint rides inside a JSON attribute, so its slashes
    // arrive escaped. Asserting the raw `route()` string passed only because
    // the DELETE button's url happened to start with the same characters.
    expect($response->getContent())
        ->toContain(str_replace('/', '\\/', route('diagrams.update', $diagram)));
});

it('shows the same status as plain text to a viewer', function () {
    $solution = Solution::factory()->create();
    $diagram = Diagram::factory()->create(['name' => 'Integração X', 'status' => 'deprecated']);
    attachParticipants($diagram, [[$solution, 0]]);

    $this->actingAs(User::factory()->create()) // viewer
        ->get(route('diagrams.show', $diagram))
        ->assertOk()
        ->assertSee('Descontinuada')
        ->assertDontSee('data-ak-inline-edit-field="status"', false);
});
