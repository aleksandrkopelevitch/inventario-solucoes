<?php

use App\Actions\Catalog\SaveSolution;
use App\Actions\Documentation\SyncPageSolutionLinks;
use App\Enums\AccessLevel;
use App\Enums\AccessModule;
use App\Enums\RelationshipDirection;
use App\Enums\UserRole;
use App\Models\DocumentationPage;
use App\Models\McpToken;
use App\Models\Notebook;
use App\Models\Solution;
use App\Models\UmlSolutionLink;
use App\Models\User;
use App\Services\SolutionRelationshipService;
use App\Support\Documentation\UmlDiagram;
use App\Support\GitbookRenderer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/*
 * The solution↔solution relationships derived from the documentation's UML
 * diagrams (docs-and-specs/solution-relationships-from-uml.md, phase 1).
 */

function relSolution(string $slug, string $category = 'erp', ?string $name = null): Solution
{
    return Solution::factory()->create(['slug' => $slug, 'name' => $name ?? ucfirst($slug), 'category' => $category]);
}

function relUml(string $type, array $body, string $title = 'Fluxo'): string
{
    return "{% uml type=\"{$type}\" title=\"{$title}\" %}\n"
        . json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        . "\n{% enduml %}";
}

function relPage(string $markdown, ?Notebook $notebook = null): DocumentationPage
{
    return DocumentationPage::factory()->for($notebook ?? Notebook::factory()->create())->create(['documentation' => $markdown]);
}

/** A sequence between bound participants: [[from, to, kind?], ...] over slugs. */
function relSequence(array $participants, array $messages): string
{
    return relUml('sequence', [
        'participants' => array_map(fn (string $slug) => ['id' => $slug, 'label' => $slug, 'solution' => $slug], $participants),
        'messages'     => array_map(fn (array $m) => ['from' => $m[0], 'to' => $m[1], 'label' => $m[3] ?? "{$m[0]}→{$m[1]}", 'kind' => $m[2] ?? 'sync'], $messages),
    ]);
}

/** @return array<string, RelationshipDirection> other slug → direction */
function relDirections(Solution $solution): array
{
    return app(SolutionRelationshipService::class)->forSolution($solution)
        ->mapWithKeys(fn ($r) => [$r->other->slug => $r->direction])
        ->all();
}

/* ------------------------------------------------------------------ */
/*  The binding */
/* ------------------------------------------------------------------ */

it('carries a binding through the validator, and refuses it on a kind that is not a system', function () {
    $ok = UmlDiagram::parse('component', '', json_encode([
        'nodes'  => [['id' => 'a', 'kind' => 'component', 'name' => 'A', 'solution' => 'sap-s4', 'at' => [0, 0]]],
        'groups' => [['label' => 'G', 'kind' => 'boundary', 'solution' => 'digibee', 'nodes' => ['a']]],
    ]));

    expect($ok['problems'])->toBe([])
        ->and($ok['diagram']['nodes'][0]['solution'])->toBe('sap-s4')
        ->and($ok['diagram']['groups'][0]['solution'])->toBe('digibee');

    $note = UmlDiagram::parse('component', '', json_encode([
        'nodes' => [['id' => 'n', 'kind' => 'note', 'text' => 'oi', 'solution' => 'sap-s4', 'at' => [0, 0]]],
    ]));
    expect(implode(' ', $note['problems']))->toContain('só elementos que podem ser um sistema aceitam "solution"');

    $shape = UmlDiagram::parse('sequence', '', json_encode([
        'participants' => [['id' => 'a', 'solution' => 'SAP S4'], ['id' => 'b']],
        'messages'     => [['from' => 'a', 'to' => 'b']],
    ]));
    expect(implode(' ', $shape['problems']))->toContain('deve ser o slug de uma solução');
});

it('refuses an unknown slug on an MCP write, but never over a block that did not change', function () {
    relSolution('sap-s4');
    $renderer = app(GitbookRenderer::class);
    $body = relSequence(['sap-s4', 'fantasma'], [['sap-s4', 'fantasma']]);

    expect(implode(' ', $renderer->problems($body)))->toContain('a solução "fantasma" não existe no catálogo')
        ->and($renderer->problems("Outro parágrafo\n\n{$body}", $body))->toBe([]);
});

