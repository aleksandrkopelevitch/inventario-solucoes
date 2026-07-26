<?php

use App\Enums\Direction;
use App\Models\Integration;
use App\Models\Solution;
use App\Services\IntegrationGraphService;
use Database\Seeders\AttributeOptionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('builds a graph with every participant as a node and an edge per consecutive pair', function () {
    $service = new IntegrationGraphService;

    $root = Solution::factory()->create(['category' => 'erp']);
    $target = Solution::factory()->create();
    $digibee = Solution::factory()->create(['category' => 'ipaas']);

    $integration = Integration::factory()->active()->create([
        'source_solution_id' => $root->id,
        'target_solution_id' => $target->id,
        'direction'          => Direction::Unidirectional,
    ]);
    attachParticipants($integration, [
        [$root, 0],
        [$digibee, 1],
        [$target, 2],
    ]);

    $graph = $service->globalMap();

    $nodeIds = collect($graph['nodes'])->pluck('id');
    expect($nodeIds)->toContain("sol-{$root->id}", "sol-{$digibee->id}", "sol-{$target->id}")
        ->and($nodeIds)->toHaveCount(3);

    $rootNode = collect($graph['nodes'])->firstWhere('id', "sol-{$root->id}");
    expect($rootNode['category'])->toBe('erp');

    expect($graph['edges'])->toHaveCount(2);
    $edge = $graph['edges'][0];
    expect($edge['source'])->toBe("sol-{$root->id}")
        ->and($edge['target'])->toBe("sol-{$digibee->id}")
        ->and($edge['status'])->toBe('active')
        ->and($edge['direction'])->toBe('unidirectional')
        ->and($edge['label'])->toBe('REST') // falls back to the scalar protocol when there's no chain
        ->and($edge)->not->toHaveKey('orchestrator');

    expect($graph['edges'][1]['source'])->toBe("sol-{$digibee->id}")
        ->and($graph['edges'][1]['target'])->toBe("sol-{$target->id}");
});

it('includes the solution attribute labels and show url on each node, for the map popover', function () {
    $this->seed(AttributeOptionSeeder::class);
    $service = new IntegrationGraphService;

    $root = Solution::factory()->create(['category' => 'erp', 'status' => 'active']);
    $target = Solution::factory()->create();

    $integration = Integration::factory()->active()->create(['source_solution_id' => $root->id, 'target_solution_id' => $target->id]);
    attachParticipants($integration, [[$root, 0], [$target, 1]]);

    $graph = $service->globalMap();
    $rootNode = collect($graph['nodes'])->firstWhere('id', "sol-{$root->id}");

    expect($rootNode['url'])->toBe(route('solutions.show', $root))
        ->and($rootNode['categoryLabel'])->toBe('ERP')
        ->and($rootNode['statusLabel'])->toBe('Ativo')
        ->and($rootNode['directorate'])->toBe($root->directorate)
        ->and($rootNode)->toHaveKeys(['criticalityLabel', 'environmentLabel', 'cloudLabel', 'contractLabel', 'supportLabel']);
});

it('exposes the saved hub map position and its auto-save url, null until the first drag', function () {
    $service = new IntegrationGraphService;

    $root = Solution::factory()->create();
    $target = Solution::factory()->create(['map_position' => ['x' => 123.4, 'y' => 56.7]]);

    $integration = Integration::factory()->active()->create(['source_solution_id' => $root->id, 'target_solution_id' => $target->id]);
    attachParticipants($integration, [[$root, 0], [$target, 1]]);

    $graph = $service->globalMap();
    $rootNode = collect($graph['nodes'])->firstWhere('id', "sol-{$root->id}");
    $targetNode = collect($graph['nodes'])->firstWhere('id', "sol-{$target->id}");

    expect($rootNode['mapPosition'])->toBeNull()
        ->and($rootNode['positionUrl'])->toBe(route('solutions.map.position.update', $root))
        ->and($targetNode['mapPosition'])->toBe(['x' => 123.4, 'y' => 56.7]);
});

