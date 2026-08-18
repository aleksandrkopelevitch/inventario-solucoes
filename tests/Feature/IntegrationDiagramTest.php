<?php

use App\Enums\UserRole;
use App\Models\Integration;
use App\Models\Solution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

function diagramContext(): array
{
    $solution = Solution::factory()->create();
    $integration = Integration::factory()->create();
    attachParticipants($integration, [[$solution, 0], [Solution::factory()->create(), 1]]);

    return [$solution, $integration];
}

it('stores the canvas picture and replaces the previous one', function () {
    Storage::fake('public');
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    [$solution, $integration] = diagramContext();

    $this->postJson(route('solutions.integrations.diagram.store', [$solution, $integration]), [
        'image' => UploadedFile::fake()->image('canvas.png', 1600, 900),
    ])->assertOk();

    $this->postJson(route('solutions.integrations.diagram.store', [$solution, $integration]), [
        'image' => UploadedFile::fake()->image('canvas.png', 1600, 900),
    ])->assertOk();

    // singleFile(): only the current picture is ever wanted.
    expect($integration->fresh()->getMedia(Integration::DIAGRAM_COLLECTION))->toHaveCount(1)
        ->and($integration->fresh()->diagram()->file_name)->toBe("{$integration->slug}-diagrama.png");
});

it('accepts only a png the canvas could have produced', function () {
    Storage::fake('public');
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    [$solution, $integration] = diagramContext();

    $this->postJson(route('solutions.integrations.diagram.store', [$solution, $integration]), [
        'image' => UploadedFile::fake()->image('canvas.jpg'),
    ])->assertStatus(422)->assertJson(['type' => 'warning']);
});

it('never lets the picture touch the topology', function () {
    Storage::fake('public');
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    [$solution, $integration] = diagramContext();
    $before = $integration->fresh();

    $this->postJson(route('solutions.integrations.diagram.store', [$solution, $integration]), [
        'image' => UploadedFile::fake()->image('canvas.png'),
    ])->assertOk();

    $after = $integration->fresh();

    // The picture is derived. The chain stays the source of truth and the
    // derived columns must not move because someone saved a layout.
    expect($after->chain)->toEqual($before->chain)
        ->and($after->viz_layout)->toEqual($before->viz_layout)
        ->and($after->source_solution_id)->toBe($before->source_solution_id)
        ->and($after->target_solution_id)->toBe($before->target_solution_id)
        ->and($after->protocol)->toBe($before->protocol);
});

it('refuses a viewer trying to publish one', function () {
    Storage::fake('public');
    $this->actingAs(User::factory()->create(['role' => UserRole::Viewer]));

    [$solution, $integration] = diagramContext();

    $this->postJson(route('solutions.integrations.diagram.store', [$solution, $integration]), [
        'image' => UploadedFile::fake()->image('canvas.png'),
    ])->assertForbidden();
});

it('serves the stored picture and 404s when there is none', function () {
    Storage::fake('public');
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    [$solution, $integration] = diagramContext();

    $this->get(route('solutions.integrations.diagram.show', [$solution, $integration]))->assertNotFound();

    $this->postJson(route('solutions.integrations.diagram.store', [$solution, $integration]), [
        'image' => UploadedFile::fake()->image('canvas.png'),
    ])->assertOk();

    $this->get(route('solutions.integrations.diagram.show', [$solution, $integration]))->assertOk();
});

it('hands the canvas the endpoint to publish to', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    [$solution, $integration] = diagramContext();

    // Without this in the graph payload, publishDiagram() silently no-ops —
    // and a controller-level test would never notice, since the payload only
    // reaches the canvas through the rendered page.
    $html = $this->get(route('solutions.integrations.docs.edit', [$solution, $integration]))
        ->assertOk()
        ->getContent();

    // Decoded rather than matched as a substring: json_encode escapes `/` as
    // `\/`, so asserting the raw URL against the HTML fails for a payload
    // that is perfectly correct.
    preg_match('/data-integration-graph="([^"]*)"/', $html, $matches);
    $graph = json_decode(html_entity_decode($matches[1] ?? '', ENT_QUOTES), true);

    expect($graph['diagramUrl'] ?? null)
        ->toBe(route('solutions.integrations.diagram.store', [$solution, $integration]))
        ->and($graph['editable'])->toBeTrue();
});
