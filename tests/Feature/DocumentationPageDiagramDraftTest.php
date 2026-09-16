<?php

use App\Actions\Documentation\CreateDiagramFromDraft;
use App\Enums\UserRole;
use App\Exceptions\DiagramDraftFailed;
use App\Models\Diagram;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\Solution;
use App\Models\User;
use App\Services\Documentation\DiagramDraftPromptBuilder;
use App\Services\Documentation\DiagramDraftService;
use App\Support\Documentation\ChainDraft;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

uses(LazilyRefreshDatabase::class);

/**
 * Test double for the service: returns scripted answers, one per call, instead
 * of reaching the API. A second answer is what the repair round gets.
 */
function fakeDraftService(string ...$replies): DiagramDraftService
{
    return new class($replies) extends DiagramDraftService
    {
        /** @var list<string> */
        public array $capturedPrompts = [];

        /** @param list<string> $replies */
        public function __construct(private array $replies)
        {
            parent::__construct(app(DiagramDraftPromptBuilder::class));
        }

        protected function prompt(string $prompt): AgentResponse
        {
            $this->capturedPrompts[] = $prompt;

            $reply = array_shift($this->replies) ?? '';

            return new AgentResponse('fake', $reply, new Usage(10, 20), new Meta('gemini', 'gemini-3.6-flash'));
        }
    };
}

