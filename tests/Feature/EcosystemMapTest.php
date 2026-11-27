<?php

use App\Models\AttributeOption;
use App\Models\DocumentationPage;
use App\Models\Solution;
use App\Models\User;
use App\Services\EcosystemMapService;
use Database\Seeders\AttributeOptionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

/*
 * The ecosystem map's payload. Its arrows come from the documentation's UML
 * diagrams since 2026-11-26 (direction, via and evidence are covered in
 * SolutionRelationshipsTest); this file keeps what the map adds on top: the
 * nodes and the "Por hospedagem" view. Replaced DiagramGraphServiceTest, whose
 * edges came from the topology drawings.
 */

/** One documentation page stating that `$from` calls `$to`. */
function mapCall(Solution $from, Solution $to): void
{
    DocumentationPage::factory()->create(['documentation' => "{% uml type=\"sequence\" %}\n" . json_encode([
        'participants' => [['id' => 'a', 'solution' => $from->slug], ['id' => 'b', 'solution' => $to->slug]],
        'messages'     => [['from' => 'a', 'to' => 'b', 'label' => 'REST']],
    ]) . "\n{% enduml %}"]);
}

it('draws a node per related solution and an arrow per documented pair', function () {
    $root = Solution::factory()->create(['category' => 'erp']);
    $target = Solution::factory()->create(['category' => 'crm']);
    Solution::factory()->create(['category' => 'crm']); // unrelated: not on the links view
    mapCall($root, $target);

    $graph = app(EcosystemMapService::class)->globalMap();

    expect(collect($graph['nodes'])->pluck('id')->sort()->values()->all())->toBe(collect(["sol-{$root->id}", "sol-{$target->id}"])->sort()->values()->all())
        ->and($graph['edges'])->toHaveCount(1)
        ->and($graph['edges'][0])->toMatchArray([
            'source'    => "sol-{$root->id}",
            'target'    => "sol-{$target->id}",
            'direction' => 'unidirectional',
            'label'     => 'REST',
        ])
        ->and($graph)->not->toHaveKey('diagrams');
});

it('includes the solution attribute labels, show url and colour family on each node, for the map popover', function () {
    $this->seed(AttributeOptionSeeder::class);

    $root = Solution::factory()->create(['category' => 'erp', 'status' => 'active']);
    mapCall($root, Solution::factory()->create(['category' => 'crm']));

    $rootNode = collect(app(EcosystemMapService::class)->globalMap()['nodes'])->firstWhere('id', "sol-{$root->id}");

    expect($rootNode['url'])->toBe(route('solutions.show', $root))
        ->and($rootNode['categoryLabel'])->toBe('ERP')
        ->and($rootNode['statusLabel'])->toBe('Ativo')
        ->and($rootNode['categoryFamily'])->toBe('indigo')
        ->and($rootNode['directorate'])->toBe($root->directorate)
        ->and($rootNode)->toHaveKeys(['criticalityLabel', 'environmentLabel', 'cloudLabel', 'contractLabel', 'supportLabel'])
        ->and($rootNode)->not->toHaveKey('mapPosition');
});

/*
|--------------------------------------------------------------------------
| The "Por hospedagem" view
|--------------------------------------------------------------------------
*/

it('places a system in its cloud first, then its hosting model, then "Não informado"', function () {
    $this->seed(AttributeOptionSeeder::class);
    $service = app(EcosystemMapService::class);

    $onAws = Solution::factory()->create(['environment' => 'saas', 'cloud' => 'aws']);
    $saas = Solution::factory()->create(['environment' => 'saas', 'cloud' => null]);
    $nowhere = Solution::factory()->create(['environment' => null, 'cloud' => null]);

    expect($service->hostingOf($onAws))->toBe('cloud:aws')
        ->and($service->hostingOf($saas))->toBe('environment:saas')
        ->and($service->hostingOf($nowhere))->toBe(EcosystemMapService::UNKNOWN_HOSTING);
});

it('adds every solution and the containers they fall into for the hosting view', function () {
    $this->seed(AttributeOptionSeeder::class);
    AttributeOption::where('group', 'cloud')->where('value', 'aws')
        ->update(['color' => '#ff9900', 'image_path' => 'hosting-images/aws.png']);
    AttributeOption::forgetCache();
    $service = app(EcosystemMapService::class);

    $connectedA = Solution::factory()->create(['environment' => 'saas', 'cloud' => 'aws', 'category' => 'erp']);
    $connectedB = Solution::factory()->create(['environment' => 'on_premise', 'cloud' => null, 'category' => 'erp']);
    $alone = Solution::factory()->create(['environment' => null, 'cloud' => null]);
    mapCall($connectedA, $connectedB);

    $links = $service->globalMap();
    $hosting = $service->globalMap(wholeCatalog: true);

    expect(collect($links['nodes'])->pluck('id'))->not->toContain("sol-{$alone->id}")
        ->and(collect($hosting['nodes'])->pluck('id'))->toContain("sol-{$alone->id}", "sol-{$connectedA->id}")
        ->and(collect($hosting['nodes'])->firstWhere('id', "sol-{$connectedA->id}")['hosting'])->toBe('cloud:aws')
        // Clouds first, hosting models next, "Não informado" last.
        ->and(collect($hosting['hostings'])->pluck('id')->first())->toBe('cloud:aws')
        ->and(collect($hosting['hostings'])->pluck('id')->last())->toBe('unknown')
        ->and(collect($hosting['hostings'])->pluck('id'))->toContain('environment:on_premise')
        ->and($hosting['hostings'][0])->toMatchArray([
            'label' => 'AWS',
            'color' => '#ff9900',
            'image' => Storage::disk('public')->url('hosting-images/aws.png'),
        ])
        // A hosting nobody coloured falls back to the neutral grey.
        ->and(collect($hosting['hostings'])->firstWhere('id', 'environment:on_premise')['color'])->toBe(AttributeOption::DEFAULT_COLOR);
});

it('serves the hosting view from the map endpoint', function () {
    $this->seed(AttributeOptionSeeder::class);
    Solution::factory()->create(['environment' => 'saas', 'cloud' => null]);

    $this->actingAs(User::factory()->create())
        ->getJson(route('solutions.map.data', ['view' => 'hosting']))
        ->assertOk()
        ->assertJsonPath('hostings.0.id', 'environment:saas');

    // An array where a string belongs reads as the default view, not a 500.
    $this->actingAs(User::factory()->create())
        ->getJson(route('solutions.map.data') . '?view[]=hosting')
        ->assertOk()
        ->assertJsonMissingPath('hostings.0');
});

it('renders the map page container', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('solutions.map'))
        ->assertOk()
        ->assertSee('Mapa de integrações')
        ->assertSee('data-ak-map-view', false);
});