it('tells the editor about an unknown slug and hints at a name that is a solution, without calling it an error', function () {
    $notebook = Notebook::factory()->create();
    $page = DocumentationPage::factory()->for($notebook)->create();
    relSolution('digibee-ipaas', 'ipaas', 'Digibee (iPaaS)');

    $response = $this->actingAs(User::factory()->admin()->create())
        ->postJson(route('notebooks.pages.uml', [$notebook, $page]), [
            'type'   => 'sequence',
            'source' => json_encode([
                'participants' => [['id' => 'a', 'label' => 'Digibee'], ['id' => 'b', 'label' => 'Outro', 'solution' => 'nao-existe']],
                'messages'     => [['from' => 'a', 'to' => 'b']],
            ]),
        ])->assertOk();

    expect($response->json('problems'))->toBe([])
        ->and($response->json('bindings')[0])->toContain('"nao-existe" não existe')
        ->and($response->json('hints')[0])->toContain('"Digibee" parece ser a solução Digibee (iPaaS)')
        ->and($response->json('hints')[0])->toContain('"solution": "digibee-ipaas"');
});

/* ------------------------------------------------------------------ */
/*  Direction */
/* ------------------------------------------------------------------ */

it('reads a sequence: a call is a direction, a return is not, both ways is mutual', function () {
    $a = relSolution('alfa');
    relSolution('beta');
    relSolution('gama');
    relSolution('delta');

    relPage(relSequence(['alfa', 'beta', 'gama', 'delta'], [
        ['alfa', 'beta'],
        ['beta', 'alfa', 'return'],
        ['gama', 'alfa', 'async'],
        ['alfa', 'delta'],
        ['delta', 'alfa', 'sync'],
    ]));

    expect(relDirections($a))->toBe([
        'beta'  => RelationshipDirection::Outgoing,
        'delta' => RelationshipDirection::Both,
        'gama'  => RelationshipDirection::Incoming,
    ]);
});

it('counts the messages inside fragments and ignores a gate and a self call', function () {
    $a = relSolution('alfa');
    relSolution('beta');

    relPage(relUml('sequence', [
        'participants' => [['id' => 'a', 'solution' => 'alfa'], ['id' => 'a2', 'solution' => 'alfa'], ['id' => 'b', 'solution' => 'beta']],
        'messages'     => [
            ['from' => 'a', 'to' => 'a2'],
            ['to' => 'a', 'gate' => true],
            ['fragment' => 'alt', 'label' => '[ok]', 'messages' => [['from' => 'a', 'to' => 'b']], 'else' => [
                ['label' => '[erro]', 'messages' => [['from' => 'b', 'to' => 'a', 'kind' => 'async']]],
            ]],
        ],
    ]));

    expect(relDirections($a))->toBe(['beta' => RelationshipDirection::Both]);
});

it('reads a structural diagram: directed edges call, an association has no direction, structure says nothing', function () {
    $a = relSolution('alfa');
    foreach (['beta', 'gama', 'delta'] as $slug) {
        relSolution($slug);
    }

    relPage(relUml('component', [
        'nodes' => [
            ['id' => 'a', 'kind' => 'component', 'name' => 'A', 'solution' => 'alfa', 'at' => [1, 1]],
            ['id' => 'b', 'kind' => 'component', 'name' => 'B', 'solution' => 'beta', 'at' => [2, 1]],
            ['id' => 'g', 'kind' => 'component', 'name' => 'G', 'solution' => 'gama', 'at' => [1, 2]],
            ['id' => 'd', 'kind' => 'component', 'name' => 'D', 'solution' => 'delta', 'at' => [0, 1]],
        ],
        'edges' => [
            ['from' => 'a', 'to' => 'b', 'kind' => 'dependency'],
            ['from' => 'g', 'to' => 'a', 'kind' => 'association'],
            ['from' => 'a', 'to' => 'd', 'kind' => 'composition'],
        ],
    ]));

    expect(relDirections($a))->toBe([
        'beta' => RelationshipDirection::Outgoing,
        'gama' => RelationshipDirection::Undirected,
    ]);
});

it('binds an unbound element to the innermost bound group around it', function () {
    $sap = relSolution('sap-s4');
    relSolution('sap-cpi', 'erp');

    relPage(relUml('component', [
        'nodes' => [
            ['id' => 'sap', 'kind' => 'component', 'name' => 'SAP', 'solution' => 'sap-s4', 'at' => [0, 0]],
            ['id' => 'iflow', 'kind' => 'component', 'name' => 'iFlow', 'at' => [1, 0]],
        ],
        'edges'  => [['from' => 'sap', 'to' => 'iflow', 'kind' => 'directed']],
        'groups' => [['label' => 'CPI', 'kind' => 'boundary', 'solution' => 'sap-cpi', 'nodes' => ['iflow']]],
    ]));

    expect(relDirections($sap))->toBe(['sap-cpi' => RelationshipDirection::Outgoing]);
});

