<?php

use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\User;
use App\Services\Documentation\SceneDraftPromptBuilder;
use App\Services\Documentation\SceneDraftService;
use App\Support\Documentation\BlockVault;
use App\Support\Documentation\StepScene;
use App\Support\GitbookRenderer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

uses(LazilyRefreshDatabase::class);

/**
 * The "fluxo em etapas" — the first animated scene of a documentation page:
 * the IR the model and the page agree on, the dialect the renderer reads, and
 * the endpoint the editor's block asks for a proposal.
 */
function fakeSceneService(string ...$replies): SceneDraftService
{
    return new class($replies) extends SceneDraftService
    {
        /** @var list<string> */
        public array $capturedPrompts = [];

        /** @param list<string> $replies */
        public function __construct(private array $replies)
        {
            parent::__construct(app(SceneDraftPromptBuilder::class));
        }

        protected function prompt(string $prompt): AgentResponse
        {
            $this->capturedPrompts[] = $prompt;

            return new AgentResponse('fake', array_shift($this->replies) ?? '', new Usage(10, 20), new Meta('gemini', 'gemini-3.8-flash'));
        }
    };
}

function sceneJson(array $payload): string
{
    return "```json\n" . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n```";
}

function validScene(): array
{
    return [
        'caption' => 'Da solicitação ao crédito',
        'steps'   => [
            ['title' => 'Cliente solicita', 'detail' => 'Pelo site.'],
            ['title' => 'Loja confere', 'detail' => '', 'highlight' => true],
            ['title' => 'Crédito no SAP', 'detail' => 'Estorno ou vale-troca.'],
        ],
    ];
}

function scenePage(string $documentation = 'O cliente pede a devolução, a loja confere e o SAP credita.'): DocumentationPage
{
    return DocumentationPage::factory()
        ->for(Notebook::factory()->create())
        ->create(['documentation' => $documentation]);
}

// ---------------------------------------------------------------------------
// The IR
// ---------------------------------------------------------------------------

it('accepts the shape the prompt asks for', function () {
    expect(StepScene::validate(validScene()))->toBe([]);

    $scene = StepScene::fromArray(validScene())->toArray();

    expect($scene['type'])->toBe('steps')
        ->and($scene['steps'][1]['highlight'])->toBeTrue()
        ->and($scene['steps'][0]['highlight'])->toBeFalse();
});

it('names what is wrong, step by step', function () {
    $problems = StepScene::validate([
        'steps' => [
            ['title' => str_repeat('x', StepScene::MAX_TITLE + 1), 'highlight' => true],
            ['title' => '', 'highlight' => true],
        ],
    ]);

    expect(implode(' ', $problems))
        ->toContain('etapa 1')
        ->toContain('etapa 2')
        ->toContain('No máximo UMA etapa');

    expect(StepScene::validate(['steps' => [['title' => 'Só uma']]]))
        ->toContain('A lista "steps" deve ter entre 2 e 8 etapas (tem 1).');
});

// ---------------------------------------------------------------------------
// The dialect
// ---------------------------------------------------------------------------

it('renders the steps as a list carrying the scene for the animation', function () {
    $html = app(GitbookRenderer::class)->render(<<<'MD'
        Antes.

        {% scene type="steps" caption="Do pedido &quot;A&quot; ao SAP" %}
        {% step title="Pedido entra" %}
        Pela VTEX.
        {% endstep %}
        {% step title="<b>Faturamento</b>" highlight="true" %}
        {% endstep %}
        {% endscene %}

        Depois.
        MD);

    expect($html)
        ->toContain('<figure class="ak-scene" data-ak-scene="')
        ->toContain('<li class="ak-scene__step"><span class="ak-scene__title">Pedido entra</span> <span class="ak-scene__detail">Pela VTEX.</span></li>')
        ->toContain('<li class="ak-scene__step is-highlight">')
        // Plain text on both ends: a tag in a title is escaped, never markup.
        ->toContain('&lt;b&gt;Faturamento&lt;/b&gt;')
        ->not->toContain('<b>Faturamento</b>')
        ->toContain('<figcaption>Do pedido &quot;A&quot; ao SAP</figcaption>')
        ->toContain('<p>Depois.</p>');

    preg_match('/data-ak-scene="([^"]*)"/', $html, $m);
    $scene = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);

    expect($scene['type'])->toBe('steps')
        ->and($scene['caption'])->toBe('Do pedido "A" ao SAP')
        ->and($scene['steps'])->toHaveCount(2)
        ->and($scene['steps'][1])->toBe(['title' => '<b>Faturamento</b>', 'detail' => '', 'highlight' => true]);
});

it('keeps the words of a scene type it does not know, without the animation', function () {
    $html = app(GitbookRenderer::class)->render("{% scene type=\"orbit\" %}\n{% step title=\"Um\" %}\n{% endstep %}\n{% endscene %}");

    expect($html)->toContain('<figure class="ak-scene">')
        ->toContain('Um')
        ->not->toContain('data-ak-scene=');
});

it('freezes a scene for the assistant instead of letting it rewrite one', function () {
    $markdown = "Texto.\n\n{% scene type=\"steps\" %}\n{% step title=\"Um\" %}\n{% endstep %}\n{% endscene %}\n\nMais texto.";

    expect(BlockVault::strip($markdown))->not->toContain('{% scene')
        ->toContain('Mais texto.');
});

// ---------------------------------------------------------------------------
// The endpoint
// ---------------------------------------------------------------------------

it('proposes the steps of a page and writes nothing', function () {
    $page = scenePage();
    $service = fakeSceneService(sceneJson(validScene()));
    app()->instance(SceneDraftService::class, $service);

    $this->actingAs(User::factory()->editor()->create())
        ->postJson(route('notebooks.pages.scene', [$page->notebook, $page]), [
            'type'    => 'steps',
            'focus'   => 'a devolução',
            'content' => 'O texto que está no editor agora.',
        ])
        ->assertOk()
        ->assertJsonPath('scene.type', 'steps')
        ->assertJsonPath('scene.steps.1.highlight', true)
        ->assertJsonCount(3, 'scene.steps');

    // The editor's CURRENT text is what the model reads, and the author's focus
    // rides along.
    expect($service->capturedPrompts[0])
        ->toContain('O texto que está no editor agora.')
        ->toContain('a devolução')
        ->not->toContain('a loja confere e o SAP credita');

    expect($page->fresh()->documentation)->toBe('O cliente pede a devolução, a loja confere e o SAP credita.');
});

it('repairs a payload once', function () {
    $page = scenePage();
    $service = fakeSceneService(sceneJson(['steps' => [['title' => 'Só uma']]]), sceneJson(validScene()));
    app()->instance(SceneDraftService::class, $service);

    $this->actingAs(User::factory()->editor()->create())
        ->postJson(route('notebooks.pages.scene', [$page->notebook, $page]), ['type' => 'steps'])
        ->assertOk()
        ->assertJsonCount(3, 'scene.steps');

    // The repair round is handed the problem, not the page again.
    expect($service->capturedPrompts[1])
        ->toContain('entre 2 e 8 etapas')
        ->not->toContain('a loja confere');
});

it('gives up after one repair round, with the reasons', function () {
    $page = scenePage();
    $broken = sceneJson(['steps' => [['title' => 'Só uma']]]);
    app()->instance(SceneDraftService::class, fakeSceneService($broken, $broken));

    $this->actingAs(User::factory()->editor()->create())
        ->postJson(route('notebooks.pages.scene', [$page->notebook, $page]), ['type' => 'steps'])
        ->assertStatus(422)
        ->assertJsonPath('type', 'warning')
        ->assertJsonPath('message', 'O fluxo proposto não passou na validação: A lista "steps" deve ter entre 2 e 8 etapas (tem 1).');
});

it('passes on the model saying the page describes no sequence', function () {
    $page = scenePage();
    app()->instance(SceneDraftService::class, fakeSceneService(sceneJson(['error' => 'A página é um glossário de campos.'])));

    $this->actingAs(User::factory()->editor()->create())
        ->postJson(route('notebooks.pages.scene', [$page->notebook, $page]), ['type' => 'steps'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Não dá para montar um fluxo em etapas a partir desta página: A página é um glossário de campos.');
});

it('refuses an empty page without calling the model', function () {
    $page = scenePage('');
    $service = fakeSceneService();
    app()->instance(SceneDraftService::class, $service);

    $this->actingAs(User::factory()->editor()->create())
        ->postJson(route('notebooks.pages.scene', [$page->notebook, $page]), ['type' => 'steps'])
        ->assertStatus(422);

    expect($service->capturedPrompts)->toBe([]);
});

it('is for whoever may edit the page', function () {
    $page = scenePage();
    app()->instance(SceneDraftService::class, fakeSceneService(sceneJson(validScene())));

    $this->actingAs(User::factory()->create())
        ->postJson(route('notebooks.pages.scene', [$page->notebook, $page]), ['type' => 'steps'])
        ->assertForbidden();

    // The app's validation shape is {message, title, type} — no `errors` key.
    $this->actingAs(User::factory()->editor()->create())
        ->postJson(route('notebooks.pages.scene', [$page->notebook, $page]), ['type' => 'orbit'])
        ->assertUnprocessable()
        ->assertJsonPath('type', 'warning');
});
