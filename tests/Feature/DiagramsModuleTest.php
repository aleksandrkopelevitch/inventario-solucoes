<?php

use App\Actions\SyncDiagramFromChain;
use App\Enums\UserRole;
use App\Models\Diagram;
use App\Models\Solution;
use App\Models\User;
use App\Services\DiagramCatalogService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function diagramsAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin->value]);
}

/** A diagram with more than its root block — i.e. one somebody actually drew. */
function drawnDiagram(array $attributes = []): Diagram
{
    return Diagram::factory()->create([
        'chain' => ['nodes' => [
            ['solution_id' => null, 'label' => 'A', 'kind' => 'system'],
            ['solution_id' => null, 'label' => 'B', 'kind' => 'system'],
        ], 'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]]],
        ...$attributes,
    ]);
}

/*
|--------------------------------------------------------------------------
| The index
|--------------------------------------------------------------------------
*/

it('renders the diagrams index for any authenticated user', function () {
    drawnDiagram(['name' => 'SAP -> AllStrategy']);

    $response = $this->actingAs(User::factory()->create()) // viewer
        ->get(route('diagrams.index'))
        ->assertOk()
        ->assertSee('Diagramas')
        ->assertSee('SAP -&gt; AllStrategy', false)
        // The filter/search hooks have to COMPILE — the
        // @json-in-a-component-attribute bug fails silently (see AGENTS.md).
        ->assertSee('data-ak-filters', false)
        ->assertSee('data-ak-search', false);

    expect($response->getContent())->not->toContain('@json(');
});

it('says so when a diagram has never been drawn on', function () {
    // One root block is what `store()` creates. It is an empty canvas, and
    // printing its single node name as a "summary" would read as content.
    Diagram::factory()->create([
        'name'  => 'Recém-criado',
        'chain' => ['nodes' => [['solution_id' => null, 'label' => 'Recém-criado', 'kind' => 'system']], 'edges' => []],
    ]);

    $this->actingAs(diagramsAdmin())
        ->get(route('diagrams.index'))
        ->assertOk()
        ->assertSee('Canvas ainda em branco');
});

it('counts drawn and placed separately, because they go missing separately', function () {
    // Drawn = more than a root block. Placed = names a catalog Solution among
    // its blocks, which is what lets the ecosystem map reach it. A drawing can
    // be one without the other: free text is a real drawing that the catalog
    // cannot place.
    drawnDiagram();
    Diagram::factory()->create(['chain' => ['nodes' => [['solution_id' => null, 'label' => 'só a raiz']], 'edges' => []]]);

    $counters = app(DiagramCatalogService::class)->counters();

    expect($counters['drawn'])->toBe(['value' => 1, 'total' => 2, 'percent' => 50.0])
        ->and($counters['placed']['total'])->toBe(2);
});

it('filters the index by name, status and whether it reaches the catalog', function () {
    // `drawnDiagram()` names no Solution, so it is drawn but NOT placed —
    // this one has to reference a real record to be the placed half.
    $solution = Solution::factory()->create();
    $placed = Diagram::factory()->create([
        'name'   => 'Alfa',
        'status' => 'active',
        'chain'  => ['nodes' => [
            ['solution_id' => $solution->id, 'label' => null, 'kind' => 'system'],
            ['solution_id' => null, 'label' => 'B', 'kind' => 'system'],
        ], 'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]]],
    ]);
    app(SyncDiagramFromChain::class)->handle($placed);
    $loose = Diagram::factory()->create([
        'name'   => 'Beta',
        'status' => 'deprecated',
        // Free text only: a real drawing the catalog cannot place.
        'chain' => ['nodes' => [
            ['solution_id' => null, 'label' => 'ERP externo'],
            ['solution_id' => null, 'label' => 'Parceiro'],
        ], 'edges' => [['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => null]]],
    ]);
    app(SyncDiagramFromChain::class)->handle($loose);

    $names = fn (array $filters) => app(DiagramCatalogService::class)->list($filters)->pluck('name')->all();

    expect($names(['search' => 'alf']))->toBe(['Alfa'])
        ->and($names(['status' => 'deprecated']))->toBe(['Beta'])
        ->and($names(['placed' => 'yes']))->toBe(['Alfa'])
        ->and($names(['placed' => 'no']))->toBe(['Beta']);
});

it('returns the index slot as JSON when filtering', function () {
    drawnDiagram(['name' => 'Filtrado']);

    $response = $this->actingAs(diagramsAdmin())
        ->getJson(route('diagrams.index', ['filter' => ['search' => 'filtrado']]))
        ->assertOk();

    expect($response->json('updatableSlots.0.id'))->toBe('diagrams-index-slot')
        ->and($response->json('updatableSlots.0.content'))->toContain('Filtrado');
});

/*
|--------------------------------------------------------------------------
| The canvas page
|--------------------------------------------------------------------------
*/

it('mounts the canvas on a diagram\'s own page, with its own endpoints in the payload', function () {
    $diagram = drawnDiagram(['name' => 'SAP -> AllStrategy']);

    $content = $this->actingAs(diagramsAdmin())->get(route('diagrams.show', $diagram))->assertOk()->getContent();

    // Every URL the canvas calls arrives inside the graph payload, which is
    // what lets one client edit either owner (§ ChainCanvas). json_encoded into
    // an attribute, so the slashes arrive escaped.
    $escaped = fn (string $url) => str_replace('/', '\\/', $url);

    expect($content)
        ->toContain('data-ak-chain-viz')
        ->toContain($escaped(route('diagrams.chain.node.add', $diagram)))
        ->toContain($escaped(route('diagrams.layout.save', $diagram)))
        ->toContain($escaped(route('diagrams.picture.store', $diagram)))
        // The name/status pair, edited in place right there.
        ->toContain('id="diagram-meta-slot"');
});

/*
|--------------------------------------------------------------------------
| Delete
|--------------------------------------------------------------------------
*/

it('refreshes the solution card it was deleted from, and navigates away when asked', function () {
    $solution = Solution::factory()->create();
    $diagram = Diagram::factory()->create([
        'name'  => 'A remover',
        'chain' => ['nodes' => [['solution_id' => $solution->id, 'label' => null, 'kind' => 'system']], 'edges' => []],
    ]);
    $diagram->afterChainMutation(); // derives the participant pivot

    // From a solution's detail card: its list has to lose the row.
    $response = $this->actingAs(diagramsAdmin())
        ->deleteJson(route('diagrams.destroy', ['diagram' => $diagram, 'solution' => $solution->slug]))
        ->assertOk();

    expect(collect($response->json('updatableSlots'))->pluck('id')->all())
        ->toBe(['diagrams-index-slot', 'solution-diagram-titles-slot'])
        ->and($response->json('redirect'))->toBeNull();

    $this->assertModelMissing($diagram);

    // From the diagram's own page: staying there would be a 404 on the next click.
    $second = Diagram::factory()->create();

    $this->actingAs(diagramsAdmin())
        ->deleteJson(route('diagrams.destroy', ['diagram' => $second, 'after' => 'index']))
        ->assertOk()
        ->assertJson(['redirect' => route('diagrams.index')]);
});

it('forbids a viewer from deleting a diagram', function () {
    $diagram = Diagram::factory()->create();

    $this->actingAs(User::factory()->create()) // viewer
        ->deleteJson(route('diagrams.destroy', $diagram))
        ->assertForbidden();

    $this->assertModelExists($diagram);
});

/*
|--------------------------------------------------------------------------
| The rail entry
|--------------------------------------------------------------------------
*/

it('gives the module its own sidebar entry, without lighting up Soluções too', function () {
    $content = $this->actingAs(diagramsAdmin())->get(route('diagrams.index'))->assertOk()->getContent();

    // A page with no rail entry is orphaned (§ Global layout). And the entry
    // has to be the ONLY active one: "Soluções" listing `diagrams.*` among its
    // patterns would light two items at once.
    expect($content)->toContain('href="' . route('diagrams.index') . '"');

    $activeMarkers = substr_count($content, 'shadow-[0_0_10px_1px_rgba(170,219,30,0.55)]');
    expect($activeMarkers)->toBe(2); // the desktop rail and the mobile nav, one each
});
