<?php

use App\Contracts\Documentable;
use App\Enums\UserRole;
use App\Models\Diagram;
use App\Models\Solution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

function imageAddAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

it('appends an image block from a pasted picture, storing it in the docs media collection', function () {
    Storage::fake('public');

    $svl = Solution::factory()->create(['name' => 'SVL']);

    $diagram = Diagram::factory()->create([
        'chain' => ['nodes' => [['solution_id' => $svl->id, 'label' => null]], 'edges' => []],
    ]);
    attachParticipants($diagram, [[$svl, 0]]);

    $response = $this->actingAs(imageAddAdmin())
        ->postJson(route('diagrams.chain.image.add', $diagram), [
            'image' => UploadedFile::fake()->image('diagram.png', 400, 300),
        ])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($response->json('node.kind'))->toBe('image')
        ->and($response->json('node.mediaUrl'))->not->toBeNull()
        ->and($response->json('node.solution'))->toBeFalse();

    $diagram->refresh();

    expect($diagram->chain['nodes'])->toHaveCount(2)
        ->and($diagram->chain['nodes'][1]['kind'])->toBe('image')
        ->and($diagram->chain['nodes'][1]['media_id'])->toBeInt()
        // Born isolated, exactly like any other new block.
        ->and($diagram->chain['edges'])->toBeEmpty();

    $media = $diagram->getMedia(Documentable::DOCS_COLLECTION)->firstWhere('id', $diagram->chain['nodes'][1]['media_id']);
    expect($media)->not->toBeNull();
});

it('rejects a non-image file', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);

    $diagram = Diagram::factory()->create([
        'chain' => ['nodes' => [['solution_id' => $svl->id, 'label' => null]], 'edges' => []],
    ]);
    attachParticipants($diagram, [[$svl, 0]]);

    $this->actingAs(imageAddAdmin())
        ->postJson(route('diagrams.chain.image.add', $diagram), [
            'image' => UploadedFile::fake()->create('doc.pdf', 10),
        ])
        ->assertStatus(422);

    expect($diagram->fresh()->chain['nodes'])->toHaveCount(1);
});

it('forbids non-admins from pasting an image on the canvas', function () {
    $svl = Solution::factory()->create(['name' => 'SVL']);

    $diagram = Diagram::factory()->create([
        'chain' => ['nodes' => [['solution_id' => $svl->id, 'label' => null]], 'edges' => []],
    ]);
    attachParticipants($diagram, [[$svl, 0]]);

    $this->actingAs(User::factory()->create()) // viewer
        ->postJson(route('diagrams.chain.image.add', $diagram), [
            'image' => UploadedFile::fake()->image('diagram.png'),
        ])
        ->assertForbidden();
});
