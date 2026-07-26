<?php

use App\Models\AttributeOption;
use App\Models\Integration;
use App\Models\Solution;
use App\Support\Heroicons;
use App\View\Components\Solutions\IntegrationsMap;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('embeds a resolved graph (labels, per-step protocol, arrow direction) on each list row', function () {
    $a = Solution::factory()->create(['name' => 'Alpha ERP']);
    $b = Solution::factory()->create(['name' => 'Bravo iPaaS']);

    $integration = Integration::factory()->create([
        'name'  => 'Alpha <-> Bravo',
        'chain' => [
            'nodes' => [
                ['solution_id' => $a->id, 'label' => null],
                ['solution_id' => $b->id, 'label' => null],
                ['solution_id' => null, 'label' => 'Sistema externo'],
            ],
            'edges' => [
                ['from' => 0, 'to' => 1, 'arrow' => '<->', 'protocol' => 'rest'],
                ['from' => 1, 'to' => 2, 'arrow' => '<-', 'protocol' => null],
            ],
        ],
    ]);
    $integration->participants()->attach($a->id, ['position' => 0]);
    $integration->participants()->attach($b->id, ['position' => 1]);

    $html = (new IntegrationsMap($a))->render()->render();

    // Extracts the JSON from data-integration-graph and validates the shape consumed by JS.
    expect($html)->toContain('data-integration-graph=');
    preg_match('/data-integration-graph="([^"]*)"/', $html, $m);
    $graph = json_decode(html_entity_decode($m[1]), true);

    // `kind` defaults to system on nodes stored before kinds existed (no
    // `kind` key at all, as written above), and only a non-system kind has an icon.
    expect($graph['nodes'])->toBe([
        ['label' => 'Alpha ERP', 'kind' => 'system', 'icon' => null, 'solution' => true, 'solutionId' => $a->id, 'url' => route('solutions.show', $a), 'comment' => null, 'logo' => null, 'environment' => null, 'cloud' => null],
        ['label' => 'Bravo iPaaS', 'kind' => 'system', 'icon' => null, 'solution' => true, 'solutionId' => $b->id, 'url' => route('solutions.show', $b), 'comment' => null, 'logo' => null, 'environment' => null, 'cloud' => null],
        ['label' => 'Sistema externo', 'kind' => 'system', 'icon' => null, 'solution' => false, 'solutionId' => null, 'url' => null, 'comment' => null, 'logo' => null, 'environment' => null, 'cloud' => null], // free-text node
    ]);
    expect($graph['edges'])->toBe([
        ['from' => 0, 'to' => 1, 'arrow' => '<->', 'protocol' => ['value' => 'rest', 'label' => 'REST']],
        ['from' => 1, 'to' => 2, 'arrow' => '<-', 'protocol' => null], // raw value + human label, null preserved
    ]);
});

it('carries logo and hosting/cloud badges (label + icon) for nodes with a solution', function () {
    AttributeOption::create(['group' => 'environment', 'value' => 'saas', 'label' => 'SaaS', 'icon' => 'cloud']);
    AttributeOption::create(['group' => 'cloud', 'value' => 'azure', 'label' => 'Azure', 'icon' => null]);

    $a = Solution::factory()->create(['environment' => 'saas', 'cloud' => 'azure', 'logo_path' => 'logos/alpha.png']);
    $b = Solution::factory()->create(['environment' => null, 'cloud' => null, 'logo_path' => null]);

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $a->id], ['solution_id' => $b->id]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    $integration->participants()->attach($a->id, ['position' => 0]);
    $integration->participants()->attach($b->id, ['position' => 1]);

    $html = (new IntegrationsMap($a))->render()->render();
    preg_match('/data-integration-graph="([^"]*)"/', $html, $m);
    $graph = json_decode(html_entity_decode($m[1]), true);

    expect($graph['nodes'][0]['logo'])->toContain('logos/alpha.png');
    expect($graph['nodes'][0]['environment'])->toBe(['label' => 'SaaS', 'icon' => Heroicons::outlineSvg('cloud')]);
    expect($graph['nodes'][0]['cloud'])->toBe(['label' => 'Azure', 'icon' => null]); // icon optional: label appears even without an icon

    expect($graph['nodes'][1]['logo'])->toBeNull();
    expect($graph['nodes'][1]['environment'])->toBeNull();
    expect($graph['nodes'][1]['cloud'])->toBeNull();
});

it('resolves decision and actor blocks with their kind icon and no solution data', function () {
    $a = Solution::factory()->create(['name' => 'Alpha ERP', 'logo_path' => 'logos/alpha.png']);

    $integration = Integration::factory()->create([
        'chain' => [
            'nodes' => [
                ['solution_id' => $a->id, 'label' => null, 'kind' => 'system'],
                ['solution_id' => null, 'label' => 'Pedido aprovado?', 'kind' => 'decision'],
                // A stale solution_id on a non-system kind is ignored on read too,
                // not just blocked on write (`ValidatesChainNode`).
                ['solution_id' => $a->id, 'label' => 'Vendedor', 'kind' => 'actor'],
            ],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    $integration->participants()->attach($a->id, ['position' => 0]);

    $html = (new IntegrationsMap($a))->render()->render();
    preg_match('/data-integration-graph="([^"]*)"/', $html, $m);
    $graph = json_decode(html_entity_decode($m[1]), true);

    expect($graph['nodes'][1]['kind'])->toBe('decision')
        ->and($graph['nodes'][1]['icon'])->toBe(Heroicons::outlineSvg('question-mark-circle'))
        ->and($graph['nodes'][1]['solution'])->toBeFalse()
        ->and($graph['nodes'][2]['kind'])->toBe('actor')
        ->and($graph['nodes'][2]['icon'])->toBe(Heroicons::outlineSvg('user'))
        ->and($graph['nodes'][2]['label'])->toBe('Vendedor')
        ->and($graph['nodes'][2]['solution'])->toBeFalse()
        ->and($graph['nodes'][2]['solutionId'])->toBeNull()
        ->and($graph['nodes'][2]['logo'])->toBeNull()
        ->and($graph['nodes'][2]['url'])->toBeNull();

    // The kinds list feeding both block panels ships with the row list.
    expect($html)->toContain('data-ak-node-kinds=');
});
