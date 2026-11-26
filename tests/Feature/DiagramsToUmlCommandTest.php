<?php

use App\Enums\RelationshipDirection;
use App\Models\Diagram;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\Solution;
use App\Models\UmlSolutionLink;
use App\Services\SolutionRelationshipService;
use App\Support\Diagrams\DiagramToUml;
use App\Support\Documentation\UmlDiagram;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/*
 * Phase 3 of docs-and-specs/solution-relationships-from-uml.md: the eleven
 * drawings become {% uml %} blocks in their documentation. Deleted in phase 4
 * with the command. The shapes below are the production ones (read through
 * get_diagram on 2026-11-26).
 */

function convSolution(string $slug, string $name, string $category = 'erp'): Solution
{
    return Solution::factory()->create(['slug' => $slug, 'name' => $name, 'category' => $category]);
}

/** @param  list<array<string, mixed>>  $nodes  @param  list<array<string, mixed>>  $edges */
function convDiagram(string $name, array $nodes, array $edges, ?Notebook $notebook = null, array $layout = []): Diagram
{
    return Diagram::factory()->forNotebook($notebook ?? Notebook::factory()->create())->create([
        'name'       => $name,
        'chain'      => ['nodes' => $nodes, 'edges' => $edges],
        'viz_layout' => $layout === [] ? null : ['nodes' => $layout],
    ]);
}

function convSystem(Solution $solution, ?string $label = null): array
{
    return ['kind' => 'system', 'solution_id' => $solution->id, 'label' => $label ?? $solution->name];
}

function convParse(array $uml): array
{
    return UmlDiagram::parse($uml['type'], $uml['title'], $uml['body']);
}

it('converts systems talking in rounds into a sequence, bound and in order', function () {
    // "Visão Geral E-Commerce": nine links among four systems, three rounds.
    $sap = convSolution('sap-s-4hana', 'SAP S/4HANA');
    $cpi = convSolution('sap-cpi-integracao', 'SAP CPI', 'ipaas');
    $dgb = convSolution('digibee-ipaas', 'Digibee (iPaaS)', 'ipaas');
    $vtex = convSolution('vtex', 'VTEX', 'ecommerce');

    $link = fn (int $f, int $t, string $p) => ['from' => $f, 'to' => $t, 'arrow' => '->', 'protocol' => $p];
    $uml = app(DiagramToUml::class)->convert(convDiagram('Visão Geral E-Commerce — Fluxo de dados', [convSystem($sap), convSystem($cpi), convSystem($dgb), convSystem($vtex)], [
        $link(0, 1, 'Catálogo'), $link(1, 2, 'Dados do ERP'), $link(2, 3, 'Catalog APIs'),
        $link(3, 2, 'Webhooks'), $link(2, 1, 'Pedidos'), $link(1, 0, 'Persistência'),
    ]));

    $parsed = convParse($uml);

    expect($uml['type'])->toBe('sequence')
        ->and($uml['bindings'])->toBe(4)
        ->and($parsed['problems'])->toBe([])
        ->and(array_column($parsed['diagram']['participants'], 'solution'))->toBe(['sap-s-4hana', 'sap-cpi-integracao', 'digibee-ipaas', 'vtex'])
        ->and(array_column($parsed['diagram']['messages'], 'label'))->toBe(['Catálogo', 'Dados do ERP', 'Catalog APIs', 'Webhooks', 'Pedidos', 'Persistência']);

    // Written into a page, it relates SAP and VTEX through both platforms.
    DocumentationPage::factory()->create(['documentation' => "{% uml type=\"sequence\" %}\n{$uml['body']}\n{% enduml %}"]);
    $vtexRow = app(SolutionRelationshipService::class)->forSolution($sap)->first(fn ($r) => $r->other->is($vtex));

    expect($vtexRow->direction)->toBe(RelationshipDirection::Both)
        ->and(array_map(fn ($p) => $p->slug, $vtexRow->via))->toBe(['sap-cpi-integracao', 'digibee-ipaas']);
});

it('keeps lifelines a sequence, self calls included, and reads every arrow way', function () {
    $sap = convSolution('sap', 'SAP');
    $onfly = convSolution('onfly', 'Onfly');

    $uml = app(DiagramToUml::class)->convert(convDiagram('SAP x Onfly — Sequência', [
        ['kind' => 'lifeline', 'solution_id' => $sap->id, 'label' => 'SAP'],
        ['kind' => 'lifeline', 'solution_id' => $onfly->id, 'label' => 'Onfly'],
    ], [
        ['from' => 0, 'to' => 1, 'arrow' => '->', 'protocol' => 'GET /rdv'],
        ['from' => 0, 'to' => 0, 'arrow' => '->', 'protocol' => 'F110'],
        ['from' => 0, 'to' => 1, 'arrow' => '<-', 'protocol' => 'volta'],
        ['from' => 0, 'to' => 1, 'arrow' => '<->', 'protocol' => 'mútuo'],
    ]));

    $messages = array_map(fn (array $m) => "{$m['from']}>{$m['to']}", convParse($uml)['diagram']['messages']);

    expect($uml['type'])->toBe('sequence')
        ->and($messages)->toBe(['n0>n1', 'n0>n0', 'n1>n0', 'n0>n1', 'n1>n0']);
});

