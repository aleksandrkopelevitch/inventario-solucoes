<?php

use App\Enums\UserRole;
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
        ->assertSee('id="solution-relationships-slot"', false)
        ->assertSee('id="solution-notebooks-slot"', false);
});

it('offers "novo caderno" to an admin and no diagram creation to anybody', function () {
    // Neither documentation nor a drawing is created FROM here: a page belongs
    // to a caderno, and so does a diagram. The right gesture on a solution is
    // "novo caderno", which opens the panel where it is named and linked.
    $solution = Solution::factory()->create();

    $this->actingAs(workspaceAdmin())
        ->get(route('solutions.show', $solution))
        ->assertOk()
        ->assertDontSee('id="diagram-create-form"', false)
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
        ->assertSee('Nenhum relacionamento documentado')
        ->assertSee('Nenhum caderno vinculado')
        ->assertSee('focusable="false"', false);
});

it('replaces an empty state with the real list once each side has content', function () {
    $solution = Solution::factory()->create(['name' => 'SVL', 'category' => 'erp']);
    $digibee = Solution::factory()->create(['name' => 'Digibee', 'category' => 'erp']);

    $notebook = Notebook::factory()->create(['name' => 'Integrações SVL']);
    $notebook->solutions()->attach($solution);
    DocumentationPage::factory()->for($notebook)->create(['title' => 'Visão geral', 'documentation' => "# Doc\n\n{% uml type=\"sequence\" %}\n"
        . json_encode([
            'participants' => [['id' => 'svl', 'solution' => $solution->slug], ['id' => 'dgb', 'solution' => $digibee->slug]],
            'messages'     => [['from' => 'svl', 'to' => 'dgb', 'label' => 'pedido']],
        ]) . "\n{% enduml %}"]);

    $this->actingAs(workspaceAdmin())
        ->get(route('solutions.show', $solution))
        ->assertOk()
        ->assertSee('SVL chama Digibee')
        // The CADERNO is what this card names, with how much of it is written —
        // the page titles live one level in, inside the caderno itself.
        ->assertSee('Integrações SVL')
        ->assertSee('1/1')
        ->assertDontSee('Nenhum relacionamento documentado')
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
