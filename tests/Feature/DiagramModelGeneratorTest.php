<?php

use App\Actions\Documentation\CreateDiagramFromModel;
use App\Enums\DiagramModel;
use App\Enums\UserRole;
use App\Exceptions\DiagramDraftFailed;
use App\Models\Diagram;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\Solution;
use App\Models\User;
use App\Services\Documentation\DiagramModelPromptBuilder;
use App\Services\Documentation\DiagramModelService;
use App\Support\Diagrams\ModelSpec;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

uses(LazilyRefreshDatabase::class);

function fakeModelService(string ...$replies): DiagramModelService
{
    return new class($replies) extends DiagramModelService
    {
        /** @var list<string> */
        public array $capturedPrompts = [];

        /** @param  list<string>  $replies */
        public function __construct(private array $replies)
        {
            parent::__construct(app(DiagramModelPromptBuilder::class));
        }

        protected function prompt(DiagramModel $model, string $prompt): AgentResponse
        {
            $this->capturedPrompts[] = $prompt;

            return new AgentResponse('fake', array_shift($this->replies) ?? '', new Usage(10, 20), new Meta('gemini', 'gemini-3.6-flash'));
        }
    };
}

function modelJson(array $payload): string
{
    return "Claro.\n\n```json\n" . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n```";
}

function modelPage(): DocumentationPage
{
    return DocumentationPage::factory()
        ->for(Notebook::factory()->create())
        ->create(['documentation' => 'A loja consulta a API de pedidos, que chama o SAP e responde.']);
}

function sequencePayload(array $overrides = []): array
{
    return array_merge([
        'name'         => 'Consulta de pedido',
        'participants' => [
            ['id' => 'loja', 'label' => 'Loja'],
            ['id' => 'api', 'label' => 'API'],
            ['id' => 'erp', 'label' => 'SAP'],
        ],
        'messages' => [
            ['from' => 'loja', 'to' => 'api', 'label' => 'GET /pedidos'],
            ['from' => 'api', 'to' => 'erp', 'label' => 'BAPI'],
            ['from' => 'erp', 'to' => 'api', 'label' => 'dados'],
            ['from' => 'api', 'to' => 'loja', 'label' => '200 OK'],
        ],
    ], $overrides);
}

// ---------------------------------------------------------------------------
// The spec: semantics only, and it says so.
// ---------------------------------------------------------------------------

it('accepts the shape each model asks for', function (DiagramModel $model, array $payload) {
    expect(ModelSpec::validate($model, $payload))->toBe([]);
})->with([
    'sequence'  => [DiagramModel::Sequence, fn () => sequencePayload()],
    'lifecycle' => [DiagramModel::Lifecycle, [
        'name'   => 'Execução',
        'states' => [
            ['id' => 'novo', 'label' => 'Recebido', 'kind' => 'start'],
            ['id' => 'proc', 'label' => 'Processando', 'kind' => 'active'],
            ['id' => 'ok', 'label' => 'Concluído', 'kind' => 'success'],
        ],
        'transitions' => [['from' => 'novo', 'to' => 'proc'], ['from' => 'proc', 'to' => 'ok']],
    ]],
    'dataflow' => [DiagramModel::Dataflow, [
        'name'  => 'Carga',
        'lanes' => [['id' => 'o', 'label' => 'Origem'], ['id' => 'c', 'label' => 'Consumo']],
        'nodes' => [
            ['id' => 'erp', 'label' => 'SAP', 'stage' => 'o'],
            ['id' => 'bq', 'label' => 'BigQuery', 'stage' => 'c'],
        ],
        'flows' => [['from' => 'erp', 'to' => 'bq', 'label' => 'carga']],
    ]],
    'workflow' => [DiagramModel::Workflow, [
        'name'  => 'Chamado',
        'lanes' => [['id' => 's', 'label' => 'Solicitante'], ['id' => 'ti', 'label' => 'TI']],
        'steps' => [
            ['id' => 'abre', 'label' => 'Abre', 'lane' => 's'],
            ['id' => 'tria', 'label' => 'Triagem', 'lane' => 'ti'],
        ],
        'flows' => [['from' => 'abre', 'to' => 'tria']],
    ]],
]);

it('names the link that points at an item that does not exist', function () {
    $problems = ModelSpec::validate(DiagramModel::Sequence, sequencePayload([
        'messages' => [['from' => 'loja', 'to' => 'fantasma', 'label' => 'x']],
    ]));

    expect($problems)->toHaveCount(1)->and($problems[0])->toContain('fantasma');
});

it('refuses a step filed under a lane nobody declared', function () {
    // The failure that would otherwise reach the layout and be placed at the
    // origin, silently on top of whatever is already there.
    $problems = ModelSpec::validate(DiagramModel::Workflow, [
        'name'  => 'X',
        'lanes' => [['id' => 'ti', 'label' => 'TI']],
        'steps' => [['id' => 'a', 'label' => 'A', 'lane' => 'financeiro']],
        'flows' => [],
    ]);

    expect(implode(' ', $problems))->toContain('financeiro');
});

// ---------------------------------------------------------------------------
// The layout: positions nobody asked the model for.
// ---------------------------------------------------------------------------

it('draws a sequence as lifelines, with every message at its own height', function () {
    $diagram = app(CreateDiagramFromModel::class)->handle(
        ModelSpec::from(DiagramModel::Sequence, sequencePayload())
    );

    $nodes = $diagram->chain['nodes'];
    $layout = $diagram->viz_layout;

    expect(collect($nodes)->pluck('kind')->unique()->all())->toBe(['lifeline'])
        // Side by side, all the same height — a column that stopped at its own
        // last message would read as "this participant left".
        ->and(collect($layout['nodes'])->pluck('y')->unique())->toHaveCount(1)
        ->and(collect($layout['nodes'])->pluck('height')->unique())->toHaveCount(1)
        ->and(collect($layout['nodes'])->pluck('x')->all())->toBe([60, 360, 660]);

    // Each message lands lower than the one before it, and the two ends of a
    // message are at the same height.
    $fractions = collect($layout['edges'])->pluck('fromT');

    expect($fractions->all())->toBe($fractions->sort()->values()->all())
        ->and($fractions->unique())->toHaveCount(4)
        ->and(collect($layout['edges'])->every(fn (array $e) => $e['fromT'] === $e['toT']))->toBeTrue();

    // A reply points back the other way, so it leaves on the other side.
    expect($layout['edges'][2]['from'])->toBe('l')
        ->and($layout['edges'][0]['from'])->toBe('r');
});

it('draws a process as lanes with the steps advancing along them', function () {
    $diagram = app(CreateDiagramFromModel::class)->handle(ModelSpec::from(DiagramModel::Workflow, [
        'name'  => 'Chamado',
        'lanes' => [['id' => 's', 'label' => 'Solicitante'], ['id' => 'ti', 'label' => 'TI']],
        'steps' => [
            ['id' => 'abre', 'label' => 'Abre', 'lane' => 's'],
            ['id' => 'tria', 'label' => 'Aprovado?', 'lane' => 'ti', 'kind' => 'decision'],
            ['id' => 'resolve', 'label' => 'Resolve', 'lane' => 'ti'],
        ],
        'flows' => [['from' => 'abre', 'to' => 'tria'], ['from' => 'tria', 'to' => 'resolve']],
    ]));

    $layout = $diagram->viz_layout;

    expect($layout['lanes'])->toHaveCount(2)
        ->and($layout['lanes'][0]['orientation'])->toBe('horizontal')
        // Two steps in the same lane advance; a step in another lane starts its
        // own count, because the order inside a lane is what the model expressed.
        ->and($layout['nodes'][1]['x'])->toBe($layout['nodes'][0]['x'])
        ->and($layout['nodes'][2]['x'])->toBeGreaterThan($layout['nodes'][1]['x'])
        ->and($layout['nodes'][1]['y'])->toBeGreaterThan($layout['nodes'][0]['y'])
        ->and($diagram->chain['nodes'][1]['kind'])->toBe('decision');
});

it('drops a lifecycle failure to its own row and dashes the way back', function () {
    $diagram = app(CreateDiagramFromModel::class)->handle(ModelSpec::from(DiagramModel::Lifecycle, [
        'name'   => 'Execução',
        'states' => [
            ['id' => 'novo', 'label' => 'Recebido', 'kind' => 'start'],
            ['id' => 'proc', 'label' => 'Processando', 'kind' => 'active'],
            ['id' => 'erro', 'label' => 'Falha', 'kind' => 'failure'],
            ['id' => 'ok', 'label' => 'Concluído', 'kind' => 'success'],
        ],
        'transitions' => [
            ['from' => 'novo', 'to' => 'proc', 'label' => 'início'],
            ['from' => 'proc', 'to' => 'erro', 'label' => 'exceção'],
            ['from' => 'erro', 'to' => 'proc', 'label' => 'retentativa'],
            ['from' => 'proc', 'to' => 'ok', 'label' => 'sucesso'],
        ],
    ]));

    $layout = $diagram->viz_layout;

    expect($layout['nodes'][2]['y'])->toBeGreaterThan($layout['nodes'][1]['y'])
        // Going down is the exception; coming back up is the retry, and it is
        // dashed like everything that is not the main course.
        ->and($layout['edges'][1]['dashed'])->toBeFalse()
        ->and($layout['edges'][2]['dashed'])->toBeTrue()
        ->and($diagram->chain['nodes'][0]['kind'])->toBe('start')
        ->and($diagram->chain['nodes'][3]['kind'])->toBe('end');
});

it('stacks a data flow down its stage and derives the participants', function () {
    $sap = Solution::factory()->create(['name' => 'SAP S/4HANA']);
    $bq = Solution::factory()->create(['name' => 'Google BigQuery']);

    $diagram = app(CreateDiagramFromModel::class)->handle(ModelSpec::from(DiagramModel::Dataflow, [
        'name'  => 'Carga',
        'lanes' => [['id' => 'o', 'label' => 'Origem'], ['id' => 'c', 'label' => 'Consumo']],
        'nodes' => [
            ['id' => 'erp', 'label' => 'SAP', 'solution' => 'SAP S/4HANA', 'stage' => 'o'],
            ['id' => 'stg', 'label' => 'Staging', 'stage' => 'o'],
            ['id' => 'bq', 'label' => 'BigQuery', 'solution' => 'Google BigQuery', 'stage' => 'c'],
        ],
        'flows' => [['from' => 'erp', 'to' => 'stg'], ['from' => 'stg', 'to' => 'bq']],
    ]));

    $layout = $diagram->viz_layout;

    expect($layout['lanes'][0]['orientation'])->toBe('vertical')
        ->and($layout['nodes'][1]['x'])->toBe($layout['nodes'][0]['x'])
        ->and($layout['nodes'][1]['y'])->toBeGreaterThan($layout['nodes'][0]['y'])
        ->and($layout['nodes'][2]['x'])->toBeGreaterThan($layout['nodes'][0]['x'])
        // An unmatched name stays as free text; the two that matched become
        // participants, which is what the ecosystem map reads.
        ->and($diagram->chain['nodes'][1]['label'])->toBe('Staging')
        ->and($diagram->participants()->pluck('solutions.id')->all())->toEqualCanonicalizing([$sap->id, $bq->id]);
});

// ---------------------------------------------------------------------------
// The service and the endpoint.
// ---------------------------------------------------------------------------

it('asks for semantics and never for a coordinate', function () {
    // The catalog is only handed over when there IS one — an empty list would
    // say nothing and cost tokens.
    Solution::factory()->create(['name' => 'CWS']);

    $service = fakeModelService(modelJson(sequencePayload()));
    $spec = $service->draft(modelPage(), DiagramModel::Sequence);

    expect($spec->name)->toBe('Consulta de pedido')
        ->and($service->capturedPrompts)->toHaveCount(1)
        ->and($service->capturedPrompts[0])->toContain('CATÁLOGO DE SOLUÇÕES');
});

it('repairs once, then gives up', function () {
    $broken = modelJson(sequencePayload(['messages' => [['from' => 'loja', 'to' => 'fantasma', 'label' => 'x']]]));

    $ok = fakeModelService($broken, modelJson(sequencePayload()));
    expect($ok->draft(modelPage(), DiagramModel::Sequence)->name)->toBe('Consulta de pedido');
    expect($ok->capturedPrompts)->toHaveCount(2)
        ->and($ok->capturedPrompts[1])->toContain('fantasma');

    $never = fakeModelService($broken, $broken);
    expect(fn () => $never->draft(modelPage(), DiagramModel::Sequence))->toThrow(DiagramDraftFailed::class);
});

it('takes "this page has no such diagram" for an answer', function () {
    $service = fakeModelService('```json' . "\n" . '{"error": "a página não descreve nenhuma sequência"}' . "\n" . '```');

    expect(fn () => $service->draft(modelPage(), DiagramModel::Sequence))
        ->toThrow(DiagramDraftFailed::class, 'não descreve nenhuma sequência');
});

it('creates the drawing and sends the author to its canvas', function () {
    $page = modelPage();
    app()->instance(DiagramModelService::class, fakeModelService(modelJson(sequencePayload())));

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->postJson(route('notebooks.pages.diagram.model', [$page->notebook, $page, 'sequence']))
        ->assertOk()
        ->assertJsonPath('redirect', route('diagrams.show', Diagram::sole()));

    expect(Diagram::sole()->chain['nodes'])->toHaveCount(3)
        // The page is read, never written.
        ->and($page->fresh()->documentation)->toContain('A loja consulta');
});

it('refuses a shape that is not one of the four', function () {
    $page = modelPage();

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->postJson(route('notebooks.pages.diagram.model', [$page->notebook, $page, 'architecture']))
        ->assertNotFound();
});

it('refuses a viewer', function () {
    $page = modelPage();
    app()->instance(DiagramModelService::class, fakeModelService(modelJson(sequencePayload())));

    $this->actingAs(User::factory()->create(['role' => UserRole::Viewer->value]))
        ->postJson(route('notebooks.pages.diagram.model', [$page->notebook, $page, 'sequence']))
        ->assertForbidden();

    expect(Diagram::count())->toBe(0);
});

it('names the drawing after the model it was generated as', function () {
    $page = modelPage();
    app()->instance(DiagramModelService::class, fakeModelService(modelJson(sequencePayload())));

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->postJson(route('notebooks.pages.diagram.model', [$page->notebook, $page, 'sequence']))
        ->assertOk();

    // The suffix is what tells four drawings of the same page apart in the
    // catalog; the slug is built from the suffixed name, so the address says it
    // too.
    expect(Diagram::sole()->name)->toBe('Consulta de pedido — Sequência')
        ->and(Diagram::sole()->slug)->toBe('consulta-de-pedido-sequencia');
});

it('does not repeat a suffix the model already wrote', function () {
    expect(DiagramModel::Workflow->suffixed('Compras — Processo'))->toBe('Compras — Processo')
        ->and(DiagramModel::Workflow->suffixed('compras — processo'))->toBe('compras — processo')
        ->and(DiagramModel::Workflow->suffixed('  Compras  '))->toBe('Compras — Processo');
});

it('gives way on the base rather than on the type when the name is too long', function () {
    $name = DiagramModel::Dataflow->suffixed(str_repeat('a', 300));

    expect(mb_strlen($name))->toBe(255)
        ->and($name)->toEndWith('— Fluxo de dados');
});
