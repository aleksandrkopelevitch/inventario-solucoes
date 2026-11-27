<?php

use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\Solution;
use App\Models\User;
use App\Services\Documentation\UmlDraftPromptBuilder;
use App\Services\Documentation\UmlDraftService;
use App\Support\Documentation\UmlSolutionBindings;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

uses(LazilyRefreshDatabase::class);

/*
 * "Gerar com IA" on a "Diagrama UML" block: the model proposes the JSON body,
 * the page's own validator judges it, one repair round, nothing written.
 * Spec: docs-and-specs/uml-ai-generation.md.
 */

function fakeUmlDraft(string|Throwable ...$replies): UmlDraftService
{
    return new class($replies) extends UmlDraftService
    {
        /** @var list<string> */
        public array $capturedPrompts = [];

        /** @var list<string> */
        public array $capturedTypes = [];

        /** @param list<string|Throwable> $replies */
        public function __construct(private array $replies)
        {
            parent::__construct(app(UmlDraftPromptBuilder::class), app(UmlSolutionBindings::class));
        }

        protected function prompt(string $type, string $prompt): AgentResponse
        {
            $this->capturedPrompts[] = $prompt;
            $this->capturedTypes[] = $type;
            $reply = array_shift($this->replies) ?? '';

            if ($reply instanceof Throwable) {
                throw $reply;
            }

            return new AgentResponse('fake', $reply, new Usage(10, 20), new Meta('gemini', 'gemini-3.8-flash'));
        }
    };
}

