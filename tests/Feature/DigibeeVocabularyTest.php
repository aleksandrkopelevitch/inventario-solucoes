<?php

use App\Actions\Digibee\IndexPipelineVocabulary;
use App\Enums\FlowspecAttachmentKind;
use App\Models\FlowspecChat;
use App\Models\FlowspecExample;
use App\Services\Flowspec\FlowspecContext;
use App\Services\Flowspec\FlowspecContextResolver;
use App\Services\Flowspec\FlowspecPromptBuilder;
use App\Support\Digibee\ParamRedactor;
use App\Support\Digibee\TenantVocabulary;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| What survives redaction
|--------------------------------------------------------------------------
*/

it('keeps names and expressions, and replaces anything that addresses a machine', function () {
    $redacted = (new ParamRedactor)->value([
        'url'               => 'https://interno.leomadeiras.com/api/v1/pedidos',
        'host'              => '10.158.1.37',
        'operation'         => 'POST',
        'stopOnClientError' => false,
        'connectTimeout'    => 30000,
        'body'              => '{{ message.$ }}',
        // A global's NAME is vocabulary — that is the whole point of the
        // artifact, and an invented one validates clean and dies at runtime.
        'headers'         => '{"x-api-key":"{{ global.promob-x-api-key }}"}',
        'objectStoreName' => 'token-cache',
        'documentation'   => 'Um parágrafo inteiro de explicação que não ensina forma nenhuma e só ocupa espaço.',
    ]);

    expect($redacted['url'])->toBe('<endpoint>')
        ->and($redacted['host'])->toBe('<endpoint>')
        ->and($redacted['operation'])->toBe('POST')
        ->and($redacted['stopOnClientError'])->toBeFalse()
        ->and($redacted['connectTimeout'])->toBe(30000)
        ->and($redacted['body'])->toBe('{{ message.$ }}')
        ->and($redacted['headers'])->toContain('global.promob-x-api-key')
        ->and($redacted['objectStoreName'])->toBe('token-cache')
        ->and($redacted['documentation'])->toBe('<string>');
});

/*
|--------------------------------------------------------------------------
| The index
|--------------------------------------------------------------------------
*/

function writePipeline(string $name, array $document): void
{
    Storage::disk('local')->put(
        config('services.digibee.pipelines_dir') . "/{$name}.json",
        (string) json_encode($document),
    );
}

function restStep(array $params, array $extra = []): array
{
    return [
        'meta'     => [],
        'flowSpec' => [
            'disconnected-root:0e1a' => [
                ['id' => 'a', 'type' => 'connector', 'name' => 'rest-connector-v2', 'params' => $params] + $extra,
            ],
        ],
    ];
}

beforeEach(function () {
    config()->set('services.digibee.vocabulary_path', sys_get_temp_dir() . '/digibee_vocabulary_test.json');
    TenantVocabulary::flush();
});

afterEach(fn () => @unlink(sys_get_temp_dir() . '/digibee_vocabulary_test.json'));

it('learns the real param keys, globals and accounts from the export', function () {
    Storage::fake('local');
    writePipeline('pedido', restStep(
        ['url'          => '{{ global.url-base-svl }}/pedidos', 'operation' => 'POST', 'stopOnClientError' => true],
        ['accountLabel' => 'svl-prod'],
    ));

    $report = app(IndexPipelineVocabulary::class)->handle();
    TenantVocabulary::flush();
    $vocabulary = new TenantVocabulary;

    expect($report['pipelines'])->toBe(1)
        ->and($vocabulary->globals())->toBe(['url-base-svl'])
        ->and($vocabulary->accounts())->toBe(['svl-prod'])
        ->and($vocabulary->forConnector('rest-connector-v2')['params'])
        // The documented parameter is called "Verb"; the JSON key is
        // `operation`, and no published page prints it.
        ->toHaveKeys(['url', 'operation', 'stopOnClientError']);
});

it('skips a pipeline that carries a literal credential instead of redacting around it', function () {
    Storage::fake('local');
    writePipeline('bom', restStep(['url' => '{{ global.a }}/x', 'operation' => 'GET']));
    writePipeline('vazado', restStep([
        'url'     => '{{ global.a }}/x',
        'headers' => '{"Authorization":"Bearer sk-live-AbCdEf0123456789AbCdEf0123456789"}',
    ]));

    $report = app(IndexPipelineVocabulary::class)->handle();

    expect($report['pipelines'])->toBe(1)
        ->and($report['skipped'])->toHaveCount(1)
        ->and($report['skipped'][0])->toContain('vazado');
});

it('says how our own pipelines spell a connector, and never invents one it has not seen', function () {
    Storage::fake('local');
    writePipeline('pedido', restStep(['url' => '{{ global.a }}/x', 'operation' => 'POST']));
    app(IndexPipelineVocabulary::class)->handle();
    TenantVocabulary::flush();

    $vocabulary = new TenantVocabulary;

    expect($vocabulary->toPrompt(['rest-connector-v2']))->toContain('`operation`')
        // A connector with no real usage has nothing to teach here, and
        // inventing a shape is exactly what this artifact exists to prevent.
        ->and($vocabulary->toPrompt(['sap-connector']))->toBe('')
        ->and($vocabulary->referenceSection())->toContain('global: `a`');
});

