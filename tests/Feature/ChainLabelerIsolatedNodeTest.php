<?php

use App\Models\Solution;
use App\Support\ChainLabeler;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('includes a block with no edges at all in the free-graph summary, alongside the connected ones', function () {
    $a = Solution::factory()->create(['name' => 'A']);
    $b = Solution::factory()->create(['name' => 'B']);
    $isolated = Solution::factory()->create(['name' => 'Isolado']);

    $labeler = new ChainLabeler;
    $chain = [
        'nodes' => [
            ['solution_id' => $a->id, 'label' => null],
            ['solution_id' => $b->id, 'label' => null],
            ['solution_id' => $isolated->id, 'label' => null],
        ],
        // Not linear (2 edges for 3 nodes would already fail isLinear
        // anyway) — forces the "list every link" branch of label().
        'edges' => [
            ['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null],
            ['from' => 1, 'to' => 0, 'arrow' => '->', 'protocol' => null],
        ],
    ];
    $solutions = $labeler->resolveSolutions(collect([$chain]));

    expect($labeler->label($chain, $solutions))->toBe('A -> B, B -> A, Isolado');
});

it('lists every block by name when a chain has nodes but no edges at all', function () {
    $a = Solution::factory()->create(['name' => 'A']);
    $b = Solution::factory()->create(['name' => 'B']);

    $labeler = new ChainLabeler;
    $chain = [
        'nodes' => [['solution_id' => $a->id, 'label' => null], ['solution_id' => $b->id, 'label' => null]],
        'edges' => [],
    ];
    $solutions = $labeler->resolveSolutions(collect([$chain]));

    expect($labeler->label($chain, $solutions))->toBe('A, B');
});
