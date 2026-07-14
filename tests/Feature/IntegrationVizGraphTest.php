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

    // Extrai o JSON do data-integration-graph e valida a forma consumida pelo JS.
    expect($html)->toContain('data-integration-graph=');
    preg_match('/data-integration-graph="([^"]*)"/', $html, $m);
    $graph = json_decode(html_entity_decode($m[1]), true);

    expect($graph['nodes'])->toBe([
        ['label' => 'Alpha ERP', 'solution' => true, 'solutionId' => $a->id, 'url' => route('solutions.show', $a), 'comment' => null, 'logo' => null, 'environment' => null, 'cloud' => null],
        ['label' => 'Bravo iPaaS', 'solution' => true, 'solutionId' => $b->id, 'url' => route('solutions.show', $b), 'comment' => null, 'logo' => null, 'environment' => null, 'cloud' => null],
        ['label' => 'Sistema externo', 'solution' => false, 'solutionId' => null, 'url' => null, 'comment' => null, 'logo' => null, 'environment' => null, 'cloud' => null], // nó de texto livre
    ]);
    expect($graph['edges'])->toBe([
        ['from' => 0, 'to' => 1, 'arrow' => '<->', 'protocol' => ['value' => 'rest', 'label' => 'REST']],
        ['from' => 1, 'to' => 2, 'arrow' => '<-', 'protocol' => null], // valor bruto + rótulo humano, null preservado
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
    expect($graph['nodes'][0]['cloud'])->toBe(['label' => 'Azure', 'icon' => null]); // ícone opcional: rótulo aparece mesmo sem ícone

    expect($graph['nodes'][1]['logo'])->toBeNull();
    expect($graph['nodes'][1]['environment'])->toBeNull();
    expect($graph['nodes'][1]['cloud'])->toBeNull();
});