/*
|--------------------------------------------------------------------------
| What reaches the prompt
|--------------------------------------------------------------------------
*/

function flowspecContext(array $connectors): FlowspecContext
{
    return new FlowspecContext(
        pages: collect(), textDocs: collect(), referenceFlowspecs: collect(),
        attachments: [], attachedMeta: [], omittedAttachments: [],
        examples: collect(), tags: [], connectors: $connectors,
    );
}

it('carries the reference of the connectors in play, and nothing about the others', function () {
    $prompt = app(FlowspecPromptBuilder::class)
        ->userPrompt(flowspecContext(['object-store-connector']), 'guarda o token', collect())
        ->text;

    expect($prompt)->toContain('REFERÊNCIA DOS CONECTORES ENVOLVIDOS')
        ->and($prompt)->toContain('object-store-connector')
        ->and($prompt)->not->toContain('sap-connector');
})->skip(fn () => ! is_file(config('services.digibee.cards_path')), 'connector cards not built');

it('carries no reference section at all when nothing names a connector', function () {
    $prompt = app(FlowspecPromptBuilder::class)
        ->userPrompt(flowspecContext([]), 'oi', collect())
        ->text;

    expect($prompt)->not->toContain('REFERÊNCIA DOS CONECTORES ENVOLVIDOS');
});

it('derives the connectors in play from a pipeline the user pasted', function () {
    Storage::fake('local');
    writePipeline('pedido', restStep(['url' => '{{ global.a }}/x', 'operation' => 'POST']));
    app(IndexPipelineVocabulary::class)->handle();
    TenantVocabulary::flush();

    $chat = FlowspecChat::factory()->create();
    $chat->attachments()->create([
        'kind'                  => FlowspecAttachmentKind::Text,
        'label'                 => 'Pedidos B2B',
        'content'               => (string) json_encode(restStep(['url' => '{{ global.a }}/x'])),
        'is_flowspec_reference' => true,
        'token_estimate'        => 10,
    ]);

    $context = app(FlowspecContextResolver::class)->resolve($chat, 'estenda esse fluxo');

    expect($context->connectors)->toContain('rest-connector-v2');
});

it('recognises a connector named the way a person names it', function () {
    $chat = FlowspecChat::factory()->create();

    // Nobody types `object-store-connector`. The card's title is the label
    // Digibee prints on the canvas, and it is what a request actually says.
    $context = app(FlowspecContextResolver::class)
        ->resolve($chat, 'guarda o token no Object Store e depois chama a API');

    expect($context->connectors)->toContain('object-store-connector');
})->skip(fn () => ! is_file(config('services.digibee.cards_path')), 'connector cards not built');

it('puts the connectors a request is about ahead of the plumbing every pipeline has', function () {
    Storage::fake('local');
    // `log-connector` in 40 pipelines, `sftp-connector` in 2: how often we use
    // a connector is how little it distinguishes one request from another.
    foreach (range(1, 40) as $i) {
        writePipeline("comum-{$i}", ['meta' => [], 'flowSpec' => ['disconnected-root:1' => [
            ['id' => 'a', 'type' => 'connector', 'name' => 'log-connector', 'params' => ['message' => 'x']],
        ]]]);
    }
    foreach (range(1, 2) as $i) {
        writePipeline("raro-{$i}", ['meta' => [], 'flowSpec' => ['disconnected-root:1' => [
            ['id' => 'b', 'type' => 'connector', 'name' => 'sftp-connector', 'params' => ['operation' => 'DOWNLOAD']],
        ]]]);
    }
    app(IndexPipelineVocabulary::class)->handle();
    TenantVocabulary::flush();

    FlowspecExample::factory()->create([
        'slug'      => 'com-plumbing', 'tags' => ['sftp'],
        'flow_spec' => ['meta' => [], 'flowSpec' => ['disconnected-root:1' => [
            ['id' => 'a', 'type' => 'connector', 'name' => 'log-connector', 'params' => []],
            ['id' => 'b', 'type' => 'connector', 'name' => 'sftp-connector', 'params' => []],
        ]]],
    ]);

    $context = app(FlowspecContextResolver::class)
        ->resolve(FlowspecChat::factory()->create(), 'baixa um arquivo por ftp');

    expect(array_search('sftp-connector', $context->connectors, true))
        ->toBeLessThan(array_search('log-connector', $context->connectors, true));
});

it('ranks a corpus example by the connectors it uses, not only by its tags', function () {
    $rest = FlowspecExample::factory()->create([
        'slug'      => 'rest-example', 'tags' => ['rest'],
        'flow_spec' => restStep(['url' => 'https://x/y']),
    ]);
    FlowspecExample::factory()->create([
        'slug'      => 'sap-example', 'tags' => ['rest'],
        'flow_spec' => ['meta' => [], 'flowSpec' => ['disconnected-root:1' => [
            ['id' => 'b', 'type' => 'connector', 'name' => 'sap-connector', 'params' => []],
        ]]],
    ]);

    config()->set('services.flowspec.max_examples', 1);

    $chat = FlowspecChat::factory()->create();
    $context = app(FlowspecContextResolver::class)
        ->resolve($chat, 'monta um fluxo usando rest-connector-v2 para consultar a API');

    expect($context->examples->pluck('slug')->all())->toBe([$rest->slug]);
});
