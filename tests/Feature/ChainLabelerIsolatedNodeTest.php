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
        // Não linear (2 edges pra 3 nós já reprovaria isLinear de qualquer
        // forma) — força o ramo "lista cada ligação" de label().
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
