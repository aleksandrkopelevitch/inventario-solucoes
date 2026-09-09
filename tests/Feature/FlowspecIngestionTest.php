<?php

use App\Actions\Digibee\IngestFlowspec;
use App\Actions\Flowspec\SynthesizeTriggerSpec;
use App\Enums\DigibeeTriggerKind;
use App\Enums\FlowspecTarget;
use App\Exceptions\DigibeeApiException;
use App\Services\Flowspec\DigibeeFlowspecNormalizer;
use App\Services\Flowspec\DigibeeFlowspecValidator;
use App\Support\Digibee\DigibeeDesignClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Block B — the ingestion mode and the design API's write path.
 *
 * The HTTP layer is faked throughout, for the reason DigibeeDesignProbeTest
 * states and which is sharper here: these are the WRITE routes, against a
 * realm running 201 live integrations, and nothing in the platform deletes a
 * pipeline created by mistake. A suite that reached them would be a suite that
 * leaves permanent drafts behind.
 */
function withDesignApi(array $overrides = []): void
{
    config()->set('services.digibee.design', array_merge([
        'endpoint'                => 'https://core.example.test',
        'realm'                   => 'leomadeiras',
        'jwt'                     => 'header.payload.signature',
        'apikey'                  => 'an-api-key',
        'config_path'             => '',
        'timeout'                 => 30,
        'retries'                 => 1,
        'retry_sleep'             => 0,
        'runtime_hosts'           => ['test' => 'https://test.example.test'],
        'deployable_environments' => ['test'],
    ], $overrides));
}

/** A generated document, in the shape the model emits: rooted for the clipboard. */
function clipboardDocument(?string $uuid = null): array
{
    $uuid ??= (string) Str::uuid();
    $step = (string) Str::uuid();

    return [
        'meta'     => [$step => ['position' => ['x' => 200, 'y' => 0]]],
        'flowSpec' => [
            "disconnected-root:{$uuid}" => [[
                'id'       => $step,
                'type'     => 'connector',
                'name'     => 'log-connector',
                'stepName' => 'Log',
                'params'   => ['logLevel' => 'INFO', 'message' => 'oi'],
            ]],
        ],
    ];
}

