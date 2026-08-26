<?php

use App\Enums\UserRole;
use App\Models\Diagram;
use App\Models\Solution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

function diagramContext(): array
{
    $solution = Solution::factory()->create();
    $diagram = Diagram::factory()->create();
    attachParticipants($diagram, [[$solution, 0], [Solution::factory()->create(), 1]]);

    return [$solution, $diagram];
}

it('stores the canvas picture and replaces the previous one', function () {
    Storage::fake('public');
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    [$solution, $diagram] = diagramContext();

    $this->postJson(route('diagrams.picture.store', $diagram), [
        'image' => UploadedFile::fake()->image('canvas.png', 1600, 900),
    ])->assertOk();

    $this->postJson(route('diagrams.picture.store', $diagram), [
        'image' => UploadedFile::fake()->image('canvas.png', 1600, 900),
    ])->assertOk();

    // singleFile(): only the current picture is ever wanted.
    expect($diagram->fresh()->getMedia(Diagram::DIAGRAM_COLLECTION))->toHaveCount(1)
        ->and($diagram->fresh()->picture()->file_name)->toBe("{$diagram->slug}-diagrama.png");
});

it('accepts only a png the canvas could have produced', function () {
    Storage::fake('public');
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    [$solution, $diagram] = diagramContext();

    $this->postJson(route('diagrams.picture.store', $diagram), [
        'image' => UploadedFile::fake()->image('canvas.jpg'),
    ])->assertStatus(422)->assertJson(['type' => 'warning']);
});

it('never lets the picture touch the topology', function () {
    Storage::fake('public');
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    [$solution, $diagram] = diagramContext();
    $before = $diagram->fresh();

    $this->postJson(route('diagrams.picture.store', $diagram), [
        'image' => UploadedFile::fake()->image('canvas.png'),
    ])->assertOk();

    $after = $diagram->fresh();

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

    [$solution, $diagram] = diagramContext();

    $this->postJson(route('diagrams.picture.store', $diagram), [
        'image' => UploadedFile::fake()->image('canvas.png'),
    ])->assertForbidden();
});

it('serves the stored picture and 404s when there is none', function () {
    Storage::fake('public');
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    [$solution, $diagram] = diagramContext();

    $this->get(route('diagrams.picture.show', $diagram))->assertNotFound();

    $this->postJson(route('diagrams.picture.store', $diagram), [
        'image' => UploadedFile::fake()->image('canvas.png'),
    ])->assertOk();

    $this->get(route('diagrams.picture.show', $diagram))->assertOk();
});

it('hands the canvas the endpoint to publish to', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    [$solution, $diagram] = diagramContext();

    // Without this in the graph payload, publishDiagram() silently no-ops —
    // and a controller-level test would never notice, since the payload only
    // reaches the canvas through the rendered page.
    $html = $this->get(route('diagrams.show', $diagram))
        ->assertOk()
        ->getContent();

    // Decoded rather than matched as a substring: json_encode escapes `/` as
    // `\/`, so asserting the raw URL against the HTML fails for a payload
    // that is perfectly correct.
    preg_match('/data-ak-chain-graph="([^"]*)"/', $html, $matches);
    $graph = json_decode(html_entity_decode($matches[1] ?? '', ENT_QUOTES), true);

    expect($graph['diagramUrl'] ?? null)
        ->toBe(route('diagrams.picture.store', $diagram))
        ->and($graph['editable'])->toBeTrue();
});
