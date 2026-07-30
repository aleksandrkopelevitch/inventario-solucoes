<?php

use App\Enums\UserRole;
use App\Models\Integration;
use App\Models\Solution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function layoutSolutionAndIntegration(): array
{
    $solution = Solution::factory()->create();
    $other = Solution::factory()->create();
    $integration = Integration::factory()->create(['chain' => [
        'nodes' => [['solution_id' => $solution->id, 'label' => null], ['solution_id' => $other->id, 'label' => null]],
        'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => 'rest']],
    ]]);
    attachParticipants($integration, [[$solution, 0], [$other, 1]]);

    return [$solution, $integration];
}

it('persists the visual layout (node positions + edge anchors) for an admin', function () {
    [$solution, $integration] = layoutSolutionAndIntegration();

    $payload = [
        'nodes' => [['x' => 10, 'y' => -20], ['x' => 240, 'y' => 30]],
        'edges' => [['from' => 'b', 'to' => 'tl']],
    ];

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->patchJson(route('solutions.integrations.layout.save', [$solution, $integration]), $payload)
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($integration->fresh()->viz_layout)->toBe($payload);
});

it('does not touch topology when saving the layout (chain stays the source of truth)', function () {
    [$solution, $integration] = layoutSolutionAndIntegration();
    $before = $integration->only(['source_solution_id', 'target_solution_id', 'direction', 'chain']);

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->patchJson(route('solutions.integrations.layout.save', [$solution, $integration]), [
            'nodes' => [['x' => 0, 'y' => 0], ['x' => 100, 'y' => 0]],
            'edges' => [['from' => 'r', 'to' => 'l']],
        ])
        ->assertOk();

    $fresh = $integration->fresh();
    expect($fresh->only(['source_solution_id', 'target_solution_id', 'direction', 'chain']))->toEqual($before)
        ->and($fresh->participants()->count())->toBe(2);
});

it('forbids non-admins from saving the layout', function () {
    [$solution, $integration] = layoutSolutionAndIntegration();

    $this->actingAs(User::factory()->create(['role' => UserRole::Viewer->value]))
        ->patchJson(route('solutions.integrations.layout.save', [$solution, $integration]), [
            'nodes' => [['x' => 0, 'y' => 0], ['x' => 1, 'y' => 1]],
            'edges' => [['from' => 'r', 'to' => 'l']],
        ])
        ->assertForbidden();

    expect($integration->fresh()->viz_layout)->toBeNull();
});

it('rejects an unknown anchor key', function () {
    [$solution, $integration] = layoutSolutionAndIntegration();

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->patchJson(route('solutions.integrations.layout.save', [$solution, $integration]), [
            'nodes' => [['x' => 0, 'y' => 0]],
            'edges' => [['from' => 'r', 'to' => 'middle']],
        ])
        ->assertStatus(422);
});

it('persists per-block color, text color and font from the contextual toolbar', function () {
    [$solution, $integration] = layoutSolutionAndIntegration();

    $payload = [
        'nodes' => [
            ['x' => 0, 'y' => 0, 'color' => '#4A90D9', 'textColor' => '#FFFFFF', 'font' => 'mono'],
            ['x' => 240, 'y' => 30, 'color' => null, 'textColor' => null, 'font' => 'serif'],
        ],
        'edges' => [['from' => 'r', 'to' => 'l']],
    ];

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->patchJson(route('solutions.integrations.layout.save', [$solution, $integration]), $payload)
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($integration->fresh()->viz_layout)->toBe($payload);
});

it('rejects an invalid font or a malformed hex color on a block', function () {
    [$solution, $integration] = layoutSolutionAndIntegration();

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->patchJson(route('solutions.integrations.layout.save', [$solution, $integration]), [
            'nodes' => [['x' => 0, 'y' => 0, 'color' => 'not-a-color', 'font' => 'comic-sans']],
            'edges' => [],
        ])
        ->assertStatus(422);
});

it('persists an image block\'s light border color', function () {
    [$solution, $integration] = layoutSolutionAndIntegration();

    $payload = [
        'nodes' => [
            ['x' => 0, 'y' => 0, 'imageBorderColor' => '#FFFFFF'],
            ['x' => 240, 'y' => 30, 'imageBorderColor' => null],
        ],
        'edges' => [['from' => 'r', 'to' => 'l']],
    ];

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->patchJson(route('solutions.integrations.layout.save', [$solution, $integration]), $payload)
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($integration->fresh()->viz_layout)->toBe($payload);
});

it('rejects a malformed hex color for a block\'s image border', function () {
    [$solution, $integration] = layoutSolutionAndIntegration();

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->patchJson(route('solutions.integrations.layout.save', [$solution, $integration]), [
            'nodes' => [['x' => 0, 'y' => 0, 'imageBorderColor' => 'not-a-color']],
            'edges' => [],
        ])
        ->assertStatus(422);
});

it('persists swimlanes and per-block/per-edge dashed toggles', function () {
    [$solution, $integration] = layoutSolutionAndIntegration();

    $payload = [
        'nodes' => [
            ['x' => 0, 'y' => 0, 'dashed' => true],
            ['x' => 240, 'y' => 30, 'dashed' => false],
        ],
        'edges' => [['from' => 'r', 'to' => 'l', 'dashed' => true]],
        'lanes' => [
            ['label' => 'GCP', 'color' => '#2F6FED', 'x' => -50, 'y' => -50, 'width' => 420, 'height' => 220],
            ['label' => 'Digibee', 'color' => '#7C3AED', 'x' => 400, 'y' => -50, 'width' => 360, 'height' => 180],
        ],
    ];

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->patchJson(route('solutions.integrations.layout.save', [$solution, $integration]), $payload)
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($integration->fresh()->viz_layout)->toBe($payload);
});

it('rejects a swimlane with an invalid color or an out-of-range height', function () {
    [$solution, $integration] = layoutSolutionAndIntegration();

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->patchJson(route('solutions.integrations.layout.save', [$solution, $integration]), [
            'nodes' => [['x' => 0, 'y' => 0]],
            'edges' => [],
            'lanes' => [['label' => 'GCP', 'color' => 'not-a-color', 'x' => 0, 'y' => 0, 'width' => 400, 'height' => 20]],
        ])
        ->assertStatus(422);
});