function umlReply(array $payload): string
{
    return "Aqui está:\n\n```json\n" . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n```";
}

function umlDraftPage(string $documentation = 'O VTEX envia o pedido ao Digibee, que o grava no SAP.'): DocumentationPage
{
    return DocumentationPage::factory()->for(Notebook::factory()->create())->create(['documentation' => $documentation]);
}

/** A valid component diagram: VTEX → SAP, side by side. */
function validComponents(array $overrides = []): array
{
    return [
        'nodes' => [
            ['id' => 'vtex', 'kind' => 'component', 'name' => 'VTEX', 'at' => [0, 0], ...($overrides['vtex'] ?? [])],
            ['id' => 'sap', 'kind' => 'component', 'name' => 'SAP', 'at' => [1, 0]],
        ],
        'edges' => [['from' => 'vtex', 'to' => 'sap', 'kind' => 'directed', 'label' => 'pedido']],
    ];
}

function draftUrl(DocumentationPage $page): string
{
    return route('notebooks.pages.uml.draft', [$page->notebook, $page]);
}

it('proposes a diagram from the page and writes nothing', function () {
    $page = umlDraftPage();
    $service = fakeUmlDraft(umlReply(validComponents()));
    app()->instance(UmlDraftService::class, $service);

    $response = $this->actingAs(User::factory()->editor()->create())
        ->postJson(draftUrl($page), ['type' => 'component', 'title' => 'Pedidos', 'content' => 'O texto que está no editor agora.'])
        ->assertOk();

    $source = json_decode($response->json('source'), true);

    expect($source['nodes'][0]['name'])->toBe('VTEX')
        ->and($response->json('problems'))->toBe([])
        ->and($response->json('warnings'))->toBe([])
        // Indented like the block's own skeleton.
        ->and($response->json('source'))->toContain("\n  \"nodes\"")
        ->and($service->capturedTypes)->toBe(['component'])
        ->and($service->capturedPrompts)->toHaveCount(1)
        // The editor's CURRENT text is what the model reads.
        ->and($service->capturedPrompts[0])->toContain('O texto que está no editor agora.')
        ->and($service->capturedPrompts[0])->not->toContain('grava no SAP')
        ->and($page->fresh()->documentation)->toBe('O VTEX envia o pedido ao Digibee, que o grava no SAP.');
});

it('reads the saved page when the editor sends no content', function () {
    $page = umlDraftPage();
    $service = fakeUmlDraft(umlReply(validComponents()));
    app()->instance(UmlDraftService::class, $service);

    $this->actingAs(User::factory()->editor()->create())
        ->postJson(draftUrl($page), ['type' => 'component'])
        ->assertOk();

    expect($service->capturedPrompts[0])->toContain('grava no SAP');
});

it('repairs a diagram once, telling the model what the validator said and not the page again', function () {
    $page = umlDraftPage();
    // Two elements in one cell: the validator refuses it.
    $service = fakeUmlDraft(umlReply(validComponents(['vtex' => ['at' => [1, 0]]])), umlReply(validComponents()));
    app()->instance(UmlDraftService::class, $service);

    $response = $this->actingAs(User::factory()->editor()->create())
        ->postJson(draftUrl($page), ['type' => 'component'])
        ->assertOk();

    expect($response->json('problems'))->toBe([])
        ->and($service->capturedPrompts)->toHaveCount(2)
        ->and($service->capturedPrompts[1])->toContain('tem problemas')
        ->and($service->capturedPrompts[1])->toContain('"at"')
        ->and($service->capturedPrompts[1])->not->toContain('grava no SAP');
});

it('returns the best attempt WITH its problems when the repair does not fix it', function () {
    $page = umlDraftPage();
    $broken = umlReply(validComponents(['vtex' => ['at' => [1, 0]]]));
    app()->instance(UmlDraftService::class, fakeUmlDraft($broken, $broken));

    $response = $this->actingAs(User::factory()->editor()->create())
        ->postJson(draftUrl($page), ['type' => 'component'])
        ->assertOk();

    expect(json_decode($response->json('source'), true)['nodes'])->toHaveCount(2)
        ->and($response->json('problems'))->not->toBe([]);
});

it('keeps the first attempt when the repair comes back unreadable', function () {
    $page = umlDraftPage();
    app()->instance(UmlDraftService::class, fakeUmlDraft(umlReply(validComponents(['vtex' => ['at' => [1, 0]]])), 'desculpe'));

    $response = $this->actingAs(User::factory()->editor()->create())
        ->postJson(draftUrl($page), ['type' => 'component'])
        ->assertOk();

    expect(json_decode($response->json('source'), true)['nodes'][0]['id'])->toBe('vtex')
        ->and($response->json('problems'))->not->toBe([]);
});

it('counts a slug the catalog does not have as a problem to repair, and answers the logos of the ones it has', function () {
    Solution::factory()->create(['slug' => 'vtex', 'name' => 'VTEX', 'category' => 'erp', 'logo_path' => '/logos/vtex.svg']);
    $page = umlDraftPage();
    $bad = validComponents(['vtex' => ['solution' => 'vtex-inventado']]);
    $good = validComponents(['vtex' => ['solution' => 'vtex']]);
    $service = fakeUmlDraft(umlReply($bad), umlReply($good));
    app()->instance(UmlDraftService::class, $service);

    $response = $this->actingAs(User::factory()->editor()->create())
        ->postJson(draftUrl($page), ['type' => 'component'])
        ->assertOk();

    expect($service->capturedPrompts[1])->toContain('"vtex-inventado" não existe no catálogo')
        ->and($response->json('problems'))->toBe([])
        ->and($response->json('logos'))->toBe(['vtex' => '/logos/vtex.svg']);
});

it('sends the current diagram and the instruction for a change, and drops an echoed type', function () {
    $page = umlDraftPage();
    $service = fakeUmlDraft(umlReply(['type' => 'class', 'title' => 'x', ...validComponents()]));
    app()->instance(UmlDraftService::class, $service);

    $response = $this->actingAs(User::factory()->editor()->create())
        ->postJson(draftUrl($page), [
            'type'        => 'component',
            'instruction' => 'adicione o retry no Digibee',
            'source'      => '{"nodes": [{"id": "atual", "kind": "component", "name": "Atual", "at": [0, 0]}]}',
        ])
        ->assertOk();

    expect($service->capturedPrompts[0])->toContain('DIAGRAMA ATUAL')
        ->and($service->capturedPrompts[0])->toContain('"id": "atual"')
        ->and($service->capturedPrompts[0])->toContain('adicione o retry no Digibee')
        ->and(json_decode($response->json('source'), true))->not->toHaveKeys(['type', 'title']);
});

it('generates from the instruction alone on an empty page, and refuses with neither', function () {
    $page = umlDraftPage('');
    app()->instance(UmlDraftService::class, fakeUmlDraft(umlReply(validComponents())));
    $editor = User::factory()->editor()->create();

    $this->actingAs($editor)
        ->postJson(draftUrl($page), ['type' => 'component', 'instruction' => 'VTEX chama o SAP'])
        ->assertOk();

    $response = $this->actingAs($editor)
        ->postJson(draftUrl($page), ['type' => 'component'])
        ->assertStatus(422)
        ->assertJson(['type' => 'warning']);

    expect($response->json('message'))->toContain('Descreva o diagrama');
});

it('turns a refusal, no JSON and a hung provider into a 422 that says what happened', function () {
    $page = umlDraftPage();
    $editor = User::factory()->editor()->create();

    app()->instance(UmlDraftService::class, fakeUmlDraft(umlReply(['error' => 'A página é um glossário de campos.'])));
    expect($this->actingAs($editor)->postJson(draftUrl($page), ['type' => 'sequence'])->assertStatus(422)->json('message'))
        ->toContain('A página é um glossário de campos');

    app()->instance(UmlDraftService::class, fakeUmlDraft('Não sei desenhar isso.'));
    expect($this->actingAs($editor)->postJson(draftUrl($page), ['type' => 'sequence'])->assertStatus(422)->json('message'))
        ->toContain('não devolveu um diagrama legível');

    app()->instance(UmlDraftService::class, fakeUmlDraft(new ConnectionException('timeout')));
    expect($this->actingAs($editor)->postJson(draftUrl($page), ['type' => 'sequence'])->assertStatus(422)->json('message'))
        ->toContain('demorou demais');
});

it('is refused to a reader and validates the type', function () {
    $page = umlDraftPage();
    app()->instance(UmlDraftService::class, fakeUmlDraft(umlReply(validComponents())));

    $this->actingAs(User::factory()->reader()->create())
        ->postJson(draftUrl($page), ['type' => 'component'])
        ->assertForbidden();

    $this->actingAs(User::factory()->editor()->create())
        ->postJson(draftUrl($page), ['type' => 'mermaid'])
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// The prompts
// ---------------------------------------------------------------------------

it('teaches the syntax from the guide\'s UML section, and only that section', function () {
    $section = UmlDraftPromptBuilder::guideSection();
    $system = app(UmlDraftPromptBuilder::class)->systemPrompt('sequence');

    expect($section)->toStartWith('## 5. Diagramas UML')
        ->and($section)->toContain('"participants"')
        ->and($section)->toContain('"solution"')
        ->and($section)->not->toContain('## 6.')
        ->and(strlen($section))->toBeGreaterThan(3000)
        ->and($system)->toContain($section)
        ->and($system)->toContain('Diagrama de sequência')
        ->and($system)->toContain('aqui não existe `search_solutions`');
});

it('lists the whole catalog for bindings, or none of it past the ceiling', function () {
    Solution::factory()->create(['slug' => 'sap-s4', 'name' => 'SAP S/4', 'category' => 'erp']);
    Solution::factory()->create(['slug' => 'vtex', 'name' => 'VTEX', 'category' => 'erp']);
    $builder = app(UmlDraftPromptBuilder::class);

    expect($builder->userPrompt('component', 'P', 'texto', null, null, $builder->catalog()))
        ->toContain('CATÁLOGO DE SOLUÇÕES')
        ->toContain('sap-s4 — SAP S/4')
        ->toContain('vtex — VTEX');

    config(['services.documentation_ai.uml_max_catalog' => 1]);
    expect($builder->catalog())->toBeNull()
        ->and($builder->userPrompt('component', 'P', 'texto', null, null, $builder->catalog()))->not->toContain('CATÁLOGO');
});

it('masks a protected value and strips media before the model reads the page', function () {
    $builder = app(UmlDraftPromptBuilder::class);
    $content = "Header: {% secret id=\"abcd1234\" %}token-de-verdade{% endsecret %}\n\n<figure><img src=\"/files/9\" alt=\"x\"></figure>";

    expect($builder->userPrompt('sequence', 'P', $content, null, null, null))
        ->not->toContain('token-de-verdade')
        ->not->toContain('/files/9');
});