it('draws a multi-hop chain in position order, not a single A<>B edge', function () {
    $service = new IntegrationGraphService;

    $a = Solution::factory()->create();
    $middle = Solution::factory()->create();
    $b = Solution::factory()->create();

    $integration = Integration::factory()->active()->create([
        'source_solution_id' => $a->id,
        'target_solution_id' => $b->id,
    ]);
    attachParticipants($integration, [
        [$a, 0],
        [$middle, 1],
        [$b, 2],
    ]);

    $graph = $service->globalMap();

    expect($graph['edges'])->toHaveCount(2);
    expect($graph['edges'][0]['source'])->toBe("sol-{$a->id}")
        ->and($graph['edges'][0]['target'])->toBe("sol-{$middle->id}")
        ->and($graph['edges'][1]['source'])->toBe("sol-{$middle->id}")
        ->and($graph['edges'][1]['target'])->toBe("sol-{$b->id}");
});

it('filters the global map by integration status', function () {
    $service = new IntegrationGraphService;

    $a = Solution::factory()->create();
    $b = Solution::factory()->create();
    $c = Solution::factory()->create();

    $active = Integration::factory()->active()->create(['source_solution_id' => $a->id, 'target_solution_id' => $b->id]);
    attachParticipants($active, [[$a, 0], [$b, 1]]);

    $planned = Integration::factory()->create(['source_solution_id' => $a->id, 'target_solution_id' => $c->id]); // planned
    attachParticipants($planned, [[$a, 0], [$c, 1]]);

    $activeOnly = $service->globalMap(['status' => 'active']);
    expect(collect($activeOnly['nodes'])->pluck('id'))
        ->toContain("sol-{$a->id}", "sol-{$b->id}")
        ->not->toContain("sol-{$c->id}");
    expect($activeOnly['edges'])->toHaveCount(1);

    $all = $service->globalMap(['status' => 'all']);
    expect(collect($all['nodes'])->pluck('id'))->toContain("sol-{$c->id}");
});