it('turns a process with no system into an activity diagram, and a mixed drawing into components on distinct cells', function () {
    $activity = app(DiagramToUml::class)->convert(convDiagram('Fluxo Login Cliente — Processo', [
        ['kind' => 'start'], ['kind' => 'step', 'label' => 'Informa CPF'], ['kind' => 'decision', 'label' => 'Existe na VTEX?'],
        ['kind'                      => 'step', 'label' => 'Autenticação padrão'], ['kind' => 'step', 'label' => 'Consulta cliente'], ['kind' => 'end'],
    ], [
        ['from' => 0, 'to' => 1], ['from' => 1, 'to' => 2], ['from' => 2, 'to' => 3, 'protocol' => 'sim'],
        ['from'                           => 2, 'to' => 4, 'protocol' => 'não'], ['from' => 3, 'to' => 5], ['from' => 4, 'to' => 5],
    ]));

    $parsed = convParse($activity);
    expect($activity['type'])->toBe('activity')
        ->and($activity['bindings'])->toBe(0)
        ->and($parsed['problems'])->toBe([])
        ->and(array_column($parsed['diagram']['nodes'], 'kind'))->toBe(['initial', 'action', 'decision', 'action', 'action', 'final'])
        ->and(array_unique(array_column($parsed['diagram']['edges'], 'kind')))->toBe(['flow']);

    // "Amigo Leo": systems, free steps and two parallel links CPI → Devpartner.
    $sap = convSolution('sap', 'SAP');
    $cpi = convSolution('cpi', 'SAP CPI', 'ipaas');
    $dev = convSolution('devpartner', 'Amigo Leo - Devpartner', 'ecommerce');
    $component = app(DiagramToUml::class)->convert(convDiagram('Amigo Leo — Fluxo de dados', [
        ['kind' => 'step', 'label' => 'Marketing'], convSystem($sap), ['kind' => 'step', 'label' => 'ZCSCRMR0011'], convSystem($dev), convSystem($cpi),
    ], [
        ['from' => 0, 'to' => 1, 'protocol' => 'categorias'], ['from' => 4, 'to' => 2], ['from' => 2, 'to' => 3],
        ['from'                                                       => 4, 'to' => 3, 'protocol' => 'REPROC'], ['from' => 4, 'to' => 3, 'protocol' => 'ZCSCRMR0012'], ['from' => 1, 'to' => 4],
    ], layout: [
        ['x' => 0, 'y' => 0], ['x' => 300, 'y' => 0], ['x' => 600, 'y' => 0], ['x' => 900, 'y' => 0], ['x' => 300, 'y' => 20],
    ]));

    $parsed = convParse($component);
    $cells = array_map(fn (array $n) => implode(',', $n['at']), $parsed['diagram']['nodes']);

    expect($component['type'])->toBe('component')
        ->and($component['bindings'])->toBe(3)
        ->and($parsed['problems'])->toBe([])
        ->and($cells)->toBe(array_unique($cells))
        // x 300 and y 0/20 are one cell on the canvas: the second moves right.
        ->and($parsed['diagram']['nodes'][4]['at'])->not->toBe($parsed['diagram']['nodes'][1]['at']);
});

it('replaces a citation in place, else lands on the page it was drawn from, else on the first page', function () {
    $a = convSolution('alfa', 'Alfa');
    $b = convSolution('beta', 'Beta');
    $nodes = [convSystem($a), convSystem($b)];
    $edges = [['from' => 0, 'to' => 1, 'protocol' => 'REST']];

    $notebook = Notebook::factory()->create();
    $first = DocumentationPage::factory()->for($notebook)->create(['title' => 'Visão geral', 'position' => 0, 'documentation' => 'Introdução.']);
    $source = DocumentationPage::factory()->for($notebook)->create(['title' => 'Integração Clientes', 'position' => 1, 'documentation' => 'Texto da integração.']);

    $cited = convDiagram('Citado', $nodes, $edges, $notebook);
    $citing = DocumentationPage::factory()->for($notebook)->create(['position' => 2, 'documentation' => "Antes.\n\n{% diagram slug=\"{$cited->slug}\" %}\n\nDepois."]);
    $drawn = convDiagram('Integração clientes — Fluxo de dados', $nodes, $edges, $notebook);
    $orphan = convDiagram('Sem página — Processo', $nodes, $edges, $notebook);

    $this->artisan('diagrams:to-uml')->assertSuccessful();

    expect($citing->fresh()->documentation)->toStartWith("Antes.\n\n{% uml type=\"component\" title=\"Citado\" %}")
        ->toEndWith("{% enduml %}\n\nDepois.")
        ->not->toContain('{% diagram')
        ->and($source->fresh()->documentation)->toContain("Texto da integração.\n\n## Integração clientes — Fluxo de dados\n\n{% uml type=\"component\"")
        ->and($first->fresh()->documentation)->toContain('## Sem página — Processo')
        ->and(UmlSolutionLink::query()->count())->toBe(3);
});

it('writes nothing on a dry run, and leaves the citation of a drawing the validator refuses', function () {
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create(['documentation' => 'Só texto.']);

    convDiagram('Válido', [['kind' => 'step', 'label' => 'A'], ['kind' => 'step', 'label' => 'B']], [['from' => 0, 'to' => 1]], $notebook);

    $this->artisan('diagrams:to-uml', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run — nothing written')
        ->assertSuccessful();
    expect($page->fresh()->documentation)->toBe('Só texto.');

    // Thirteen lifelines: a sequence holds twelve participants at most.
    $tooMany = convDiagram('Grande — Sequência', array_map(fn (int $i) => ['kind' => 'lifeline', 'label' => "P{$i}"], range(1, 13)), [['from' => 0, 'to' => 1]], $notebook);
    $citing = DocumentationPage::factory()->for($notebook)->create(['documentation' => "{% diagram slug=\"{$tooMany->slug}\" %}"]);

    $this->artisan('diagrams:to-uml', ['--diagram' => $tooMany->slug])
        ->expectsOutputToContain('Not written')
        ->assertSuccessful();

    expect($citing->fresh()->documentation)->toBe("{% diagram slug=\"{$tooMany->slug}\" %}");
});
