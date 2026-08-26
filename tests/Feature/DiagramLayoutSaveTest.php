<?php

use App\Enums\UserRole;
use App\Models\Diagram;
use App\Models\Solution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function layoutSolutionAndIntegration(): array
{
    $solution = Solution::factory()->create();
    $other = Solution::factory()->create();
    $diagram = Diagram::factory()->create(['chain' => [
        'nodes' => [['solution_id' => $solution->id, 'label' => null], ['solution_id' => $other->id, 'label' => null]],
        'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => 'rest']],
    ]]);
    attachParticipants($diagram, [[$solution, 0], [$other, 1]]);

    return [$solution, $diagram];
}

it('persists the visual layout (node positions + edge anchors) for an admin', function () {
    [$solution, $diagram] = layoutSolutionAndIntegration();

    $payload = [
        'nodes' => [['x' => 10, 'y' => -20], ['x' => 240, 'y' => 30]],
        'edges' => [['from' => 'b', 'to' => 'tl']],
    ];

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->patchJson(route('diagrams.layout.save', $diagram), $payload)
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($diagram->fresh()->viz_layout)->toBe($payload);
});

it('does not touch topology when saving the layout (chain stays the source of truth)', function () {
    [$solution, $diagram] = layoutSolutionAndIntegration();
    $before = $diagram->only(['source_solution_id', 'target_solution_id', 'direction', 'chain']);

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->patchJson(route('diagrams.layout.save', $diagram), [
            'nodes' => [['x' => 0, 'y' => 0], ['x' => 100, 'y' => 0]],
            'edges' => [['from' => 'r', 'to' => 'l']],
        ])
        ->assertOk();

    $fresh = $diagram->fresh();
    expect($fresh->only(['source_solution_id', 'target_solution_id', 'direction', 'chain']))->toEqual($before)
        ->and($fresh->participants()->count())->toBe(2);
});

it('forbids non-admins from saving the layout', function () {
    [$solution, $diagram] = layoutSolutionAndIntegration();

    $this->actingAs(User::factory()->create(['role' => UserRole::Viewer->value]))
        ->patchJson(route('diagrams.layout.save', $diagram), [
            'nodes' => [['x' => 0, 'y' => 0], ['x' => 1, 'y' => 1]],
            'edges' => [['from' => 'r', 'to' => 'l']],
        ])
        ->assertForbidden();

    expect($diagram->fresh()->viz_layout)->toBeNull();
});

it('rejects an unknown anchor key', function () {
    [$solution, $diagram] = layoutSolutionAndIntegration();

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->patchJson(route('diagrams.layout.save', $diagram), [
            'nodes' => [['x' => 0, 'y' => 0]],
            'edges' => [['from' => 'r', 'to' => 'middle']],
        ])
        ->assertStatus(422);
});

it('persists per-block color, text color and font from the contextual toolbar', function () {
    [$solution, $diagram] = layoutSolutionAndIntegration();

    $payload = [
        'nodes' => [
            ['x' => 0, 'y' => 0, 'color' => '#4A90D9', 'textColor' => '#FFFFFF', 'font' => 'mono'],
            ['x' => 240, 'y' => 30, 'color' => null, 'textColor' => null, 'font' => 'serif'],
        ],
        'edges' => [['from' => 'r', 'to' => 'l']],
    ];

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->patchJson(route('diagrams.layout.save', $diagram), $payload)
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($diagram->fresh()->viz_layout)->toBe($payload);
});

it('rejects an invalid font or a malformed hex color on a block', function () {
    [$solution, $diagram] = layoutSolutionAndIntegration();

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->patchJson(route('diagrams.layout.save', $diagram), [
            'nodes' => [['x' => 0, 'y' => 0, 'color' => 'not-a-color', 'font' => 'comic-sans']],
            'edges' => [],
        ])
        ->assertStatus(422);
});