/** A pipeline as the design API's detail route answers it. */
function storedPipeline(array $overrides = []): array
{
    return array_merge([
        'id'            => 'pid-1',
        'name'          => 'meu-pipeline',
        'description'   => '',
        'versionMajor'  => 0,
        'versionMinor'  => 0,
        'draft'         => true,
        'canvasVersion' => 0,
        'projectId'     => 'proj-default',
        'projectName'   => 'default',
        'triggerSpec'   => [],
        'counters'      => ['capsules' => 0, 'steps' => 4, 'subFlows' => 1],
        'metadata'      => [
            'componentsCount' => 4,
            'canvas'          => ['nodes' => [['id' => 'trigger']], 'edges' => []],
            'integrityHash'   => 'abc123',
        ],
        'flowSpec' => ['start' => []],
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| The mode key: normalizer
|--------------------------------------------------------------------------
*/

it('converts a clipboard document to the stored shape: root renamed, meta dropped', function () {
    $uuid = (string) Str::uuid();

    $result = app(DigibeeFlowspecNormalizer::class)->normalize(clipboardDocument($uuid), FlowspecTarget::Platform);

    expect(array_keys($result->document['flowSpec']))->toBe(['start'])
        ->and($result->document)->not->toHaveKey('meta')
        ->and(implode(' ', $result->fixes))->toContain('renomeada para "start"')
        ->and(implode(' ', $result->fixes))->toContain('Canvas `meta` removido');
});

it('keeps the entry branch first when it renames it', function () {
    $uuid = (string) Str::uuid();
    $document = clipboardDocument($uuid);
    $document['flowSpec']['outra-branch'] = [];

    $result = app(DigibeeFlowspecNormalizer::class)->normalize($document, FlowspecTarget::Platform);

    expect(array_keys($result->document['flowSpec']))->toBe(['start', 'outra-branch']);
});

it('still fills canvas positions for the clipboard, which is the default', function () {
    $document = clipboardDocument();
    $document['meta'] = [];

    $result = app(DigibeeFlowspecNormalizer::class)->normalize($document);

    expect($result->document['meta'])->not->toBe([])
        ->and(implode(' ', $result->fixes))->toContain('Posição de canvas gerada');
});

it('normalizes a document read back from the platform, which has no meta at all', function () {
    $step = (string) Str::uuid();

    $result = app(DigibeeFlowspecNormalizer::class)->normalize([
        'flowSpec' => ['start' => [['id' => $step, 'type' => 'connector', 'name' => 'log-connector']]],
    ], FlowspecTarget::Platform);

    // The old guard demanded `meta` unconditionally and returned the document
    // untouched without it — so every stored pipeline was unnormalizable.
    expect($result->document['flowSpec'])->toHaveKey('start')
        ->and($result->document)->not->toHaveKey('meta');
});

/*
|--------------------------------------------------------------------------
| The mode key: validator
|--------------------------------------------------------------------------
*/

it('accepts a start-rooted document with no meta for the platform', function () {
    $document = app(DigibeeFlowspecNormalizer::class)
        ->normalize(clipboardDocument(), FlowspecTarget::Platform)
        ->document;

    $result = app(DigibeeFlowspecValidator::class)->validate($document, FlowspecTarget::Platform);

    expect($result->passes())->toBeTrue();
});

it('names the spelling it expected when the root is wrong for the target', function () {
    $platform = app(DigibeeFlowspecValidator::class)->validate(clipboardDocument(), FlowspecTarget::Platform);
    $clipboard = app(DigibeeFlowspecValidator::class)->validate([
        'meta'     => [],
        'flowSpec' => ['start' => []],
    ], FlowspecTarget::Clipboard);

    expect(implode(' ', $platform->errors))->toContain('`start`')
        ->and(implode(' ', $clipboard->errors))->toContain('disconnected-root:<uuid>');
});

it('does not require canvas positions for the platform target', function () {
    $step = (string) Str::uuid();
    $document = ['flowSpec' => ['start' => [[
        'id'       => $step,
        'type'     => 'connector',
        'name'     => 'log-connector',
        'stepName' => 'Log',
    ]]]];

    $result = app(DigibeeFlowspecValidator::class)->validate($document, FlowspecTarget::Platform);

    expect($result->passes())->toBeTrue();
});

it('still requires canvas positions for the clipboard', function () {
    $step = (string) Str::uuid();
    $result = app(DigibeeFlowspecValidator::class)->validate([
        'meta'     => [],
        'flowSpec' => ['disconnected-root:' . Str::uuid() => [[
            'id'       => $step,
            'type'     => 'connector',
            'name'     => 'log-connector',
            'stepName' => 'Log',
        ]]],
    ]);

    expect(implode(' ', $result->errors))->toContain('position');
});

/*
|--------------------------------------------------------------------------
| The design client
|--------------------------------------------------------------------------
*/

it('resolves a pipeline by name and keeps only exact matches', function () {
    withDesignApi();
    Http::fake(['*/pipelines*' => Http::response([
        ['id' => 'a', 'name' => 'zfl-cadastro-cliente', 'versionMajor' => 1, 'versionMinor' => 2],
        ['id' => 'b', 'name' => 'zfl-cadastro-cliente-v2', 'versionMajor' => 3, 'versionMinor' => 0],
        ['id' => 'c', 'name' => 'zfl-cadastro-cliente', 'versionMajor' => 2, 'versionMinor' => 0],
    ])]);

    $found = app(DigibeeDesignClient::class)->findByName('zfl-cadastro-cliente');

    // A prefix match would hand back a different pipeline and the ingestion
    // would write a flowSpec into it — nothing published says whether `?name=`
    // is exact.
    expect($found)->toHaveCount(2)
        ->and(array_column($found, 'id'))->toBe(['a', 'c']);
});

it('picks the highest version when a name has several', function () {
    withDesignApi();
    Http::fake(['*/pipelines*' => Http::response([
        ['id' => 'a', 'name' => 'p', 'versionMajor' => 1, 'versionMinor' => 9],
        ['id' => 'b', 'name' => 'p', 'versionMajor' => 2, 'versionMinor' => 0],
        ['id' => 'c', 'name' => 'p', 'versionMajor' => 1, 'versionMinor' => 12],
    ])]);

    expect(app(DigibeeDesignClient::class)->latestByName('p')['id'])->toBe('b');
});

it('sends the name as a server-side filter instead of listing everything', function () {
    withDesignApi();
    Http::fake(['*' => Http::response([])]);

    app(DigibeeDesignClient::class)->findByName('meu-pipeline');

    // The unfiltered listing embeds a whole flowSpec per item, 1801 of them.
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'name=meu-pipeline'));
});

it('reads the new id out of the create envelope, not off the top level', function () {
    withDesignApi();
    Http::fake(['*' => Http::response([
        'pipeline'       => ['id' => 'new-id', 'name' => 'novo', 'draft' => true],
        'configurations' => [],
    ])]);

    expect(app(DigibeeDesignClient::class)->create('novo'))->toBe('new-id');
});

it('reports a create whose envelope carries no id', function () {
    withDesignApi();
    Http::fake(['*' => Http::response(['unexpected' => true])]);

    expect(fn () => app(DigibeeDesignClient::class)->create('novo'))
        ->toThrow(DigibeeApiException::class, 'may have been created anyway');
});

it('refuses to upsert a document with no id, without calling anything', function () {
    withDesignApi();
    Http::fake();

    expect(fn () => app(DigibeeDesignClient::class)->upsert(['name' => 'p']))
        ->toThrow(DigibeeApiException::class, 'CREATE a duplicate pipeline');

    Http::assertNothingSent();
});

it('turns a failed design call into an exception that says what the status means', function () {
    withDesignApi();
    Http::fake(['*' => Http::response(['message' => 'nope'], 403)]);

    expect(fn () => app(DigibeeDesignClient::class)->pipeline('pid-1'))
        ->toThrow(DigibeeApiException::class, 'lacks the permission');
});

it('reports a non-JSON body rather than treating it as an empty document', function () {
    withDesignApi();
    Http::fake(['*' => Http::response('<html>login</html>', 200)]);

    expect(fn () => app(DigibeeDesignClient::class)->pipeline('pid-1'))
        ->toThrow(DigibeeApiException::class, 'not JSON');
});

/*
|--------------------------------------------------------------------------
| The ingestion
|--------------------------------------------------------------------------
*/

/**
 * Fakes the four calls one ingestion makes — list by name, read detail, POST
 * upsert, read detail again — with the platform's own behaviour of answering
 * the written document back on the next read.
 */
function fakeDesignApi(?array $stored = null): void
{
    $state = ['stored' => $stored ?? storedPipeline()];

    Http::fake(function (Request $request) use (&$state) {
        if ($request->method() === 'POST') {
            $state['stored']['flowSpec'] = $request->data()['flowSpec'] ?? $state['stored']['flowSpec'];

            return Http::response(['pipeline' => $state['stored'], 'configurations' => []]);
        }

        if (str_contains($request->url(), '/pipelines/')) {
            return Http::response($state['stored']);
        }

        return Http::response([[
            'id'           => $state['stored']['id'],
            'name'         => $state['stored']['name'],
            'versionMajor' => $state['stored']['versionMajor'],
            'versionMinor' => $state['stored']['versionMinor'],
        ]]);
    });
}

it('writes the converted flowSpec and confirms it by reading it back', function () {
    withDesignApi();
    $posted = [];

    Http::fake(function (Request $request) use (&$posted) {
        if ($request->method() === 'POST') {
            $posted[] = $request->data();

            return Http::response(['pipeline' => ['id' => 'pid-1'], 'configurations' => []]);
        }

        if (str_contains($request->url(), '/pipelines/pid-1')) {
            return Http::response(storedPipeline([
                'flowSpec' => $posted === [] ? ['start' => []] : $posted[0]['flowSpec'],
            ]));
        }

        return Http::response([['id' => 'pid-1', 'name' => 'meu-pipeline', 'versionMajor' => 0, 'versionMinor' => 0]]);
    });

    $report = app(IngestFlowspec::class)->handle(clipboardDocument(), 'meu-pipeline');

    expect($report->ok())->toBeTrue()
        ->and($report->confirmed())->toBeTrue()
        ->and($report->stepCount)->toBe(1)
        ->and($report->branchCount)->toBe(1)
        ->and(array_keys($posted[0]['flowSpec']))->toBe(['start'])
        ->and($posted[0])->toHaveKey('projectId') // the whole 34-key document travels back
        ->and($posted[0]['metadata'])->not->toHaveKey('canvas')
        ->and($posted[0]['metadata'])->not->toHaveKey('integrityHash');
});

it('says out loud that the read-back flowSpec is not what it sent', function () {
    withDesignApi();

    Http::fake(function (Request $request) {
        if ($request->method() === 'POST') {
            return Http::response(['pipeline' => ['id' => 'pid-1'], 'configurations' => []]);
        }

        if (str_contains($request->url(), '/pipelines/pid-1')) {
            return Http::response(storedPipeline(['flowSpec' => ['start' => []]]));
        }

        return Http::response([['id' => 'pid-1', 'name' => 'meu-pipeline', 'versionMajor' => 0, 'versionMinor' => 0]]);
    });

    $report = app(IngestFlowspec::class)->handle(clipboardDocument(), 'meu-pipeline');

    // 200 is what this route answers for a create, an upsert and a silently
    // discarded field alike, so it is not evidence of a write.
    expect($report->wrote)->toBeTrue()
        ->and($report->verified)->toBeFalse()
        ->and($report->confirmed())->toBeFalse()
        ->and(implode(' ', $report->warnings))->toContain('NÃO é idêntico');
});

it('refuses to write an invalid flowSpec, and calls nothing', function () {
    withDesignApi();
    Http::fake();

    $document = clipboardDocument();
    $document['flowSpec'][array_key_first($document['flowSpec'])][0]['name'] = 'inventado-connector';

    $report = app(IngestFlowspec::class)->handle($document, 'meu-pipeline');

    expect($report->ok())->toBeFalse()
        ->and(implode(' ', $report->errors))->toContain('fora do catálogo');

    Http::assertNothingSent();
});

it('refuses to create a pipeline unless creating was asked for', function () {
    withDesignApi();
    Http::fake(['*' => Http::response([])]);

    $report = app(IngestFlowspec::class)->handle(clipboardDocument(), 'nao-existe');

    expect($report->ok())->toBeFalse()
        ->and(implode(' ', $report->errors))->toContain('nada na plataforma apaga pipeline');

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

it('creates the pipeline when asked, and says where it really landed', function () {
    withDesignApi();
    $created = false;

    Http::fake(function (Request $request) use (&$created) {
        if ($request->method() === 'POST' && ! $created) {
            $created = true;

            return Http::response(['pipeline' => storedPipeline(['name' => 'novo']), 'configurations' => []]);
        }

        if ($request->method() === 'POST') {
            return Http::response(['pipeline' => storedPipeline(['name' => 'novo']), 'configurations' => []]);
        }

        if (str_contains($request->url(), '/pipelines/')) {
            return Http::response(storedPipeline(['name' => 'novo']));
        }

        return Http::response([]);
    });

    $report = app(IngestFlowspec::class)->handle(clipboardDocument(), 'novo', create: true);

    expect($report->created)->toBeTrue()
        ->and(implode(' ', $report->warnings))->toContain('projeto `default`');
});

it('preserves a triggerSpec somebody configured, unless replacing it is explicit', function () {
    withDesignApi();
    $stored = storedPipeline(['triggerSpec' => ['type' => 'rest', 'name' => 'rest', 'basicAuth' => true]]);
    $posted = [];

    Http::fake(function (Request $request) use (&$posted, $stored) {
        if ($request->method() === 'POST') {
            $posted[] = $request->data();

            return Http::response(['pipeline' => $stored, 'configurations' => []]);
        }

        if (str_contains($request->url(), '/pipelines/')) {
            return Http::response($stored);
        }

        return Http::response([['id' => 'pid-1', 'name' => 'meu-pipeline', 'versionMajor' => 0, 'versionMinor' => 0]]);
    });

    $trigger = app(SynthesizeTriggerSpec::class)->handle(DigibeeTriggerKind::Http);
    $report = app(IngestFlowspec::class)->handle(clipboardDocument(), 'meu-pipeline', trigger: $trigger);

    expect($report->triggerApplied)->toBeFalse()
        ->and($posted[0]['triggerSpec'])->toBe($stored['triggerSpec'])
        ->and(implode(' ', $report->warnings))->toContain('triggerSpec existente preservado');
});

it('writes a synthesized trigger into a pipeline that has none', function () {
    withDesignApi();
    $posted = [];

    Http::fake(function (Request $request) use (&$posted) {
        if ($request->method() === 'POST') {
            $posted[] = $request->data();

            return Http::response(['pipeline' => storedPipeline(), 'configurations' => []]);
        }

        if (str_contains($request->url(), '/pipelines/')) {
            return Http::response(storedPipeline([
                'flowSpec' => $posted === [] ? ['start' => []] : $posted[0]['flowSpec'],
            ]));
        }

        return Http::response([['id' => 'pid-1', 'name' => 'meu-pipeline', 'versionMajor' => 0, 'versionMinor' => 0]]);
    });

    $trigger = app(SynthesizeTriggerSpec::class)->handle(DigibeeTriggerKind::Rest);
    $report = app(IngestFlowspec::class)->handle(clipboardDocument(), 'meu-pipeline', trigger: $trigger);

    expect($report->triggerApplied)->toBeTrue()
        ->and($posted[0]['triggerSpec']['type'])->toBe('rest')
        ->and($posted[0]['triggerCategory'])->toBe('Web Protocols');
});

it('refuses an incomplete trigger instead of deploying a pipeline nobody can call', function () {
    withDesignApi();
    Http::fake();

    $trigger = app(SynthesizeTriggerSpec::class)->handle(DigibeeTriggerKind::Scheduler);
    $report = app(IngestFlowspec::class)->handle(clipboardDocument(), 'meu-pipeline', trigger: $trigger);

    expect($report->ok())->toBeFalse()
        ->and(implode(' ', $report->errors))->toContain('cronExpression');

    Http::assertNothingSent();
});

it('warns when the pipeline it is about to overwrite has a released version', function () {
    withDesignApi();

    Http::fake(function (Request $request) {
        if ($request->method() === 'POST') {
            return Http::response(['pipeline' => [], 'configurations' => []]);
        }

        if (str_contains($request->url(), '/pipelines/')) {
            return Http::response(storedPipeline(['versionMajor' => 3, 'versionMinor' => 1, 'draft' => false]));
        }

        return Http::response([['id' => 'pid-1', 'name' => 'meu-pipeline', 'versionMajor' => 3, 'versionMinor' => 1]]);
    });

    $report = app(IngestFlowspec::class)->handle(clipboardDocument(), 'meu-pipeline');

    expect(implode(' ', $report->warnings))->toContain('v3.1')
        ->and(implode(' ', $report->warnings))->toContain('verificado apenas contra um v0.0');
});

it('reports the derived counters it left stale rather than recomputing them', function () {
    withDesignApi();
    fakeDesignApi();

    $report = app(IngestFlowspec::class)->handle(clipboardDocument(), 'meu-pipeline');

    expect(implode(' ', $report->warnings))->toContain('Contadores derivados')
        ->and(implode(' ', $report->warnings))->toContain('counters');
});

it('resolves and validates without writing anything on a dry run', function () {
    withDesignApi();
    fakeDesignApi();

    $report = app(IngestFlowspec::class)->handle(clipboardDocument(), 'meu-pipeline', dryRun: true);

    expect($report->ok())->toBeTrue()
        ->and($report->wrote)->toBeFalse()
        ->and($report->stepCount)->toBe(1);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

/*
|--------------------------------------------------------------------------
| Two rules that described nothing real
|--------------------------------------------------------------------------
|
| Both were found by running the validator over the 201 pipelines the tenant
| actually runs, which is the check the paste-only era never had a reason to
| do. Neither is about the ingestion mode; both would have made block F's
| correction loop "fix" pipelines that were already right.
*/

it('accepts a track connector with no exception track, which is the ordinary state', function () {
    $forEach = (string) Str::uuid();
    $inner = (string) Str::uuid();

    $document = [
        'flowSpec' => [
            'start' => [[
                'id'       => $forEach,
                'type'     => 'connector',
                'name'     => 'for-each-connector',
                'stepName' => 'For Each',
                'params'   => ['onProcess' => "{$forEach}-onProcessTrack"],
            ]],
            "{$forEach}-onProcessTrack" => [[
                'id'       => $inner,
                'type'     => 'connector',
                'name'     => 'log-connector',
                'stepName' => 'Log',
            ]],
        ],
    ];

    // `onException` is absent in 296 of the 384 track references across the
    // tenant's pipelines, and never dangling — while `onProcess` is a real
    // branch in 404 of 404.
    expect(app(DigibeeFlowspecValidator::class)->validate($document, FlowspecTarget::Platform)->passes())
        ->toBeTrue();
});

it('still flags a track reference that names a branch which does not exist', function () {
    $forEach = (string) Str::uuid();

    $document = ['flowSpec' => ['start' => [[
        'id'       => $forEach,
        'type'     => 'connector',
        'name'     => 'for-each-connector',
        'stepName' => 'For Each',
        'params'   => ['onProcess' => 'nao-existe', 'onException' => 'tambem-nao'],
    ]]]];

    $errors = app(DigibeeFlowspecValidator::class)->validate($document, FlowspecTarget::Platform)->errors;

    // A present-but-wrong name is a typo, not an omission — both tracks flag.
    expect(implode(' ', $errors))->toContain('nao-existe')
        ->and(implode(' ', $errors))->toContain('tambem-nao');
});

it('accepts the two Double Braces scopes Digibee documents and this rejected', function () {
    $step = (string) Str::uuid();

    $document = ['flowSpec' => ['start' => [[
        'id'       => $step,
        'type'     => 'connector',
        'name'     => 'log-connector',
        'stepName' => 'Log',
        'params'   => [
            // `{{iterators.<for-each-alias>.current}}` is how the For Each
            // reference itself reads the current item, and
            // `{{replica.instance_variable_name}}` is the multi-instance
            // guide's own pattern.
            'message' => '{{ iterators.for-each-1.current }} / {{ replica.instancia }}',
        ],
    ]]]];

    expect(app(DigibeeFlowspecValidator::class)->validate($document, FlowspecTarget::Platform)->passes())
        ->toBeTrue();
});

it('still rejects a scope nobody documents', function () {
    $step = (string) Str::uuid();

    $document = ['flowSpec' => ['start' => [[
        'id'       => $step,
        'type'     => 'connector',
        'name'     => 'log-connector',
        'stepName' => 'Log',
        'params'   => ['message' => '{{ inventado.campo }}'],
    ]]]];

    expect(implode(' ', app(DigibeeFlowspecValidator::class)->validate($document, FlowspecTarget::Platform)->errors))
        ->toContain('Escopo Double Braces desconhecido');
});

/*
|--------------------------------------------------------------------------
| What a read-back pipeline is judged on
|--------------------------------------------------------------------------
*/

it('judges a stored pipeline by its live flow, not by blocks abandoned on the canvas', function () {
    $step = (string) Str::uuid();

    $document = [
        'flowSpec' => ['start' => [[
            'id'       => $step,
            'type'     => 'connector',
            'name'     => 'rest-connector-v2',
            'stepName' => 'Chama serviço',
            'params'   => ['headers' => ['Authorization' => '{{ account.servico }}']],
        ]]],
        'metadata' => [
            'disconnectedFlowSpecs' => [[
                'flowSpec' => ['disconnected-start:x' => [[
                    'id'     => (string) Str::uuid(),
                    'type'   => 'connector',
                    'name'   => 'rest-connector-v2',
                    'params' => ['headers' => ['ApiKey' => 'ZmFrZS1hcGkta2V5LTEyMzQ1Njc4OTBhYmNkZWY']],
                ]]],
            ]],
        ],
    ];

    // 29 of the corpus's credential findings sit in leftovers like this, and 3
    // pipelines fail for no other reason. No rewrite of the flow can fix one.
    expect(app(DigibeeFlowspecValidator::class)->validate($document, FlowspecTarget::Platform)->passes())
        ->toBeTrue();
});

it('still fails a stored pipeline whose LIVE flow carries the credential', function () {
    $step = (string) Str::uuid();

    $document = ['flowSpec' => ['start' => [[
        'id'       => $step,
        'type'     => 'connector',
        'name'     => 'rest-connector-v2',
        'stepName' => 'Chama serviço',
        'params'   => ['headers' => ['ApiKey' => 'ZmFrZS1hcGkta2V5LTEyMzQ1Njc4OTBhYmNkZWY']],
    ]]]];

    expect(implode(' ', app(DigibeeFlowspecValidator::class)->validate($document, FlowspecTarget::Platform)->errors))
        ->toContain('Credencial literal');
});

it('keeps scanning a clipboard document whole, since that is what gets persisted', function () {
    $step = (string) Str::uuid();
    $document = clipboardDocument();
    $document['flowSpec'][array_key_first($document['flowSpec'])][0]['params']['headers'] = [
        'ApiKey' => 'ZmFrZS1hcGkta2V5LTEyMzQ1Njc4OTBhYmNkZWY',
    ];

    expect(implode(' ', app(DigibeeFlowspecValidator::class)->validate($document)->errors))
        ->toContain('Credencial literal');
});

it('accepts the third choice dialect, the one that can compose conditions', function () {
    $choice = (string) Str::uuid();

    $document = ['flowSpec' => [
        'start' => [[
            'id'       => $choice,
            'type'     => 'choice',
            'stepName' => 'Allowlist de rotas',
            'when'     => [[
                // 5 conditions across 2 live pipelines use this, and it is the
                // only dialect of the three that composes.
                'doubleBraces' => '{{ AND(EQUALTO(message.method, "GET"), CONTAINS(message.path, "/rota")) }}',
                'target'       => 'rota-permitida',
            ]],
            'otherwise' => 'rota-negada',
        ]],
        'rota-permitida' => [],
        'rota-negada'    => [],
    ]];

    expect(app(DigibeeFlowspecValidator::class)->validate($document, FlowspecTarget::Platform)->passes())
        ->toBeTrue();
});

it('still flags a choice condition with no dialect at all', function () {
    $choice = (string) Str::uuid();

    $document = ['flowSpec' => [
        'start' => [[
            'id'       => $choice,
            'type'     => 'choice',
            'stepName' => 'Sem condição',
            'when'     => [['target' => 'algum-lugar']],
        ]],
        'algum-lugar' => [],
    ]];

    expect(implode(' ', app(DigibeeFlowspecValidator::class)->validate($document, FlowspecTarget::Platform)->errors))
        ->toContain('doubleBraces');
});