/* ------------------------------------------------------------------ */
/*  Through a platform */
/* ------------------------------------------------------------------ */

it('infers the end-to-end pair through a platform in a sequence, and keeps the direct ones', function () {
    $sap = relSolution('sap');
    relSolution('cpi', 'ipaas', 'SAP CPI');
    relSolution('fresh');

    relPage(relSequence(['sap', 'cpi', 'fresh'], [['sap', 'cpi'], ['cpi', 'fresh']]));

    $relationships = app(SolutionRelationshipService::class)->forSolution($sap)->keyBy(fn ($r) => $r->other->slug);

    expect($relationships->keys()->all())->toBe(['fresh', 'cpi']) // by name: Fresh, SAP CPI
        ->and($relationships['cpi']->direct)->toBeTrue()
        ->and($relationships['fresh']->direct)->toBeFalse()
        ->and($relationships['fresh']->direction)->toBe(RelationshipDirection::Outgoing)
        ->and(array_map(fn ($p) => $p->slug, $relationships['fresh']->via))->toBe(['cpi']);
});

it('chains two platforms, but no other caller may come between', function () {
    $sap = relSolution('sap');
    relSolution('cpi', 'ipaas');
    relSolution('digibee', 'ipaas');
    relSolution('vtex');
    relSolution('outro');

    relPage(relSequence(['sap', 'cpi', 'digibee', 'vtex', 'outro'], [
        ['sap', 'cpi'], ['cpi', 'digibee'], ['digibee', 'vtex'],
    ]));
    relPage(relSequence(['sap', 'cpi', 'outro'], [
        ['sap', 'cpi'], ['outro', 'cpi'], ['cpi', 'sap'],
    ]));

    $relationships = app(SolutionRelationshipService::class)->forSolution($sap)->keyBy(fn ($r) => $r->other->slug);

    // SAP → VTEX via CPI and Digibee; and "outro" called CPI before CPI called
    // SAP, so that call is outro's, not a round trip of SAP's.
    expect(array_map(fn ($p) => $p->slug, $relationships['vtex']->via))->toBe(['cpi', 'digibee'])
        ->and($relationships['outro']->direction)->toBe(RelationshipDirection::Incoming)
        ->and($relationships['outro']->direct)->toBeFalse();
});

it('infers through a platform drawn as a box only when the picture is unambiguous', function () {
    foreach (['a', 'b', 'c', 'd'] as $slug) {
        relSolution($slug);
    }
    relSolution('hub', 'ipaas');

    $grid = fn (array $edges) => relUml('component', [
        'nodes' => [
            ['id' => 'a', 'kind' => 'component', 'name' => 'A', 'solution' => 'a', 'at' => [0, 0]],
            ['id' => 'b', 'kind' => 'component', 'name' => 'B', 'solution' => 'b', 'at' => [0, 2]],
            ['id' => 'h', 'kind' => 'component', 'name' => 'Hub', 'solution' => 'hub', 'at' => [1, 1]],
            ['id' => 'c', 'kind' => 'component', 'name' => 'C', 'solution' => 'c', 'at' => [2, 0]],
            ['id' => 'd', 'kind' => 'component', 'name' => 'D', 'solution' => 'd', 'at' => [2, 2]],
        ],
        'edges' => array_map(fn (array $e) => ['from' => $e[0], 'to' => $e[1], 'kind' => 'flow'], $edges),
    ]);

    // Fan-out: A → Hub → {C, D} says A reaches both.
    $page = relPage($grid([['a', 'h'], ['h', 'c'], ['h', 'd']]));
    expect(array_keys(relDirections(Solution::firstWhere('slug', 'a'))))->toBe(['c', 'd', 'hub']);

    // A hub with two on each side says nothing about who reaches whom.
    $page->update(['documentation' => $grid([['a', 'h'], ['b', 'h'], ['h', 'c'], ['h', 'd']])]);
    expect(array_keys(relDirections(Solution::firstWhere('slug', 'a'))))->toBe(['hub']);
});

it('never joins two diagrams through a platform, and follows a change of category without re-syncing', function () {
    $sap = relSolution('sap');
    $mid = relSolution('mid', 'erp');
    relSolution('fresh');

    $page = relPage(relSequence(['sap', 'mid', 'fresh'], [['sap', 'mid'], ['mid', 'fresh']]));
    relPage(relSequence(['mid', 'fresh'], [['mid', 'fresh']]));

    expect(array_keys(relDirections($sap)))->toBe(['mid']);

    $mid->update(['category' => 'ipaas']);
    expect(array_keys(relDirections($sap)))->toBe(['fresh', 'mid']);

    // The second page alone (mid → fresh) never gives SAP anything.
    $page->update(['documentation' => relSequence(['sap', 'mid'], [['sap', 'mid']])]);
    expect(array_keys(relDirections($sap)))->toBe(['mid']);
});

