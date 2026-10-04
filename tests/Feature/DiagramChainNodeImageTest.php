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

function nodeImageAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

/** A root system block, a step and a decision — the step is the one that takes a picture. */
function nodeImageDiagram(): Diagram
{
    $svl = Solution::factory()->create(['name' => 'SVL']);

    $diagram = Diagram::factory()->create([
        'chain' => [
            'nodes' => [
                ['solution_id' => $svl->id, 'label' => null],
                ['solution_id' => null, 'label' => 'Consulta o CPF', 'kind' => 'step'],
                ['solution_id' => null, 'label' => 'Tem cadastro?', 'kind' => 'decision'],
            ],
            'edges' => [],
        ],
    ]);
    attachParticipants($diagram, [[$svl, 0]]);

    return $diagram;
}

it('puts a picture inside an action block without adding a block', function () {
    Storage::fake('public');
    $diagram = nodeImageDiagram();

    $response = $this->actingAs(nodeImageAdmin())
        ->postJson(route('diagrams.chain.node.image.store', [$diagram, 1]), [
            'image' => UploadedFile::fake()->image('logo.png', 120, 80),
        ])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    expect($response->json('node.kind'))->toBe('step')
        ->and($response->json('node.label'))->toBe('Consulta o CPF')
        ->and($response->json('node.mediaUrl'))->not->toBeNull();

    $diagram->refresh();
    $mediaId = $diagram->chain['nodes'][1]['media_id'];

    expect($diagram->chain['nodes'])->toHaveCount(3)
        ->and($mediaId)->toBeInt()
        ->and($diagram->getMedia(Documentable::DOCS_COLLECTION)->pluck('id')->all())->toBe([$mediaId]);
});

it('deletes the picture it replaces, and the one it removes', function () {
    Storage::fake('public');
    $diagram = nodeImageDiagram();
    $admin = nodeImageAdmin();

    $this->actingAs($admin)->postJson(route('diagrams.chain.node.image.store', [$diagram, 1]), [
        'image' => UploadedFile::fake()->image('first.png'),
    ])->assertOk();
    $first = $diagram->fresh()->chain['nodes'][1]['media_id'];

    $this->actingAs($admin)->postJson(route('diagrams.chain.node.image.store', [$diagram, 1]), [
        'image' => UploadedFile::fake()->image('second.png'),
    ])->assertOk();
    $second = $diagram->fresh()->chain['nodes'][1]['media_id'];

    expect($second)->not->toBe($first)
        ->and($diagram->fresh()->getMedia(Documentable::DOCS_COLLECTION)->pluck('id')->all())->toBe([$second]);

    $this->actingAs($admin)
        ->deleteJson(route('diagrams.chain.node.image.remove', [$diagram, 1]))
        ->assertOk()
        ->assertJsonPath('node.mediaUrl', null);

    expect($diagram->fresh()->chain['nodes'][1])->not->toHaveKey('media_id')
        ->and($diagram->fresh()->getMedia(Documentable::DOCS_COLLECTION))->toBeEmpty();
});

it('keeps the block picture when the block is renamed', function () {
    Storage::fake('public');
    $diagram = nodeImageDiagram();
    $admin = nodeImageAdmin();

    $this->actingAs($admin)->postJson(route('diagrams.chain.node.image.store', [$diagram, 1]), [
        'image' => UploadedFile::fake()->image('logo.png'),
    ])->assertOk();
    $mediaId = $diagram->fresh()->chain['nodes'][1]['media_id'];

    $this->actingAs($admin)
        ->patchJson(route('diagrams.chain.node.update', [$diagram, 1]), ['kind' => 'step', 'label' => 'Consulta o CNPJ'])
        ->assertOk()
        ->assertJsonPath('node.label', 'Consulta o CNPJ');

    expect($diagram->fresh()->chain['nodes'][1]['media_id'])->toBe($mediaId);
});

it('refuses a picture inside a decision', function () {
    Storage::fake('public');
    $diagram = nodeImageDiagram();

    $this->actingAs(nodeImageAdmin())
        ->postJson(route('diagrams.chain.node.image.store', [$diagram, 2]), [
            'image' => UploadedFile::fake()->image('logo.png'),
        ])
        ->assertStatus(422);

    expect($diagram->fresh()->chain['nodes'][2])->not->toHaveKey('media_id')
        ->and($diagram->fresh()->getMedia(Documentable::DOCS_COLLECTION))->toBeEmpty();
});

it('rejects a non-image file and a block that does not exist', function () {
    $diagram = nodeImageDiagram();

    $this->actingAs(nodeImageAdmin())
        ->postJson(route('diagrams.chain.node.image.store', [$diagram, 1]), [
            'image' => UploadedFile::fake()->create('doc.pdf', 10),
        ])
        ->assertStatus(422);

    $this->actingAs(nodeImageAdmin())
        ->postJson(route('diagrams.chain.node.image.store', [$diagram, 9]), [
            'image' => UploadedFile::fake()->image('logo.png'),
        ])
        ->assertNotFound();
});

it('forbids a viewer from putting a picture in a block', function () {
    $diagram = nodeImageDiagram();

    $this->actingAs(User::factory()->create())
        ->postJson(route('diagrams.chain.node.image.store', [$diagram, 1]), [
            'image' => UploadedFile::fake()->image('logo.png'),
        ])
        ->assertForbidden();
});

it('deletes a block picture together with the block', function () {
    Storage::fake('public');
    $diagram = nodeImageDiagram();
    $admin = nodeImageAdmin();

    $this->actingAs($admin)->postJson(route('diagrams.chain.node.image.store', [$diagram, 1]), [
        'image' => UploadedFile::fake()->image('logo.png'),
    ])->assertOk();
    expect($diagram->fresh()->getMedia(Documentable::DOCS_COLLECTION))->toHaveCount(1);

    $this->actingAs($admin)
        ->deleteJson(route('diagrams.chain.node.remove', [$diagram, 1]))
        ->assertOk();

    expect($diagram->fresh()->chain['nodes'])->toHaveCount(2)
        ->and($diagram->fresh()->getMedia(Documentable::DOCS_COLLECTION))->toBeEmpty();
});

it('refuses an SVG, which would be served back as a document', function () {
    Storage::fake('public');
    $diagram = nodeImageDiagram();

    $this->actingAs(nodeImageAdmin())
        ->postJson(route('diagrams.chain.node.image.store', [$diagram, 1]), [
            'image' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        ])
        ->assertStatus(422);
});
