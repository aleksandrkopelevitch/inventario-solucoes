<?php

use App\Models\AttributeOption;
use App\Models\Diagram;
use App\Models\Solution;
use App\Support\Heroicons;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Blade;

uses(LazilyRefreshDatabase::class);

it('embeds a resolved graph (labels, per-step protocol, arrow direction) on the workspace row', function () {
    $a = Solution::factory()->create(['name' => 'Alpha ERP']);
    $b = Solution::factory()->create(['name' => 'Bravo iPaaS']);

    $diagram = Diagram::factory()->create([
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
    $diagram->participants()->attach($a->id, ['position' => 0]);
    $diagram->participants()->attach($b->id, ['position' => 1]);

    $html = Blade::render(
        '<x-diagrams.workspace :solution="$a" :diagram="$diagram" />',
        ['a' => $a, 'diagram' => $diagram],
    );

    // Extracts the JSON from data-ak-chain-graph and validates the shape consumed by JS.
    expect($html)->toContain('data-ak-chain-graph=');
    preg_match('/data-ak-chain-graph="([^"]*)"/', $html, $m);
    $graph = json_decode(html_entity_decode($m[1]), true);

    // `kind` defaults to system on nodes stored before kinds existed (no
    // `kind` key at all, as written above), and only a non-system kind has an icon.
    expect($graph['nodes'])->toBe([
        ['label' => 'Alpha ERP', 'kind' => 'system', 'icon' => null, 'solution' => true, 'solutionId' => $a->id, 'url' => route('solutions.show', $a), 'comment' => null, 'logo' => null, 'environment' => null, 'cloud' => null, 'mediaUrl' => null],
        ['label' => 'Bravo iPaaS', 'kind' => 'system', 'icon' => null, 'solution' => true, 'solutionId' => $b->id, 'url' => route('solutions.show', $b), 'comment' => null, 'logo' => null, 'environment' => null, 'cloud' => null, 'mediaUrl' => null],
        ['label' => 'Sistema externo', 'kind' => 'system', 'icon' => null, 'solution' => false, 'solutionId' => null, 'url' => null, 'comment' => null, 'logo' => null, 'environment' => null, 'cloud' => null, 'mediaUrl' => null], // free-text node
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

    $diagram = Diagram::factory()->create([
        'chain' => [
            'nodes' => [['solution_id' => $a->id], ['solution_id' => $b->id]],
            'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]],
        ],
    ]);
    $diagram->participants()->attach($a->id, ['position' => 0]);
    $diagram->participants()->attach($b->id, ['position' => 1]);

    $html = Blade::render(
        '<x-diagrams.workspace :solution="$a" :diagram="$diagram" />',
        ['a' => $a, 'diagram' => $diagram],
    );
    preg_match('/data-ak-chain-graph="([^"]*)"/', $html, $m);
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

    $diagram = Diagram::factory()->create([
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
    $diagram->participants()->attach($a->id, ['position' => 0]);

    $html = Blade::render(
        '<x-diagrams.workspace :solution="$a" :diagram="$diagram" />',
        ['a' => $a, 'diagram' => $diagram],
    );
    preg_match('/data-ak-chain-graph="([^"]*)"/', $html, $m);
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

    // The kinds list feeding both block panels ships with the workspace.
    expect($html)->toContain('data-ak-node-kinds=');
});

it('gives the F3 canvas touch-reachable affordances for gestures that were mouse-only', function () {
    // Regression test for the touch-support pass. Three things a tablet user
    // had no path to before:
    //  1. renaming a block (only `dblclick` on the shape, which touch lacks);
    //  2. hitting a 11px port / 9px arrow-tip handle with a finger;
    //  3. help text that only described mouse gestures ("Roda do mouse").
    $solution = Solution::factory()->create();
    $diagram = Diagram::factory()->create([
        'chain' => ['nodes' => [['solution_id' => $solution->id, 'label' => null]], 'edges' => []],
    ]);
    $diagram->participants()->attach($solution->id, ['position' => 0]);

    $html = Blade::render(
        '<x-diagrams.workspace :solution="$s" :diagram="$i" />',
        ['s' => $solution, 'i' => $diagram],
    );

    expect($html)
        // Panel path to the inline label editor, alongside the dblclick one.
        ->toContain('data-viz-toolbar-rename')
        // Coarse-pointer hit targets and a coarse-pointer help list.
        ->toContain('@media (pointer: coarse)')
        ->toContain('[@media(pointer:coarse)]:flex')
        ->toContain('Toque o bloco')
        // The fine-pointer list keeps the mouse wording, hidden on coarse.
        ->toContain('Roda do mouse');
});