it('marks only the segment with a <-> arrow as bidirectional, not the whole chain (regression)', function () {
    $service = new IntegrationGraphService;

    $a = Solution::factory()->create();
    $b = Solution::factory()->create();
    $c = Solution::factory()->create();

    // A -> B -> C, only the B-C segment is bidirectional.
    $integration = Integration::factory()->active()->create([
        'source_solution_id' => $a->id,
        'target_solution_id' => $c->id,
        'direction'          => Direction::Bidirectional, // aggregated summary for the whole chain
        'chain'              => [
            'nodes' => [
                ['solution_id' => $a->id, 'label' => null],
                ['solution_id' => $b->id, 'label' => null],
                ['solution_id' => $c->id, 'label' => null],
            ],
            'edges' => [
                ['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null],
                ['from' => 1, 'to' => 2, 'arrow' => '<->', 'protocol' => null],
            ],
        ],
    ]);
    attachParticipants($integration, [[$a, 0], [$b, 1], [$c, 2]]);

    $graph = $service->globalMap();

    $edgeAB = collect($graph['edges'])->firstWhere('target', "sol-{$b->id}");
    $edgeBC = collect($graph['edges'])->first(fn ($e) => $e['source'] === "sol-{$b->id}");

    expect($edgeAB['direction'])->toBe('unidirectional')
        ->and($edgeBC['direction'])->toBe('bidirectional');
});

it('labels each edge with the per-segment protocol from the chain', function () {
    $service = new IntegrationGraphService;

    $a = Solution::factory()->create();
    $b = Solution::factory()->create();
    $c = Solution::factory()->create();

    // Per-step protocol: A->B is SOAP, B->C is SFTP.
    $integration = Integration::factory()->active()->create([
        'protocol' => null,
        'chain'    => [
            'nodes' => [
                ['solution_id' => $a->id, 'label' => null],
                ['solution_id' => $b->id, 'label' => null],
                ['solution_id' => $c->id, 'label' => null],
            ],
            'edges' => [
                ['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => 'soap'],
                ['from' => 1, 'to' => 2, 'arrow' => '->', 'protocol' => 'sftp'],
            ],
        ],
    ]);
    attachParticipants($integration, [[$a, 0], [$b, 1], [$c, 2]]);

    $graph = $service->globalMap();

    $edgeAB = collect($graph['edges'])->firstWhere('target', "sol-{$b->id}");
    $edgeBC = collect($graph['edges'])->first(fn ($e) => $e['source'] === "sol-{$b->id}");

    expect($edgeAB['label'])->toBe('SOAP')
        ->and($edgeBC['label'])->toBe('SFTP');
});

it('draws no edges for an integration without a chain, even though its participants still appear as nodes', function () {
    // Shouldn't happen in production — `SyncIntegrationFromChain` is the only
    // writer of `participants` and always derives it from a `chain` — but the
    // global map must not invent an edge from pivot position adjacency
    // when the chain (the single source of truth for topology) is absent.
    $service = new IntegrationGraphService;

    $a = Solution::factory()->create();
    $b = Solution::factory()->create();

    $integration = Integration::factory()->active()->create([
        'source_solution_id' => $a->id,
        'target_solution_id' => $b->id,
        'direction'          => Direction::Bidirectional,
        'chain'              => null,
    ]);
    $integration->participants()->attach($a->id, ['position' => 0]);
    $integration->participants()->attach($b->id, ['position' => 1]);

    $graph = $service->globalMap();

    expect(collect($graph['nodes'])->pluck('id'))->toContain("sol-{$a->id}", "sol-{$b->id}");
    expect($graph['edges'])->toBeEmpty();
});

it('draws no edge through a decision/actor node, even one carrying a stale solution_id', function () {
    // Only a `system` node may reference a Solution (`ChainNodeKind`), so a
    // decision/actor node is never a solution endpoint — not even when a
    // hand-written chain (or a block converted before that rule existed) left a
    // `solution_id` behind on it. Otherwise the map would draw a phantom A<->B
    // edge for a flow that really goes A -> "Pedido aprovado?" -> B.
    $service = new IntegrationGraphService;

    $a = Solution::factory()->create();
    $b = Solution::factory()->create();
    $ghost = Solution::factory()->create();

    $integration = Integration::factory()->active()->create([
        'chain' => [
            'nodes' => [
                ['solution_id' => $a->id, 'label' => null, 'kind' => 'system'],
                ['solution_id' => $ghost->id, 'label' => 'Pedido aprovado?', 'kind' => 'decision'],
                ['solution_id' => $b->id, 'label' => null, 'kind' => 'system'],
            ],
            'edges' => [
                ['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null],
                ['from' => 1, 'to' => 2, 'arrow' => '->', 'protocol' => null],
            ],
        ],
    ]);
    attachParticipants($integration, [[$a, 0], [$b, 2]]);

    $graph = $service->globalMap();

    expect($graph['edges'])->toBeEmpty()
        ->and(collect($graph['nodes'])->pluck('id'))->not->toContain("sol-{$ghost->id}");
});

it('draws edges exactly as defined in chain.edges, not by pivot-position adjacency (regression)', function () {
    // Pivot order is A, B, C — but the real topology only connects A->C and B->C.
    // The fixed bug used to draw A->B and B->C (position adjacency) instead
    // of the real edges.
    $service = new IntegrationGraphService;

    $a = Solution::factory()->create();
    $b = Solution::factory()->create();
    $c = Solution::factory()->create();

    $integration = Integration::factory()->active()->create([
        'chain' => [
            'nodes' => [
                ['solution_id' => $a->id, 'label' => null],
                ['solution_id' => $b->id, 'label' => null],
                ['solution_id' => $c->id, 'label' => null],
            ],
            'edges' => [
                ['from' => 0, 'to' => 2, 'arrow' => '->', 'protocol' => null],
                ['from' => 1, 'to' => 2, 'arrow' => '->', 'protocol' => null],
            ],
        ],
    ]);
    attachParticipants($integration, [[$a, 0], [$b, 1], [$c, 2]]);

    $graph = $service->globalMap();

    $hasEdge = fn ($source, $target) => collect($graph['edges'])
        ->contains(fn ($e) => $e['source'] === $source && $e['target'] === $target);

    expect($graph['edges'])->toHaveCount(2)
        ->and($hasEdge("sol-{$a->id}", "sol-{$b->id}"))->toBeFalse()
        ->and($hasEdge("sol-{$a->id}", "sol-{$c->id}"))->toBeTrue()
        ->and($hasEdge("sol-{$b->id}", "sol-{$c->id}"))->toBeTrue();
});

it('dedupes parallel edges between the same pair into one', function () {
    $service = new IntegrationGraphService;

    $svl = Solution::factory()->create();
    $sap = Solution::factory()->create();

    $first = Integration::factory()->active()->create(['name' => 'SVL -> SAP (pedidos)', 'source_solution_id' => $svl->id, 'target_solution_id' => $sap->id]);
    attachParticipants($first, [[$svl, 0], [$sap, 1]]);

    $second = Integration::factory()->active()->create(['name' => 'SVL -> SAP (estoque)', 'source_solution_id' => $svl->id, 'target_solution_id' => $sap->id]);
    attachParticipants($second, [[$svl, 0], [$sap, 1]]);

    $graph = $service->globalMap();

    expect($graph['edges'])->toHaveCount(1);
    $edge = $graph['edges'][0];
    expect($edge['source'])->toBe("sol-{$svl->id}")
        ->and($edge['target'])->toBe("sol-{$sap->id}")
        ->and($edge['integrations'])->toHaveCount(2)
        ->and(collect($edge['integrations'])->pluck('name')->all())->toBe(['SVL -> SAP (pedidos)', 'SVL -> SAP (estoque)']);
});

it('marks a deduped pair as bidirectional when flows exist in both directions across integrations', function () {
    $service = new IntegrationGraphService;

    $a = Solution::factory()->create();
    $b = Solution::factory()->create();

    $outbound = Integration::factory()->active()->create(['source_solution_id' => $a->id, 'target_solution_id' => $b->id]);
    attachParticipants($outbound, [[$a, 0], [$b, 1]]);

    $inbound = Integration::factory()->active()->create(['source_solution_id' => $b->id, 'target_solution_id' => $a->id]);
    attachParticipants($inbound, [[$b, 0], [$a, 1]]);

    $graph = $service->globalMap();

    expect($graph['edges'])->toHaveCount(1)
        ->and($graph['edges'][0]['direction'])->toBe('bidirectional');
});

it('picks the healthiest status when a deduped pair mixes active and non-active integrations', function () {
    $service = new IntegrationGraphService;

    $a = Solution::factory()->create();
    $b = Solution::factory()->create();

    $planned = Integration::factory()->create(['source_solution_id' => $a->id, 'target_solution_id' => $b->id]); // planned
    attachParticipants($planned, [[$a, 0], [$b, 1]]);

    $active = Integration::factory()->active()->create(['source_solution_id' => $a->id, 'target_solution_id' => $b->id]);
    attachParticipants($active, [[$a, 0], [$b, 1]]);

    $graph = $service->globalMap(['status' => 'all']);

    expect($graph['edges'])->toHaveCount(1)
        ->and($graph['edges'][0]['status'])->toBe('active');
});

it('joins distinct protocol labels for a deduped pair', function () {
    $service = new IntegrationGraphService;

    $a = Solution::factory()->create();
    $b = Solution::factory()->create();

    $chainWith = fn (string $protocol) => [
        'nodes' => [['solution_id' => $a->id, 'label' => null], ['solution_id' => $b->id, 'label' => null]],
        'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => $protocol]],
    ];

    $rest = Integration::factory()->active()->create(['source_solution_id' => $a->id, 'target_solution_id' => $b->id, 'chain' => $chainWith('rest')]);
    attachParticipants($rest, [[$a, 0], [$b, 1]]);

    $sftp = Integration::factory()->active()->create(['source_solution_id' => $a->id, 'target_solution_id' => $b->id, 'chain' => $chainWith('sftp')]);
    attachParticipants($sftp, [[$a, 0], [$b, 1]]);

    $graph = $service->globalMap();

    expect($graph['edges'])->toHaveCount(1)
        ->and($graph['edges'][0]['label'])->toBe('REST · SFTP');
});

it('filters the global map by category', function () {
    $service = new IntegrationGraphService;

    $erp = Solution::factory()->create(['category' => 'erp']);
    $crm = Solution::factory()->create(['category' => 'crm']);
    $mkt = Solution::factory()->create(['category' => 'marketing']);
    $tms = Solution::factory()->create(['category' => 'tms']);

    $withErp = Integration::factory()->active()->create(['source_solution_id' => $erp->id, 'target_solution_id' => $crm->id]);
    attachParticipants($withErp, [[$erp, 0], [$crm, 1]]);

    $withoutErp = Integration::factory()->active()->create(['source_solution_id' => $mkt->id, 'target_solution_id' => $tms->id]);
    attachParticipants($withoutErp, [[$mkt, 0], [$tms, 1]]);

    $graph = $service->globalMap(['category' => 'erp']);

    expect(collect($graph['nodes'])->pluck('id'))
        ->toContain("sol-{$erp->id}", "sol-{$crm->id}")
        ->not->toContain("sol-{$mkt->id}", "sol-{$tms->id}");
    expect($graph['edges'])->toHaveCount(1);
});
