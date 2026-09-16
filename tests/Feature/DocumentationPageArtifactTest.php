<?php

use App\Enums\ArtifactDiagramType;
use App\Enums\UserRole;
use App\Exceptions\PageArtifactFailed;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Models\User;
use App\Services\Documentation\PageArtifactPromptBuilder;
use App\Services\Documentation\PageArtifactService;
use App\Support\Archify\ArchifyRunner;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

uses(LazilyRefreshDatabase::class);

// Spatie writes each file under `storage/app/public/{media id}`, and
// LazilyRefreshDatabase rolls the ids back between tests — so without a fake
// disk one test's leftover directory is the next test's id, and which test
// fails depends on the order they ran in.
beforeEach(function () {
    Storage::fake('public');

    // NOT patience for a slow renderer — the CLI answers in ~0.4s, measured.
    // This WSL2 box's clock steps (see `.claude/rules/testing-time.md`): a loop
    // of 15 identical invocations had one report 81s and the next report a
    // NEGATIVE duration, i.e. the clock jumped forward and back. Symfony's
    // process timeout is wall-clock, so a step mid-render reads as a hung
    // sidecar and fails whichever test happened to be running.
    config(['services.archify.timeout' => 300]);
});

/**
 * The MODEL is faked; Archify is not. The sidecar is the half most likely to
 * break on a spec that looks fine — its schemas and its geometry checks are the
 * contract this feature actually has to satisfy — so these run the real CLI.
 */
function fakeArtifactService(string ...$replies): PageArtifactService
{
    return new class($replies) extends PageArtifactService
    {
        /** @var list<string> */
        public array $capturedPrompts = [];

        /** @param  list<string>  $replies */
        public function __construct(private array $replies)
        {
            parent::__construct(app(PageArtifactPromptBuilder::class), app(ArchifyRunner::class));
        }

        protected function prompt(ArtifactDiagramType $type, string $prompt): AgentResponse
        {
            $this->capturedPrompts[] = $prompt;

            return new AgentResponse('fake', array_shift($this->replies) ?? '', new Usage(10, 20), new Meta('gemini', 'gemini-3.6-flash'));
        }
    };
}