function draftJson(array $payload): string
{
    return "Claro.\n\n```json\n" . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n```";
}

function pageWithDocumentation(string $documentation = 'O CWS envia pedidos ao SAP via REST.'): DocumentationPage
{
    return DocumentationPage::factory()
        ->for(Notebook::factory()->create())
        ->create(['documentation' => $documentation]);
}

function drawUrl(DocumentationPage $page): string
{
    return route('notebooks.pages.diagram', [$page->notebook, $page]);
}

// ---------------------------------------------------------------------------
// The IR contract. Pure — the validator never touches the database, which is
// the point of it being a value object rather than a FormRequest.
// ---------------------------------------------------------------------------

it('accepts the shape the prompt asks for', function () {
    expect(ChainDraft::validate([
        'name'  => 'CWS → SAP',
        'nodes' => [
            ['id' => 'cws', 'kind' => 'system', 'solution' => 'CWS'],
            ['id' => 'sap', 'kind' => 'system', 'label' => 'SAP'],
        ],
        'edges' => [['from' => 'cws', 'to' => 'sap', 'arrow' => '->', 'protocol' => 'REST']],
    ]))->toBe([]);
});

it('names the edge that points at a block that does not exist', function () {
    // The reason the IR uses string ids at all: as an integer index into
    // `nodes`, this mistake is invisible and draws a confident arrow between
    // the wrong two systems.
    $problems = ChainDraft::validate([
        'name'  => 'X',
        'nodes' => [['id' => 'a', 'kind' => 'system', 'label' => 'A']],
        'edges' => [['from' => 'a', 'to' => 'ghost', 'arrow' => '->']],
    ]);

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('"ghost"');
});

it('refuses a duplicate id, a self-edge and an arrow outside the vocabulary', function () {
    $problems = ChainDraft::validate([
        'name'  => 'X',
        'nodes' => [
            ['id' => 'a', 'kind' => 'system', 'label' => 'A'],
            ['id' => 'a', 'kind' => 'system', 'label' => 'Outro A'],
        ],
        'edges' => [['from' => 'a', 'to' => 'a', 'arrow' => '=>']],
    ]);

    expect(implode(' ', $problems))
        ->toContain('mais de um bloco')
        ->toContain('a ele mesmo')
        ->toContain('"arrow" inválido');
});

it('refuses a solution on a block that is not a system', function () {
    // Same rule `ChainNodeKind::referencesSolution()` enforces on the way in.
    // Without it a draft could produce a node the canvas reads as free text
    // while the model believed it had linked a catalog system.
    $problems = ChainDraft::validate([
        'name'  => 'X',
        'nodes' => [['id' => 'd', 'kind' => 'decision', 'label' => 'Aprovado?', 'solution' => 'CWS']],
        'edges' => [],
    ]);

    expect(implode(' ', $problems))->toContain('só "system" referencia');
});

it('refuses an image block, which no model can author', function () {
    // It carries a `media_id` that only a paste on the canvas produces — the
    // same reason `pickable()` keeps it out of the kind picker.
    $problems = ChainDraft::validate([
        'name'  => 'X',
        'nodes' => [['id' => 'i', 'kind' => 'image', 'label' => 'Foto']],
        'edges' => [],
    ]);

    expect(implode(' ', $problems))->toContain('"kind" inválido');
});

it('lets a start and an end block carry no label, and nothing else', function () {
    expect(ChainDraft::validate([
        'name'  => 'X',
        'nodes' => [['id' => 's', 'kind' => 'start'], ['id' => 'e', 'kind' => 'end']],
        'edges' => [],
    ]))->toBe([]);

    expect(implode(' ', ChainDraft::validate([
        'name'  => 'X',
        'nodes' => [['id' => 'n', 'kind' => 'system']],
        'edges' => [],
    ])))->toContain('precisa de "label" ou de "solution"');
});

// ---------------------------------------------------------------------------
// Draft → drawing.
// ---------------------------------------------------------------------------

it('resolves a catalog name exactly, and keeps everything else as free text', function () {
    $cws = Solution::factory()->create(['name' => 'CWS']);
    Solution::factory()->create(['name' => 'SAP S/4HANA']);

    $diagram = app(CreateDiagramFromDraft::class)->handle(ChainDraft::fromArray([
        'name'  => 'CWS → SAP',
        'nodes' => [
            ['id' => 'a', 'kind' => 'system', 'solution' => 'CWS'],
            // Merely CONTAINED in a catalog name. `whereFolded` would have
            // matched "SAP S/4HANA" here and drawn a relationship in the
            // ecosystem map between two systems that have none.
            ['id' => 'b', 'kind' => 'system', 'solution' => 'SAP'],
        ],
        'edges' => [['from' => 'a', 'to' => 'b', 'arrow' => '->', 'protocol' => 'REST']],
    ]));

    expect($diagram->chain['nodes'][0]['solution_id'])->toBe($cws->id)
        ->and($diagram->chain['nodes'][0]['label'])->toBeNull()
        ->and($diagram->chain['nodes'][1]['solution_id'])->toBeNull()
        ->and($diagram->chain['nodes'][1]['label'])->toBe('SAP');
});

it('matches a catalog name written without its accents', function () {
    $solution = Solution::factory()->create(['name' => 'Gestão de Fretes']);

    $diagram = app(CreateDiagramFromDraft::class)->handle(ChainDraft::fromArray([
        'name'  => 'X',
        'nodes' => [['id' => 'a', 'kind' => 'system', 'solution' => 'gestao de fretes']],
        'edges' => [],
    ]));

    expect($diagram->chain['nodes'][0]['solution_id'])->toBe($solution->id);
});

it('turns the draft ids into the chain indices the canvas addresses', function () {
    $diagram = app(CreateDiagramFromDraft::class)->handle(ChainDraft::fromArray([
        'name'  => 'Fluxo',
        'nodes' => [
            ['id' => 'inicio', 'kind' => 'start'],
            ['id' => 'sis', 'kind' => 'system', 'label' => 'Sistema'],
            ['id' => 'fim', 'kind' => 'end'],
        ],
        // Deliberately out of order, and skipping a neighbour: the chain is a
        // free graph, so nothing here may assume edges follow node order.
        'edges' => [
            ['from' => 'fim', 'to' => 'sis', 'arrow' => '<-'],
            ['from' => 'inicio', 'to' => 'fim', 'arrow' => '->'],
        ],
    ]));

    expect($diagram->chain['edges'])->toBe([
        ['from' => 2, 'to' => 1, 'arrow' => '<-', 'protocol' => null],
        ['from' => 0, 'to' => 2, 'arrow' => '->', 'protocol' => null],
    ]);
});

it('derives the diagram participants from the drawing it just created', function () {
    $a = Solution::factory()->create(['name' => 'Alpha']);
    $b = Solution::factory()->create(['name' => 'Beta']);

    $diagram = app(CreateDiagramFromDraft::class)->handle(ChainDraft::fromArray([
        'name'  => 'Alpha → Beta',
        'nodes' => [
            ['id' => 'a', 'kind' => 'system', 'solution' => 'Alpha'],
            ['id' => 'b', 'kind' => 'system', 'solution' => 'Beta'],
        ],
        'edges' => [['from' => 'a', 'to' => 'b', 'arrow' => '->', 'protocol' => 'REST']],
    ]));

    // What comes out is an ORDINARY diagram: the ecosystem map reads it like
    // any other, which only holds if afterChainMutation() ran.
    expect($diagram->participants()->pluck('solutions.id')->all())->toEqualCanonicalizing([$a->id, $b->id])
        ->and($diagram->source_solution_id)->toBe($a->id)
        ->and($diagram->target_solution_id)->toBe($b->id)
        ->and($diagram->protocol)->toBe('REST');
});

it('gives a second diagram of the same name an address of its own', function () {
    $draft = ChainDraft::fromArray([
        'name'  => 'Mesmo nome',
        'nodes' => [['id' => 'a', 'kind' => 'system', 'label' => 'A']],
        'edges' => [],
    ]);

    $first = app(CreateDiagramFromDraft::class)->handle($draft);
    $second = app(CreateDiagramFromDraft::class)->handle($draft);

    expect($first->slug)->toBe('mesmo-nome')->and($second->slug)->toBe('mesmo-nome-2');
});

// ---------------------------------------------------------------------------
// The service: one call, then at most one repair round.
// ---------------------------------------------------------------------------

it('reads the page and proposes what it describes', function () {
    Solution::factory()->create(['name' => 'CWS']);
    $page = pageWithDocumentation();

    $service = fakeDraftService(draftJson([
        'name'  => 'CWS → SAP',
        'nodes' => [
            ['id' => 'cws', 'kind' => 'system', 'solution' => 'CWS'],
            ['id' => 'sap', 'kind' => 'system', 'label' => 'SAP'],
        ],
        'edges' => [['from' => 'cws', 'to' => 'sap', 'arrow' => '->', 'protocol' => 'REST']],
    ]));

    $draft = $service->draft($page);

    expect($draft->name)->toBe('CWS → SAP')
        ->and($draft->nodes)->toHaveCount(2)
        ->and($service->capturedPrompts)->toHaveCount(1)
        // The catalog is what stops the model writing what it remembers a
        // system is called.
        ->and($service->capturedPrompts[0])->toContain('CATÁLOGO DE SOLUÇÕES')
        ->and($service->capturedPrompts[0])->toContain('O CWS envia pedidos');
});

it('asks for a repair once, then accepts the corrected answer', function () {
    $page = pageWithDocumentation();

    $service = fakeDraftService(
        draftJson([
            'name'  => 'X',
            'nodes' => [['id' => 'a', 'kind' => 'system', 'label' => 'A']],
            'edges' => [['from' => 'a', 'to' => 'ghost', 'arrow' => '->']],
        ]),
        draftJson([
            'name'  => 'X',
            'nodes' => [['id' => 'a', 'kind' => 'system', 'label' => 'A'], ['id' => 'b', 'kind' => 'system', 'label' => 'B']],
            'edges' => [['from' => 'a', 'to' => 'b', 'arrow' => '->']],
        ]),
    );

    expect($service->draft($page)->edges)->toHaveCount(1)
        ->and($service->capturedPrompts)->toHaveCount(2)
        // The repair round is given the problems and the payload — never the
        // page again, which would invite a redraw instead of a fix.
        ->and($service->capturedPrompts[1])->toContain('"ghost"')
        ->and($service->capturedPrompts[1])->not->toContain('O CWS envia pedidos');
});

it('stops after that one repair rather than drawing something it could not validate', function () {
    $page = pageWithDocumentation();

    $broken = draftJson([
        'name'  => 'X',
        'nodes' => [['id' => 'a', 'kind' => 'system', 'label' => 'A']],
        'edges' => [['from' => 'a', 'to' => 'ghost', 'arrow' => '->']],
    ]);

    $service = fakeDraftService($broken, $broken);

    expect(fn () => $service->draft($page))->toThrow(DiagramDraftFailed::class);
    expect($service->capturedPrompts)->toHaveCount(2)
        ->and(Diagram::count())->toBe(0);
});

it('reads a bare JSON object when the model forgets the fence', function () {
    $page = pageWithDocumentation();

    $service = fakeDraftService(json_encode([
        'name'  => 'X',
        'nodes' => [['id' => 'a', 'kind' => 'system', 'label' => 'A']],
        'edges' => [],
    ]));

    expect($service->draft($page)->name)->toBe('X');
});

it('refuses to draw a page with nothing on it', function () {
    $page = DocumentationPage::factory()->for(Notebook::factory()->create())->create(['documentation' => null]);
    $service = fakeDraftService(draftJson(['name' => 'X', 'nodes' => [], 'edges' => []]));

    expect(fn () => $service->draft($page))->toThrow(DiagramDraftFailed::class);
    // Cheaper than a model call, and a truer answer.
    expect($service->capturedPrompts)->toBeEmpty();
});

// ---------------------------------------------------------------------------
// The endpoint.
// ---------------------------------------------------------------------------

it('creates the drawing and sends the author to its canvas', function () {
    Solution::factory()->create(['name' => 'CWS']);
    $page = pageWithDocumentation();

    app()->instance(DiagramDraftService::class, fakeDraftService(draftJson([
        'name'  => 'CWS → SAP',
        'nodes' => [
            ['id' => 'cws', 'kind' => 'system', 'solution' => 'CWS'],
            ['id' => 'sap', 'kind' => 'system', 'label' => 'SAP'],
        ],
        'edges' => [['from' => 'cws', 'to' => 'sap', 'arrow' => '->', 'protocol' => 'REST']],
    ])));

    $response = $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->postJson(drawUrl($page));

    $diagram = Diagram::sole();

    $response->assertOk()->assertJsonPath('redirect', route('diagrams.show', $diagram));

    expect($diagram->name)->toBe('CWS → SAP')
        ->and($diagram->chain['nodes'])->toHaveCount(2)
        // The page is READ, never written, and nothing links the two afterwards
        // — prose reaches a drawing by citing it.
        ->and($page->fresh()->documentation)->toBe('O CWS envia pedidos ao SAP via REST.');
});

it('answers a failed draft with a message rather than a 500', function () {
    $page = pageWithDocumentation();

    app()->instance(DiagramDraftService::class, fakeDraftService('Não consegui.', 'Ainda não.'));

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->postJson(drawUrl($page))
        ->assertStatus(422)
        ->assertJsonStructure(['message']);

    expect(Diagram::count())->toBe(0);
});

it('refuses an account that may read the caderno but not write a diagram', function () {
    $page = pageWithDocumentation();

    app()->instance(DiagramDraftService::class, fakeDraftService(draftJson([
        'name'  => 'X',
        'nodes' => [['id' => 'a', 'kind' => 'system', 'label' => 'A']],
        'edges' => [],
    ])));

    $this->actingAs(User::factory()->create(['role' => UserRole::Viewer->value]))
        ->postJson(drawUrl($page))
        ->assertForbidden();

    expect(Diagram::count())->toBe(0);
});

it('offers the button to a writer and withholds it from a viewer', function () {
    $page = pageWithDocumentation();

    $this->actingAs(User::factory()->create(['role' => UserRole::Writer->value]))
        ->get(route('notebooks.pages.edit', [$page->notebook, $page]))
        ->assertOk()
        ->assertSee('Desenhar esta página');

    $this->actingAs(User::factory()->create(['role' => UserRole::Viewer->value]))
        ->get(route('notebooks.pages.edit', [$page->notebook, $page]))
        ->assertOk()
        ->assertDontSee('Desenhar esta página');
});
