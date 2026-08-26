<?php

use App\Enums\UserRole;
use App\Models\Diagram;
use App\Models\Solution;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function edgeRemoveAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

it('removes an edge without deleting the nodes it connected, leaving a block isolated', function () {
    $a = Solution::factory()->create(['name' => 'A']);
    $b = Solution::factory()->create(['name' => 'B']);

    $diagram = Diagram::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $a->id, 'label' => null], ['solution_id' => $b->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => 'rest']],
        ],
    ]);
    attachParticipants($diagram, [[$a, 0], [$b, 1]]);

    $response = $this->actingAs(edgeRemoveAdmin())
        ->deleteJson(route('diagrams.chain.edge.remove', [$diagram, 0]))
        ->assertOk()
        ->assertJson(['type' => 'success']);

    $diagram->refresh();

    expect($diagram->chain['nodes'])->toHaveCount(2) // nodes still exist
        ->and($diagram->chain['edges'])->toBeEmpty()
        ->and($diagram->participants->pluck('name')->sort()->values()->all())->toBe(['A', 'B'])
        // No link: the summary lists the blocks, not an arrow between them.
        ->and($response->json('summary'))->toBe('A, B');
});

it('reindexes viz_layout.edges (anchors) when an earlier edge is removed', function () {
    $a = Solution::factory()->create();
    $b = Solution::factory()->create();
    $c = Solution::factory()->create();

    $diagram = Diagram::factory()->create([
        'chain' => [
            'nodes' => [
                ['solution_id' => $a->id, 'label' => null],
                ['solution_id' => $b->id, 'label' => null],
                ['solution_id' => $c->id, 'label' => null],
            ],
            'edges' => [
                ['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null],
                ['from' => 1, 'to' => 2, 'arrow' => '->', 'protocol' => null],
            ],
        ],
        'viz_layout' => [
            'edges' => [
                ['from' => 'r', 'to' => 'l'],
                ['from' => 't', 'to' => 'b'], // "custom" anchor of the 2nd link
            ],
        ],
    ]);
    attachParticipants($diagram, [[$a, 0], [$b, 1], [$c, 2]]);

    $this->actingAs(edgeRemoveAdmin())
        ->deleteJson(route('diagrams.chain.edge.remove', [$diagram, 0]))
        ->assertOk();

    $diagram->refresh();

    // The remaining anchor belongs to the link that's left (now at index 0), not the one that was removed.
    expect($diagram->chain['edges'])->toHaveCount(1)
        ->and($diagram->viz_layout['edges'])->toBe([['from' => 't', 'to' => 'b']]);
});

it('404s for an edge index outside the chain', function () {
    $a = Solution::factory()->create();
    $b = Solution::factory()->create();

    $diagram = Diagram::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $a->id, 'label' => null], ['solution_id' => $b->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($diagram, [[$a, 0], [$b, 1]]);

    $this->actingAs(edgeRemoveAdmin())
        ->deleteJson(route('diagrams.chain.edge.remove', [$diagram, 3]))
        ->assertNotFound();
});

it('forbids non-admins from removing an edge', function () {
    $a = Solution::factory()->create();
    $b = Solution::factory()->create();

    $diagram = Diagram::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $a->id, 'label' => null], ['solution_id' => $b->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($diagram, [[$a, 0], [$b, 1]]);

    $this->actingAs(User::factory()->create()) // viewer
        ->deleteJson(route('diagrams.chain.edge.remove', [$diagram, 0]))
        ->assertForbidden();
});