/* ------------------------------------------------------------------ */
/*  The index */
/* ------------------------------------------------------------------ */

it('re-syncs a page on every body save, not on a title save, and cascades with page and solution', function () {
    $a = relSolution('alfa');
    $b = relSolution('beta');
    $page = relPage(relSequence(['alfa', 'beta'], [['alfa', 'beta']]));

    expect(UmlSolutionLink::query()->count())->toBe(1);

    $page->update(['documentation' => 'Sem diagrama agora.']);
    expect(UmlSolutionLink::query()->count())->toBe(0);

    $page->update(['documentation' => relSequence(['alfa', 'beta'], [['alfa', 'beta'], ['beta', 'alfa']])]);
    UmlSolutionLink::query()->delete();
    $page->update(['title' => 'Outro título']);
    expect(UmlSolutionLink::query()->count())->toBe(0);

    $this->artisan('documentation:solution-links')->assertSuccessful();
    expect(UmlSolutionLink::query()->count())->toBe(2);

    $b->delete();
    expect(UmlSolutionLink::query()->count())->toBe(0);

    $b = relSolution('beta');
    app(SyncPageSolutionLinks::class)->handle($page);
    $page->delete();
    expect(UmlSolutionLink::query()->count())->toBe(0);
});

it('picks up a page that named a solution before it existed', function () {
    relSolution('alfa');
    $page = relPage(relSequence(['alfa', 'novo'], [['alfa', 'novo']]));
    expect(UmlSolutionLink::query()->count())->toBe(0);

    app(SaveSolution::class)->handle(['name' => 'Novo', 'slug' => 'novo', 'category' => 'erp', 'status' => 'active']);

    expect(UmlSolutionLink::query()->where('documentation_page_id', $page->id)->count())->toBe(1);
});

it('follows a slug rename into the UML blocks only, leaving the rest of the text alone', function () {
    $sap = relSolution('sap-velho');
    relSolution('beta');

    $diagram = relSequence(['sap-velho', 'beta'], [['sap-velho', 'beta']]);
    $page = relPage("Texto que cita \"solution\": \"sap-velho\" fora do diagrama.\n\n{$diagram}");

    app(SaveSolution::class)->handle([...$sap->only(['name', 'category']), 'slug' => 'sap-novo'], $sap);

    $body = $page->fresh()->documentation;
    // Only the binding moved: the prose and the participant's own id and
    // label (which happened to read "sap-velho") are the author's text.
    expect($body)->toContain('Texto que cita "solution": "sap-velho" fora do diagrama.')
        ->and(substr_count($body, '"solution": "sap-novo"'))->toBe(1)
        ->and(substr_count($body, '"solution": "sap-velho"'))->toBe(1)
        ->and(substr_count($body, '"id": "sap-velho"'))->toBe(1)
        ->and(UmlSolutionLink::query()->count())->toBe(1);
});

/* ------------------------------------------------------------------ */
/*  The solution page */
/* ------------------------------------------------------------------ */

it('shows the relationships on the solution page, with the arrow in words and the evidence', function () {
    $a = relSolution('alfa', name: 'Alfa');
    relSolution('beta', name: 'Beta');
    relSolution('cpi', 'ipaas', 'SAP CPI');
    relSolution('gama', name: 'Gama');

    $notebook = Notebook::factory()->create(['name' => 'Caderno X']);
    $page = relPage(relSequence(['alfa', 'beta', 'cpi', 'gama'], [['alfa', 'beta'], ['beta', 'alfa'], ['alfa', 'cpi'], ['cpi', 'gama']]), $notebook);

    $html = $this->actingAs(User::factory()->admin()->create())
        ->get(route('solutions.show', $a))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Relacionamentos')
        ->toContain('Chamam uma à outra')
        ->toContain('Alfa chama SAP CPI')
        ->toContain('via')
        ->toContain('Onde está documentado (1)')
        ->toContain(route('notebooks.pages.edit', [$notebook, $page]) . '#uml-figure-1')
        ->not->toContain('Nenhum diagrama ainda');
});