it('persists a block\'s font size and logo-only toggle', function () {
    [$solution, $diagram] = layoutSolutionAndIntegration();

    $payload = [
        'nodes' => [
            ['x' => 0, 'y' => 0, 'fontSize' => 'lg', 'logoOnly' => true],
            ['x' => 240, 'y' => 30, 'fontSize' => 'md', 'logoOnly' => false],
        ],
        'edges' => [['from' => 'r', 'to' => 'l']],
    ];

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->patchJson(route('diagrams.layout.save', $diagram), $payload)
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($diagram->fresh()->viz_layout)->toBe($payload);
});

it('rejects an unknown font size or a non-boolean logo-only flag', function (array $overrides) {
    [$solution, $diagram] = layoutSolutionAndIntegration();

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->patchJson(route('diagrams.layout.save', $diagram), [
            'nodes' => [array_merge(['x' => 0, 'y' => 0], $overrides)],
            'edges' => [],
        ])
        ->assertStatus(422);
})->with([
    'invalid font size'     => [['fontSize' => 'xl']],
    'non-boolean logo-only' => [['logoOnly' => 'yes']],
]);

it('persists an image block\'s light border color', function () {
    [$solution, $diagram] = layoutSolutionAndIntegration();

    $payload = [
        'nodes' => [
            ['x' => 0, 'y' => 0, 'imageBorderColor' => '#FFFFFF'],
            ['x' => 240, 'y' => 30, 'imageBorderColor' => null],
        ],
        'edges' => [['from' => 'r', 'to' => 'l']],
    ];

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->patchJson(route('diagrams.layout.save', $diagram), $payload)
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($diagram->fresh()->viz_layout)->toBe($payload);
});

it('rejects a malformed hex color for a block\'s image border', function () {
    [$solution, $diagram] = layoutSolutionAndIntegration();

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->patchJson(route('diagrams.layout.save', $diagram), [
            'nodes' => [['x' => 0, 'y' => 0, 'imageBorderColor' => 'not-a-color']],
            'edges' => [],
        ])
        ->assertStatus(422);
});

it('persists swimlanes and per-block/per-edge dashed toggles', function () {
    [$solution, $diagram] = layoutSolutionAndIntegration();

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
        ->patchJson(route('diagrams.layout.save', $diagram), $payload)
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($diagram->fresh()->viz_layout)->toBe($payload);
});

it('rejects a swimlane with an invalid color or an out-of-range height', function () {
    [$solution, $diagram] = layoutSolutionAndIntegration();

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->patchJson(route('diagrams.layout.save', $diagram), [
            'nodes' => [['x' => 0, 'y' => 0]],
            'edges' => [],
            'lanes' => [['label' => 'GCP', 'color' => 'not-a-color', 'x' => 0, 'y' => 0, 'width' => 400, 'height' => 20]],
        ])
        ->assertStatus(422);
});

it('persists a swimlane\'s corner/border/opacity/orientation/title/header-color/font-size style', function () {
    [$solution, $diagram] = layoutSolutionAndIntegration();

    $payload = [
        'nodes' => [['x' => 0, 'y' => 0], ['x' => 240, 'y' => 30]],
        'edges' => [['from' => 'r', 'to' => 'l']],
        'lanes' => [
            [
                'label'       => 'GCP', 'color' => '#2F6FED', 'headerColor' => '#000000',
                'x'           => -50, 'y' => -50, 'width' => 420, 'height' => 220,
                'rounded'     => true, 'dashed' => true, 'opacity' => 0.22,
                'orientation' => 'vertical', 'showTitle' => false, 'fontSize' => 'lg',
            ],
        ],
    ];

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->patchJson(route('diagrams.layout.save', $diagram), $payload)
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($diagram->fresh()->viz_layout)->toBe($payload);
});

