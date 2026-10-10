<?php

use App\Contracts\Documentable;
use App\Enums\SubmissionDiagramKind;
use App\Enums\UserRole;
use App\Models\Diagram;
use App\Models\Solution;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

function restoreAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

/** A layout entry per node and per edge — the shape the canvas posts. */
function restoreLayout(int $nodes, int $edges, array $overrides = []): array
{
    return [
        'nodes' => array_map(fn (int $i) => ['x' => $i * 300, 'y' => 0], range(0, $nodes - 1)),
        'edges' => array_fill(0, $edges, ['from' => 'r', 'to' => 'l', 'dashed' => false]),
        ...$overrides,
    ];
}

/** SVL -> Consulta o CPF. */
function restoreDiagram(): array
{
    $svl = Solution::factory()->create(['name' => 'SVL']);

    $diagram = Diagram::factory()->create([
        'chain' => [
            'nodes' => [
                ['solution_id' => $svl->id, 'label' => null, 'kind' => 'system'],
                ['solution_id' => null, 'label' => 'Consulta o CPF', 'kind' => 'step'],
            ],
            'edges' => [
                ['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => 'rest'],
            ],
        ],
    ]);
    attachParticipants($diagram, [[$svl, 0]]);

    return [$diagram, $svl];
}

it('answers every chain mutation with the stored chain, which is what the undo history records', function () {
    [$diagram] = restoreDiagram();

    $response = $this->actingAs(restoreAdmin())
        ->postJson(route('diagrams.chain.node.add', $diagram), ['kind' => 'step', 'label' => 'Aprova'])
        ->assertOk();

    expect($response->json('chain'))->toBe($diagram->fresh()->chain)
        ->and($response->json('chain.nodes.2.label'))->toBe('Aprova');

    // The page's own payload carries it too, so a history can start from it.
    $html = $this->get(route('diagrams.show', $diagram))->assertOk()->getContent();
    expect($html)->toContain('&quot;chain&quot;');
});

it('writes back a whole earlier state — undoing a deleted block brings it and its link back', function () {
    [$diagram, $svl] = restoreDiagram();
    $admin = restoreAdmin();
    $before = $diagram->chain;

    $this->actingAs($admin)->deleteJson(route('diagrams.chain.node.remove', [$diagram, 1]))->assertOk();
    expect($diagram->fresh()->chain['nodes'])->toHaveCount(1);

    $layout = restoreLayout(2, 1, ['edges' => [['from' => 'b', 'to' => 't', 'dashed' => true, 'labelT' => 0.25]]]);

    $response = $this->actingAs($admin)
        ->putJson(route('diagrams.chain.restore', $diagram), ['chain' => $before, 'layout' => $layout])
        ->assertOk()
        ->assertJson(['type' => 'success']);

    $diagram->refresh();

    expect($diagram->chain)->toBe($before)
        ->and($diagram->viz_layout['edges'][0])->toMatchArray(['from' => 'b', 'to' => 't', 'labelT' => 0.25])
        ->and($response->json('graph.nodes'))->toHaveCount(2)
        ->and($response->json('graph.nodes.1.label'))->toBe('Consulta o CPF')
        ->and($response->json('chain'))->toBe($before)
        // The derived columns are a reading of the chain, so they follow it.
        ->and($diagram->participants->pluck('id')->all())->toBe([$svl->id])
        ->and($diagram->protocol)->toBe('rest');
});

it('keeps the stored root, whatever the payload says it is', function () {
    [$diagram, $svl] = restoreDiagram();
    $other = Solution::factory()->create(['name' => 'Outro']);

    $chain = $diagram->chain;
    $chain['nodes'][0] = ['solution_id' => $other->id, 'label' => null, 'kind' => 'system'];

    $this->actingAs(restoreAdmin())
        ->putJson(route('diagrams.chain.restore', $diagram), ['chain' => $chain, 'layout' => restoreLayout(2, 1)])
        ->assertOk();

    expect($diagram->fresh()->chain['nodes'][0]['solution_id'])->toBe($svl->id);
});

it('drops a picture that is not one of this drawing\'s own', function () {
    Storage::fake('public');
    [$diagram] = restoreDiagram();
    [$elsewhere] = restoreDiagram();

    $own = $diagram->addMedia(UploadedFile::fake()->image('own.png'))->toMediaCollection(Documentable::DOCS_COLLECTION);
    $foreign = $elsewhere->addMedia(UploadedFile::fake()->image('theirs.png'))->toMediaCollection(Documentable::DOCS_COLLECTION);

    $chain = $diagram->chain;
    $chain['nodes'][1]['media_id'] = $foreign->id;
    $chain['nodes'][] = ['solution_id' => null, 'label' => 'Imagem', 'kind' => 'image', 'media_id' => $own->id];

    $this->actingAs(restoreAdmin())
        ->putJson(route('diagrams.chain.restore', $diagram), ['chain' => $chain, 'layout' => restoreLayout(3, 1)])
        ->assertOk();

    $nodes = $diagram->fresh()->chain['nodes'];

    expect($nodes[1])->not->toHaveKey('media_id')
        ->and($nodes[2]['media_id'])->toBe($own->id);
});

it('refuses an edge pointing past the last block, and a layout that does not line up', function () {
    [$diagram] = restoreDiagram();
    $admin = restoreAdmin();

    $chain = $diagram->chain;
    $chain['edges'][] = ['from' => 0, 'to' => 7, 'arrow' => '->', 'protocol' => null];

    $response = $this->actingAs($admin)
        ->putJson(route('diagrams.chain.restore', $diagram), ['chain' => $chain, 'layout' => restoreLayout(2, 2)])
        ->assertUnprocessable();
    expect($response->json('message'))->toContain('bloco que não existe');

    // One layout entry short: positions are keyed by index, so the second
    // block would silently inherit nothing — or somebody else's.
    $response = $this->actingAs($admin)
        ->putJson(route('diagrams.chain.restore', $diagram), ['chain' => $diagram->chain, 'layout' => restoreLayout(1, 1)])
        ->assertUnprocessable();
    expect($response->json('message'))->toContain('não corresponde')
        ->and($diagram->fresh()->chain['edges'])->toHaveCount(1);
});

it('lets only an editor restore', function () {
    [$diagram] = restoreDiagram();

    $this->actingAs(User::factory()->reader()->create())
        ->putJson(route('diagrams.chain.restore', $diagram), ['chain' => $diagram->chain, 'layout' => restoreLayout(2, 1)])
        ->assertForbidden();
});

it('restores a submission\'s drawing through its own route', function () {
    $admin = restoreAdmin();
    $this->actingAs($admin);
    $submission = Submission::factory()->withSections()->create(['created_by_id' => $admin->id]);
    $drawing = $submission->diagram(SubmissionDiagramKind::ToBe);
    $before = $drawing->chain;

    $this->postJson(route('submissions.diagrams.chain.node.add', [$submission, $drawing]), ['kind' => 'step', 'label' => 'Novo'])
        ->assertOk();

    $this->putJson(route('submissions.diagrams.chain.restore', [$submission, $drawing]), [
        'chain'  => $before,
        'layout' => restoreLayout(count($before['nodes']), count($before['edges'] ?? [])),
    ])->assertOk();

    expect($drawing->fresh()->chain['nodes'])->toHaveCount(count($before['nodes']));
});