it('shows the relationships to a catalog reader, without the links into cadernos', function () {
    $a = relSolution('alfa', name: 'Alfa');
    relSolution('beta', name: 'Beta');
    relPage(relSequence(['alfa', 'beta'], [['alfa', 'beta']]));

    $reader = User::factory()->create(['role' => UserRole::Member->value, 'access' => [
        AccessModule::Catalog->value       => AccessLevel::Reader->value,
        AccessModule::Documentation->value => AccessLevel::None->value,
    ]]);

    $this->actingAs($reader)->get(route('solutions.show', $a))
        ->assertOk()
        ->assertSee('Alfa chama Beta')
        ->assertDontSee('Onde está documentado')
        ->assertDontSee('Cadernos');
});

it('says how relationships come to exist when there are none', function () {
    $html = $this->actingAs(User::factory()->admin()->create())
        ->get(route('solutions.show', relSolution('sozinha')))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Nenhum relacionamento documentado')
        ->toContain('com “solution”')
        ->not->toContain('&amp;quot;');
});

/* ------------------------------------------------------------------ */
/*  The map, the catalog counters and the MCP */
/* ------------------------------------------------------------------ */

it('draws the map from the documented pairs, with no head on an association and no via pair', function () {
    relSolution('alfa');
    relSolution('beta');
    relSolution('gama');
    relSolution('hub', 'ipaas');

    relPage(relSequence(['alfa', 'hub', 'beta'], [['alfa', 'hub'], ['hub', 'beta']]));
    relPage(relUml('component', [
        'nodes' => [
            ['id' => 'a', 'kind' => 'component', 'name' => 'A', 'solution' => 'alfa', 'at' => [0, 0]],
            ['id' => 'g', 'kind' => 'component', 'name' => 'G', 'solution' => 'gama', 'at' => [1, 0]],
        ],
        'edges' => [['from' => 'g', 'to' => 'a', 'kind' => 'association', 'label' => 'compartilha']],
    ]));

    $data = $this->actingAs(User::factory()->admin()->create())
        ->getJson(route('solutions.map.data'))
        ->assertOk()
        ->json();

    $edges = collect($data['edges'])->keyBy(fn (array $e) => collect($data['nodes'])->firstWhere('id', $e['source'])['slug'] . '>' . collect($data['nodes'])->firstWhere('id', $e['target'])['slug']);

    expect($edges->keys()->sort()->values()->all())->toBe(['alfa>gama', 'alfa>hub', 'hub>beta'])
        ->and($edges['alfa>gama']['direction'])->toBe('undirected')
        ->and($edges['alfa>gama']['label'])->toBe('compartilha')
        ->and($edges['alfa>hub']['direction'])->toBe('unidirectional')
        ->and($edges['alfa>hub']['evidence'][0]['url'])->toContain('#uml-figure-1');
});

it('counts relationships for the catalog strip and the spreadsheet', function () {
    $a = relSolution('alfa');
    relSolution('beta');
    relSolution('gama');
    relPage(relSequence(['alfa', 'beta', 'gama'], [['alfa', 'beta'], ['gama', 'alfa']]));

    expect(app(SolutionRelationshipService::class)->total())->toBe(2)
        ->and(app(SolutionRelationshipService::class)->counts()[$a->id])->toBe(2);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('solutions.index'))
        ->assertOk()
        ->assertSee('2 relacionamentos documentados');
});

it('answers get_solution with the relationships, hiding the evidence of an unpublished caderno from a token', function () {
    relSolution('alfa', name: 'Alfa');
    relSolution('beta', name: 'Beta');
    relSolution('gama', name: 'Gama');

    $published = Notebook::factory()->create(['slug' => 'publicado']);
    $published->forceFill(['published_at' => now()])->save();
    relPage(relSequence(['alfa', 'beta'], [['alfa', 'beta', 'sync', 'POST /x']]), $published);
    relPage(relSequence(['alfa', 'gama'], [['gama', 'alfa']]), Notebook::factory()->create());

    $result = $this->postJson(
        route('mcp.handle'),
        ['jsonrpc'       => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_solution', 'arguments' => ['slug' => 'alfa']]],
        ['Authorization' => 'Bearer ' . McpToken::mint('Test')['plain']],
    )->assertOk()->json('result');

    $relationships = collect(json_decode($result['content'][0]['text'], true)['relationships'])->keyBy('solution.slug');

    expect($relationships['beta']['direction'])->toBe('chama')
        ->and($relationships['beta']['labels'])->toBe(['POST /x'])
        ->and($relationships['beta']['evidence'][0]['notebook'])->toBe('publicado')
        ->and($relationships['gama']['direction'])->toBe('é chamada por')
        ->and($relationships['gama'])->not->toHaveKey('evidence');
});