function artifactJson(array $payload): string
{
    return "Claro.\n\n```json\n" . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n```";
}

function sequenceSpec(array $overrides = []): array
{
    return array_merge([
        'diagram_type' => 'sequence',
        'meta'         => ['title' => 'Consulta de pedido'],
        'participants' => [
            ['id' => 'loja', 'type' => 'frontend', 'label' => 'Loja'],
            ['id' => 'api', 'type' => 'backend', 'label' => 'API'],
        ],
        'messages' => [
            ['from' => 'loja', 'to' => 'api', 'label' => 'GET /pedidos'],
            ['from' => 'api', 'to' => 'loja', 'label' => '200 OK', 'variant' => 'return'],
        ],
    ], $overrides);
}

function artifactPage(string $documentation = 'A loja consulta a API de pedidos, que responde com o pedido.'): DocumentationPage
{
    return DocumentationPage::factory()
        ->for(Notebook::factory()->create())
        ->create(['documentation' => $documentation]);
}

// ---------------------------------------------------------------------------
// The sidecar.
// ---------------------------------------------------------------------------

it('has a working renderer on this machine', function () {
    // Guards every other test in this file: a red here means Node or the
    // vendored copy, not the feature.
    expect(app(ArchifyRunner::class)->available())->toBeTrue();
});

it('reports the validator problems instead of swallowing them', function () {
    $path = tempnam(sys_get_temp_dir(), 'spec-');
    // A hand-written spec, so it carries the two fields the service fills in.
    file_put_contents($path, json_encode(sequenceSpec([
        'schema_version' => 1,
        'messages'       => [['from' => 'loja', 'to' => 'fantasma', 'y' => 200, 'label' => 'x']],
    ])));

    $result = app(ArchifyRunner::class)->validate('sequence', $path);

    expect($result->ok)->toBeFalse()
        ->and(implode(' ', $result->problems))->toContain('fantasma');

    @unlink($path);
});

// ---------------------------------------------------------------------------
// Spec → artifact.
// ---------------------------------------------------------------------------

it('renders a real artifact from what the model proposed', function () {
    $service = fakeArtifactService(artifactJson(sequenceSpec()));

    ['path' => $path, 'title' => $title] = $service->render(artifactPage(), ArtifactDiagramType::Sequence);

    expect($title)->toBe('Consulta de pedido')
        ->and(is_file($path))->toBeTrue();

    $html = file_get_contents($path);

    // Self-contained and real: an inline SVG, our labels in it, and no request
    // to anywhere.
    expect($html)->toContain('<svg')
        ->toContain('GET /pedidos')
        ->and($service->capturedPrompts)->toHaveCount(1);

    @unlink($path);
});

it('assigns the vertical position of a sequence itself', function () {
    // The model is told not to write `y`, and the schema demands one. It is
    // arithmetic over an order the model already expressed by listing the
    // messages — asking for it would be asking for the one thing it is worst
    // at, and asking again on every repair round.
    $service = fakeArtifactService(artifactJson(sequenceSpec()));

    ['path' => $path] = $service->render(artifactPage(), ArtifactDiagramType::Sequence);

    expect(is_file($path))->toBeTrue();

    @unlink($path);
});

it('grows the canvas instead of running a long exchange off the bottom', function () {
    // Reported from production: the renderer's default 760-high canvas fits
    // exactly NINE messages, and the tenth came back "sits outside the readable
    // timeline — keep y between 160 and 677". A sequence diagram is supposed to
    // get taller as the exchange gets longer, so the spacing stays readable and
    // the canvas follows.
    $messages = [];

    // Every variant, so the legend is at its tallest — it grows upward from the
    // canvas floor and is what the last message actually has to clear.
    $variants = ['default', 'return', 'security', 'dashed', 'emphasis'];

    for ($i = 1; $i <= 14; $i++) {
        $messages[] = [
            'from'    => $i % 2 === 0 ? 'api' : 'loja',
            'to'      => $i % 2 === 0 ? 'loja' : 'api',
            'label'   => 'passo ' . $i,
            'variant' => $variants[$i % 5],
        ];
    }

    $service = fakeArtifactService(artifactJson(sequenceSpec(['messages' => $messages])));

    ['path' => $path] = $service->render(artifactPage(), ArtifactDiagramType::Sequence);

    expect(is_file($path))->toBeTrue()
        ->and(file_get_contents($path))->toContain('passo 14')
        // One model call: the spec was valid the first time, not repaired into
        // shape afterwards.
        ->and($service->capturedPrompts)->toHaveCount(1);

    @unlink($path);
});

it('widens the canvas for a wide cast, for the same reason', function () {
    // The lanes sit at a fixed pitch, so a dozen participants run off the side
    // exactly as a dozen messages ran off the bottom — and the prompt allows up
    // to twelve blocks.
    $participants = [];
    $messages = [];

    for ($i = 1; $i <= 11; $i++) {
        $participants[] = ['id' => 'p' . $i, 'type' => 'backend', 'label' => 'Sistema ' . $i];

        if ($i > 1) {
            $messages[] = ['from' => 'p' . ($i - 1), 'to' => 'p' . $i, 'label' => 'passo ' . $i];
        }
    }

    $service = fakeArtifactService(artifactJson(sequenceSpec([
        'participants' => $participants,
        'messages'     => $messages,
    ])));

    ['path' => $path] = $service->render(artifactPage(), ArtifactDiagramType::Sequence);

    expect(is_file($path))->toBeTrue()
        ->and(file_get_contents($path))->toContain('Sistema 11');

    @unlink($path);
});

it('widens a data flow to its widest legal shape', function () {
    // The schema allows five stages and the renderer's default canvas fits
    // four — so the widest LEGAL dataflow did not fit the default canvas. Same
    // bug as the sequence one, on the other axis.
    $nodes = [];
    $flows = [];

    for ($i = 0; $i < 5; $i++) {
        $nodes[] = ['id' => 'n' . $i, 'type' => 'backend', 'label' => 'Etapa ' . $i, 'stage' => $i, 'row' => 0];

        if ($i > 0) {
            $flows[] = ['from' => 'n' . ($i - 1), 'to' => 'n' . $i, 'label' => 'passo'];
        }
    }

    $service = fakeArtifactService(artifactJson([
        'meta'   => ['title' => 'Carga completa'],
        'stages' => [['label' => 'A'], ['label' => 'B'], ['label' => 'C'], ['label' => 'D'], ['label' => 'E']],
        'nodes'  => $nodes,
        'flows'  => $flows,
    ]));

    ['path' => $path] = $service->render(artifactPage(), ArtifactDiagramType::Dataflow);

    expect(is_file($path))->toBeTrue()
        ->and($service->capturedPrompts)->toHaveCount(1);

    @unlink($path);
});

it('repairs once against the renderer own diagnostics, then gives up', function () {
    $broken = artifactJson(sequenceSpec(['messages' => [['from' => 'loja', 'to' => 'fantasma', 'label' => 'x']]]));

    $service = fakeArtifactService($broken, artifactJson(sequenceSpec()));
    ['path' => $path] = $service->render(artifactPage(), ArtifactDiagramType::Sequence);

    expect(is_file($path))->toBeTrue()
        ->and($service->capturedPrompts)->toHaveCount(2)
        // The repair round gets the diagnostics and the spec — never the page
        // again, which would invite a redraw instead of a fix.
        ->and($service->capturedPrompts[1])->toContain('fantasma')
        ->and($service->capturedPrompts[1])->not->toContain('A loja consulta');

    @unlink($path);

    $twiceBroken = fakeArtifactService($broken, $broken);
    expect(fn () => $twiceBroken->render(artifactPage(), ArtifactDiagramType::Sequence))
        ->toThrow(PageArtifactFailed::class);
    expect($twiceBroken->capturedPrompts)->toHaveCount(2);
});

it('takes "this page has no such diagram" for an answer', function () {
    // The prompt offers this exit on purpose: it is worth more than four boxes
    // invented to satisfy the request.
    $service = fakeArtifactService('```json' . "\n" . '{"error": "a página não descreve nenhuma sequência de chamadas"}' . "\n" . '```');

    expect(fn () => $service->render(artifactPage(), ArtifactDiagramType::Sequence))
        ->toThrow(PageArtifactFailed::class, 'não descreve nenhuma sequência');
});

it('refuses a page with nothing on it before spending a model call', function () {
    $page = DocumentationPage::factory()->for(Notebook::factory()->create())->create(['documentation' => null]);
    $service = fakeArtifactService(artifactJson(sequenceSpec()));

    expect(fn () => $service->render($page, ArtifactDiagramType::Sequence))->toThrow(PageArtifactFailed::class);
    expect($service->capturedPrompts)->toBeEmpty();
});

it('renders the other three types from the same contract', function (ArtifactDiagramType $type, array $spec) {
    $service = fakeArtifactService(artifactJson($spec));

    ['path' => $path] = $service->render(artifactPage(), $type);

    expect(is_file($path))->toBeTrue()
        ->and(file_get_contents($path))->toContain('<svg');

    @unlink($path);
})->with([
    'dataflow' => [ArtifactDiagramType::Dataflow, [
        'meta'   => ['title' => 'Carga diária'],
        'stages' => [['label' => 'Origem'], ['label' => 'Transformação'], ['label' => 'Consumo']],
        'nodes'  => [
            ['id' => 'erp', 'type' => 'external', 'label' => 'SAP', 'stage' => 0, 'row' => 0],
            ['id' => 'job', 'type' => 'backend', 'label' => 'DAG', 'stage' => 1, 'row' => 0],
            ['id' => 'bq', 'type' => 'database', 'label' => 'BigQuery', 'stage' => 2, 'row' => 0],
        ],
        'flows' => [
            ['from' => 'erp', 'to' => 'job', 'label' => 'extração'],
            ['from' => 'job', 'to' => 'bq', 'label' => 'tabela fato'],
        ],
    ]],
    'lifecycle' => [ArtifactDiagramType::Lifecycle, [
        'meta' => ['title' => 'Execução'],
        // `main` is REQUIRED and `terminal` reserved — lane ids are semantic
        // here, not free text, which is what the first run of this test found.
        'lanes'  => [['id' => 'main', 'label' => 'Execução'], ['id' => 'terminal', 'label' => 'Desfecho']],
        'states' => [
            ['id' => 'novo', 'type' => 'start', 'label' => 'Recebido', 'lane' => 'main', 'col' => 0],
            ['id' => 'proc', 'type' => 'active', 'label' => 'Processando', 'lane' => 'main', 'col' => 1],
            ['id' => 'ok', 'type' => 'success', 'label' => 'Concluído', 'lane' => 'terminal', 'col' => 2],
        ],
        // Unlabelled: between two ADJACENT states there is no room for a label,
        // and Archify's geometry check says so with a suggested labelDy. The
        // repair round is what fixes that in real use — this fixture is about
        // the type rendering at all, so it does not lean on a second call.
        'transitions' => [
            ['from' => 'novo', 'to' => 'proc'],
            ['from' => 'proc', 'to' => 'ok'],
        ],
    ]],
    'workflow' => [ArtifactDiagramType::Workflow, [
        'meta'  => ['title' => 'Abertura de chamado'],
        'lanes' => [['id' => 'sol', 'label' => 'Solicitante'], ['id' => 'ti', 'label' => 'TI']],
        'nodes' => [
            ['id' => 'abre', 'lane' => 'sol', 'col' => 0, 'type' => 'frontend', 'label' => 'Abre'],
            ['id' => 'tria', 'lane' => 'ti', 'col' => 1, 'type' => 'backend', 'label' => 'Triagem'],
            ['id' => 'fim', 'lane' => 'ti', 'col' => 2, 'type' => 'backend', 'label' => 'Resolvido'],
        ],
        'edges' => [
            ['from' => 'abre', 'to' => 'tria', 'label' => 'novo'],
            ['from' => 'tria', 'to' => 'fim', 'label' => 'aceito', 'role' => 'main'],
        ],
        'mainPath' => ['abre', 'tria', 'fim'],
    ]],
]);

// ---------------------------------------------------------------------------
// The endpoints.
// ---------------------------------------------------------------------------

it('stores the artifact on the page and lists it in the rail', function () {
    $page = artifactPage();
    app()->instance(PageArtifactService::class, fakeArtifactService(artifactJson(sequenceSpec())));

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->postJson(route('notebooks.pages.artifacts.store', [$page->notebook, $page, 'sequence']))
        ->assertOk()
        ->assertJsonPath('updatableSlots.0.id', 'page-artifacts-slot');

    $media = $page->fresh()->getMedia(DocumentationPage::ARTIFACTS_COLLECTION);

    expect($media)->toHaveCount(1)
        ->and($media->first()->getCustomProperty('type'))->toBe('sequence')
        ->and($media->first()->getCustomProperty('title'))->toBe('Consulta de pedido')
        // NOT the `docs` collection: that one is served by `/files/{id}` on the
        // collection name alone, and this is a full HTML document with scripts.
        ->and($page->fresh()->getMedia(DocumentationPage::DOCS_COLLECTION))->toBeEmpty();
});

it('serves the artifact sandboxed, so its scripts get no origin of ours', function () {
    $page = artifactPage();
    app()->instance(PageArtifactService::class, fakeArtifactService(artifactJson(sequenceSpec())));
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);

    $this->actingAs($admin)->postJson(route('notebooks.pages.artifacts.store', [$page->notebook, $page, 'sequence']));
    $media = $page->fresh()->getFirstMedia(DocumentationPage::ARTIFACTS_COLLECTION);

    $response = $this->actingAs($admin)->get(route('notebooks.pages.artifacts.show', [$page->notebook, $page, $media]));

    $response->assertOk();
    expect($response->headers->get('Content-Security-Policy'))
        ->toContain('sandbox allow-scripts')
        ->toContain("default-src 'none'")
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('refuses to serve media of another page, or of the page other collection', function () {
    $page = artifactPage();
    $other = artifactPage();
    app()->instance(PageArtifactService::class, fakeArtifactService(artifactJson(sequenceSpec())));
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);

    $this->actingAs($admin)->postJson(route('notebooks.pages.artifacts.store', [$other->notebook, $other, 'sequence']));
    $foreign = $other->fresh()->getFirstMedia(DocumentationPage::ARTIFACTS_COLLECTION);

    // An id in a URL is scoped by nothing; the owner check is what scopes it.
    $this->actingAs($admin)
        ->get(route('notebooks.pages.artifacts.show', [$page->notebook, $page, $foreign]))
        ->assertNotFound();

    // And a `docs` image of THIS page must not come back through a route that
    // hands out sandboxing headers for artifacts.
    $image = $page->addMedia(UploadedFile::fake()->image('x.png'))->toMediaCollection(DocumentationPage::DOCS_COLLECTION);

    $this->actingAs($admin)
        ->get(route('notebooks.pages.artifacts.show', [$page->notebook, $page, $image]))
        ->assertNotFound();
});

it('removes an artifact when asked', function () {
    $page = artifactPage();
    app()->instance(PageArtifactService::class, fakeArtifactService(artifactJson(sequenceSpec())));
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);

    $this->actingAs($admin)->postJson(route('notebooks.pages.artifacts.store', [$page->notebook, $page, 'sequence']));
    $media = $page->fresh()->getFirstMedia(DocumentationPage::ARTIFACTS_COLLECTION);

    $this->actingAs($admin)
        ->deleteJson(route('notebooks.pages.artifacts.destroy', [$page->notebook, $page, $media]))
        ->assertOk();

    expect($page->fresh()->getMedia(DocumentationPage::ARTIFACTS_COLLECTION))->toBeEmpty();
});

it('refuses a type that is not one of the four', function () {
    $page = artifactPage();

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin->value]))
        ->postJson(route('notebooks.pages.artifacts.store', [$page->notebook, $page, 'architecture']))
        ->assertNotFound();
});

it('offers the four types to a writer and the menu to nobody who can use neither half', function () {
    $page = artifactPage();

    $this->actingAs(User::factory()->create(['role' => UserRole::Writer->value]))
        ->get(route('notebooks.pages.edit', [$page->notebook, $page]))
        ->assertOk()
        ->assertSee('Fluxo de dados')
        ->assertSee('Ciclo de vida');

    // A viewer may open neither half, so the trigger itself is absent — a
    // button opening an empty popover is an affordance for nothing.
    $this->actingAs(User::factory()->create(['role' => UserRole::Viewer->value]))
        ->get(route('notebooks.pages.edit', [$page->notebook, $page]))
        ->assertOk()
        ->assertDontSee('Desenhar esta página')
        ->assertDontSee('Ciclo de vida');
});

it('refuses a viewer', function () {
    $page = artifactPage();
    app()->instance(PageArtifactService::class, fakeArtifactService(artifactJson(sequenceSpec())));

    $this->actingAs(User::factory()->create(['role' => UserRole::Viewer->value]))
        ->postJson(route('notebooks.pages.artifacts.store', [$page->notebook, $page, 'sequence']))
        ->assertForbidden();

    expect($page->fresh()->getMedia(DocumentationPage::ARTIFACTS_COLLECTION))->toBeEmpty();
});
