<?php

use App\Enums\UserRole;
use App\Models\Integration;
use App\Models\Solution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function solutionIntegrationAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

it('creates an integration with the solution as the root node, ready for the data-viz to extend', function () {
    $solution = Solution::factory()->create(['name' => 'SVL']);

    $response = $this->actingAs(solutionIntegrationAdmin())
        ->postJson(route('solutions.integrations.store', $solution), ['name' => 'Nova integração'])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    $integration = Integration::where('name', 'Nova integração')->firstOrFail();

    expect($integration->status->value)->toBe('planned')
        ->and($integration->chain)->toBe([
            'nodes' => [['solution_id' => $solution->id, 'label' => null, 'kind' => 'system']],
            'edges' => [],
        ])
        ->and($integration->participants->pluck('id')->all())->toBe([$solution->id])
        // Creating one takes the user straight to its own page — there's
        // nothing about a brand-new integration the list it came from could show.
        ->and($response->json('redirect'))->toBe(route('solutions.integrations.docs.edit', [$solution, $integration]));
});

it('falls back to the solution name when creating an integration without a name', function () {
    $solution = Solution::factory()->create(['name' => 'SVL']);

    $this->actingAs(solutionIntegrationAdmin())
        ->postJson(route('solutions.integrations.store', $solution), [])
        ->assertOk();

    $this->assertDatabaseHas('integrations', ['name' => 'SVL']);
});

it('forbids non-admins from creating an integration', function () {
    $solution = Solution::factory()->create();

    $this->actingAs(User::factory()->create()) // viewer
        ->postJson(route('solutions.integrations.store', $solution), ['name' => 'Nova'])
        ->assertForbidden();
});

it('renames and changes the status of an existing integration without touching its chain', function () {
    $solution = Solution::factory()->create();
    $integration = Integration::factory()->create([
        'name'   => 'Antigo nome',
        'status' => 'active',
        'chain'  => ['nodes' => [['solution_id' => $solution->id, 'label' => null]], 'edges' => []],
    ]);
    attachParticipants($integration, [[$solution, 0]]);

    $this->actingAs(solutionIntegrationAdmin())
        ->patchJson(route('solutions.integrations.update', [$solution, $integration]), [
            'name'   => 'Novo nome',
            'status' => 'deprecated',
        ])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    $integration->refresh();

    expect($integration->name)->toBe('Novo nome')
        ->and($integration->status->value)->toBe('deprecated')
        ->and($integration->chain)->toBe(['nodes' => [['solution_id' => $solution->id, 'label' => null]], 'edges' => []]);
});

it('updates only the field the inline editor sent, leaving the other one alone', function () {
    $solution = Solution::factory()->create();
    $integration = Integration::factory()->create(['name' => 'Nome preservado', 'status' => 'planned']);
    attachParticipants($integration, [[$solution, 0]]);

    // The top bar's `x-ui.inline-edit` confirms one field at a time.
    $this->actingAs(solutionIntegrationAdmin())
        ->patchJson(route('solutions.integrations.update', [$solution, $integration]), ['status' => 'active'])
        ->assertOk();

    $integration->refresh();

    expect($integration->status->value)->toBe('active')
        ->and($integration->name)->toBe('Nome preservado');
});

it('refreshes the top bar and the pages rail after an inline meta edit', function () {
    $solution = Solution::factory()->create();
    $integration = Integration::factory()->create(['name' => 'Antigo', 'status' => 'planned']);
    attachParticipants($integration, [[$solution, 0]]);

    $response = $this->actingAs(solutionIntegrationAdmin())
        ->patchJson(route('solutions.integrations.update', [$solution, $integration]), ['name' => 'Novo'])
        ->assertOk();

    expect(collect($response->json('updatableSlots'))->pluck('id')->all())
        ->toBe(['integration-meta-slot', 'documentation-pages-nav-slot'])
        ->and($response->json('updatableSlots.0.content'))->toContain('Novo');
});

it('rejects blanking a field it was given', function () {
    $solution = Solution::factory()->create();
    $integration = Integration::factory()->create();
    attachParticipants($integration, [[$solution, 0]]);

    $this->actingAs(solutionIntegrationAdmin())
        ->patchJson(route('solutions.integrations.update', [$solution, $integration]), ['name' => ''])
        ->assertStatus(422);
});

it('forbids non-admins from renaming an integration', function () {
    $solution = Solution::factory()->create();
    $integration = Integration::factory()->create();
    attachParticipants($integration, [[$solution, 0]]);

    $this->actingAs(User::factory()->create()) // viewer
        ->patchJson(route('solutions.integrations.update', [$solution, $integration]), [
            'name'   => 'Tentativa',
            'status' => 'active',
        ])
        ->assertForbidden();
});
