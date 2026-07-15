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
            'nodes' => [['solution_id' => $solution->id, 'label' => null]],
            'edges' => [],
        ])
        ->and($integration->participants->pluck('id')->all())->toBe([$solution->id])
        ->and($response->json('js'))->toContain($integration->slug);
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

it('rejects updating an integration without a name', function () {
    $solution = Solution::factory()->create();
    $integration = Integration::factory()->create();
    attachParticipants($integration, [[$solution, 0]]);

    $this->actingAs(solutionIntegrationAdmin())
        ->patchJson(route('solutions.integrations.update', [$solution, $integration]), ['status' => 'active'])
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

it('shows the flowspec attached via the F8 chat in the integration side panel', function () {
    $solution = Solution::factory()->create();
    $integration = Integration::factory()->create([
        'generated_flowspec'    => ['meta' => [], 'flowSpec' => ['disconnected-root:x' => []]],
        'flowspec_status'       => 'validated',
        'flowspec_generated_at' => now(),
    ]);
    attachParticipants($integration, [[$solution, 0]]);

    $response = $this->actingAs(User::factory()->create()) // viewer — leitura, sem restrição de admin
        ->getJson(route('solutions.integrations.flowspec', [$solution, $integration]))
        ->assertOk();

    expect($response->json('content'))
        ->toContain('flowSpec validado')
        ->toContain('disconnected-root:x');
});

it('404s the flowspec panel when the integration does not belong to the solution (scoped binding)', function () {
    $solution = Solution::factory()->create();
    $otherIntegration = Integration::factory()->create();

    $this->actingAs(User::factory()->create())
        ->getJson(route('solutions.integrations.flowspec', [$solution, $otherIntegration]))
        ->assertNotFound();
});
