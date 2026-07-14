<?php

use App\Enums\UserRole;
use App\Models\Integration;
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

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $a->id, 'label' => null], ['solution_id' => $b->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => 'rest']],
        ],
    ]);
    attachParticipants($integration, [[$a, 0], [$b, 1]]);

    $response = $this->actingAs(edgeRemoveAdmin())
        ->deleteJson(route('solutions.integrations.chain.edge.remove', [$a, $integration, 0]))
        ->assertOk()
        ->assertJson(['type' => 'success']);

    $integration->refresh();

    expect($integration->chain['nodes'])->toHaveCount(2) // nós continuam existindo
        ->and($integration->chain['edges'])->toBeEmpty()
        ->and($integration->participants->pluck('name')->sort()->values()->all())->toBe(['A', 'B'])
        // Nenhuma ligação: o resumo lista os blocos, não uma seta entre eles.
        ->and($response->json('summary'))->toBe('A, B');
});

it('reindexes viz_layout.edges (anchors) when an earlier edge is removed', function () {
    $a = Solution::factory()->create();
    $b = Solution::factory()->create();
    $c = Solution::factory()->create();

    $integration = Integration::factory()->create([
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
                ['from' => 't', 'to' => 'b'], // âncora "custom" da 2ª ligação
            ],
        ],
    ]);
    attachParticipants($integration, [[$a, 0], [$b, 1], [$c, 2]]);

    $this->actingAs(edgeRemoveAdmin())
        ->deleteJson(route('solutions.integrations.chain.edge.remove', [$a, $integration, 0]))
        ->assertOk();

    $integration->refresh();

    // A âncora que sobrou é a da ligação que ficou (agora no índice 0), não a que foi removida.
    expect($integration->chain['edges'])->toHaveCount(1)
        ->and($integration->viz_layout['edges'])->toBe([['from' => 't', 'to' => 'b']]);
});

it('404s for an edge index outside the chain', function () {
    $a = Solution::factory()->create();
    $b = Solution::factory()->create();

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $a->id, 'label' => null], ['solution_id' => $b->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($integration, [[$a, 0], [$b, 1]]);

    $this->actingAs(edgeRemoveAdmin())
        ->deleteJson(route('solutions.integrations.chain.edge.remove', [$a, $integration, 3]))
        ->assertNotFound();
});

it('forbids non-admins from removing an edge', function () {
    $a = Solution::factory()->create();
    $b = Solution::factory()->create();

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $a->id, 'label' => null], ['solution_id' => $b->id, 'label' => null]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    attachParticipants($integration, [[$a, 0], [$b, 1]]);

    $this->actingAs(User::factory()->create()) // viewer
        ->deleteJson(route('solutions.integrations.chain.edge.remove', [$a, $integration, 0]))
        ->assertForbidden();
});