it('persists the canvas theme', function () {
    [$solution, $diagram] = layoutSolutionAndIntegration();

    $payload = [
        'nodes' => [['x' => 0, 'y' => 0], ['x' => 240, 'y' => 30]],
        'edges' => [['from' => 'r', 'to' => 'l']],
        'theme' => 'tech',
    ];

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->patchJson(route('diagrams.layout.save', $diagram), $payload)
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($diagram->fresh()->viz_layout)->toBe($payload);
});

it('rejects an unknown theme', function () {
    [$solution, $diagram] = layoutSolutionAndIntegration();

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->patchJson(route('diagrams.layout.save', $diagram), [
            'theme' => 'neon-cyberpunk',
            'nodes' => [['x' => 0, 'y' => 0]],
            'edges' => [],
        ])
        ->assertStatus(422);
});

it('rejects a swimlane with an invalid header color, orientation, font size or out-of-range opacity', function (array $overrides) {
    [$solution, $diagram] = layoutSolutionAndIntegration();

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->patchJson(route('diagrams.layout.save', $diagram), [
            'nodes' => [['x' => 0, 'y' => 0]],
            'edges' => [],
            'lanes' => [array_merge(
                ['label' => 'GCP', 'color' => '#2F6FED', 'x' => 0, 'y' => 0, 'width' => 400, 'height' => 200],
                $overrides
            )],
        ])
        ->assertStatus(422);
})->with([
    'invalid header color' => [['headerColor' => 'not-a-color']],
    'invalid orientation'  => [['orientation' => 'diagonal']],
    'invalid font size'    => [['fontSize' => 'xl']],
    'opacity too high'     => [['opacity' => 0.9]],
    'opacity too low'      => [['opacity' => 0.001]],
    'non-boolean rounded'  => [['rounded' => 'yes']],
]);

it('silently drops a stale swimlane "pattern" field instead of rejecting it', function () {
    [$solution, $diagram] = layoutSolutionAndIntegration();

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->patchJson(route('diagrams.layout.save', $diagram), [
            'nodes' => [['x' => 0, 'y' => 0]],
            'edges' => [],
            'lanes' => [['label' => 'GCP', 'color' => '#2F6FED', 'x' => 0, 'y' => 0, 'width' => 400, 'height' => 200, 'pattern' => 'diagonal']],
        ])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($diagram->fresh()->viz_layout['lanes'][0])->not->toHaveKey('pattern');
});

it('persists post-it annotations', function () {
    [$solution, $diagram] = layoutSolutionAndIntegration();

    $payload = [
        'nodes' => [['x' => 0, 'y' => 0], ['x' => 240, 'y' => 30]],
        'edges' => [['from' => 'r', 'to' => 'l']],
        'notes' => [
            ['x' => -80, 'y' => -120, 'text' => "Confirmar com o time de infra\nantes de mudar o protocolo."],
            ['x' => 300, 'y' => 200, 'text' => ''],
        ],
    ];

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->patchJson(route('diagrams.layout.save', $diagram), $payload)
        ->assertOk()
        ->assertJson(['type' => 'success']);

    // The `web` group's `ConvertEmptyStringsToNull` middleware normalizes an
    // empty string request input to `null` before it ever reaches the Form
    // Request — same reason every OTHER nullable-string field in this suite
    // (`color`, `textColor`, ...) is tested with `null`, never `''`.
    $expected = $payload;
    $expected['notes'][1]['text'] = null;

    expect($diagram->fresh()->viz_layout)->toBe($expected);
});

it('rejects a post-it annotation missing a position or with an over-long text', function (array $overrides) {
    [$solution, $diagram] = layoutSolutionAndIntegration();

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->patchJson(route('diagrams.layout.save', $diagram), [
            'nodes' => [['x' => 0, 'y' => 0]],
            'edges' => [],
            'notes' => [array_merge(['x' => 0, 'y' => 0, 'text' => 'ok'], $overrides)],
        ])
        ->assertStatus(422);
})->with([
    'missing x'     => [['x' => null]],
    'missing y'     => [['y' => null]],
    'text too long' => [['text' => str_repeat('a', 4001)]],
]);
