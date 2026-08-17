<?php

use App\Enums\UserRole;
use App\Models\DocumentationPage;
use App\Models\Integration;
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
        ->assertSee('id="solution-integration-titles-slot"', false)
        ->assertSee('id="solution-documentation-slot"', false);
});

it('offers both creation forms to an admin and neither to a viewer', function () {
    $solution = Solution::factory()->create();

    $this->actingAs(workspaceAdmin())
        ->get(route('solutions.show', $solution))
        ->assertOk()
        ->assertSee('id="integration-create-form"', false)
        ->assertSee(route('solutions.integrations.store', $solution), false)
        ->assertSee('id="solution-page-create-form"', false)
        ->assertSee(route('solutions.docs.pages.store', $solution), false);

    $this->actingAs(User::factory()->create()) // viewer
        ->get(route('solutions.show', $solution))
        ->assertOk()
        ->assertDontSee('id="integration-create-form"', false)
        ->assertDontSee('id="solution-page-create-form"', false);
});

it('draws an illustrated empty state on each column that has nothing', function () {
    $solution = Solution::factory()->create();

    // `focusable="false"` is carried only by the inlined unDraw SVGs, so it's
    // what tells an actually-rendered illustration from its caption alone.
    $this->actingAs(workspaceAdmin())
        ->get(route('solutions.show', $solution))
        ->assertOk()
        ->assertSee('Nenhuma integração cadastrada')
        ->assertSee('Nenhuma documentação cadastrada')
        ->assertSee('focusable="false"', false);
});

it('replaces an empty state with the real list once each side has content', function () {
    $solution = Solution::factory()->create();
    $integration = Integration::factory()->create(['name' => 'SVL ↔ Digibee', 'status' => 'active']);
    attachParticipants($integration, [[$solution, 0]]);
    DocumentationPage::factory()->create(['container_type' => $solution->getMorphClass(), 'container_id' => $solution->id, 'title' => 'Visão geral']);

    $this->actingAs(workspaceAdmin())
        ->get(route('solutions.show', $solution))
        ->assertOk()
        ->assertSee('SVL ↔ Digibee')
        ->assertSee('Ativa')
        ->assertSee('Visão geral')
        ->assertDontSee('Nenhuma integração cadastrada')
        ->assertDontSee('Nenhuma documentação cadastrada');
});

it('shows the integration status in the editor top bar, editable in place for an admin', function () {
    $solution = Solution::factory()->create();
    $integration = Integration::factory()->create(['name' => 'Integração X', 'status' => 'in_development']);
    attachParticipants($integration, [[$solution, 0]]);

    $response = $this->actingAs(workspaceAdmin())
        ->get(route('solutions.integrations.docs.edit', [$solution, $integration]))
        ->assertOk()
        ->assertSee('id="integration-meta-slot"', false)
        ->assertSee('Em desenvolvimento')
        ->assertSee('data-ak-inline-edit-field="status"', false)
        ->assertSee('data-ak-inline-edit-field="name"', false);

    expect($response->getContent())->toContain(route('solutions.integrations.update', [$solution, $integration]));
});

it('shows the same status as plain text to a viewer', function () {
    $solution = Solution::factory()->create();
    $integration = Integration::factory()->create(['name' => 'Integração X', 'status' => 'deprecated']);
    attachParticipants($integration, [[$solution, 0]]);

    $this->actingAs(User::factory()->create()) // viewer
        ->get(route('solutions.integrations.docs.edit', [$solution, $integration]))
        ->assertOk()
        ->assertSee('Descontinuada')
        ->assertDontSee('data-ak-inline-edit-field="status"', false);
});
