<?php

use App\Actions\Digibee\RunPipelineTestSuite;
use App\Enums\DigibeeTriggerAuth;
use App\Exceptions\DigibeeApiException;
use App\Support\Digibee\Testing\Assertion;
use App\Support\Digibee\Testing\AssertionOperator;
use App\Support\Digibee\Testing\EndpointCredential;
use App\Support\Digibee\Testing\PipelineTestCase;
use App\Support\Digibee\Testing\PipelineTestSuite;
use App\Support\Digibee\Testing\StatusExpectation;
use App\Support\Digibee\Testing\TestCaseCategory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Block D's half that needs no deployment: everything between a built matrix
 * and an evaluated result, with the network faked.
 *
 * The fake is not only about speed here — a real run of these cases sends
 * malformed payloads at a pipeline that writes to SAP and BigQuery.
 */
function withRuntime(array $overrides = []): void
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
        'runtime_hosts'           => ['test' => 'https://test.example.test', 'prod' => 'https://api.example.test'],
        'deployable_environments' => ['test'],
    ], $overrides));
}

function suiteWith(array $cases, string $environment = 'test'): PipelineTestSuite
{
    return new PipelineTestSuite(
        name: 'suite',
        pipelineName: 'meu-pipeline',
        environment: $environment,
        cases: $cases,
        versionMajor: 2,
    );
}

function okCase(string $name = 'Caminho feliz', ?string $blocked = null): PipelineTestCase
{
    return new PipelineTestCase(
        name: $name,
        category: TestCaseCategory::HappyPath,
        expects: StatusExpectation::ok(),
        assertions: [new Assertion('$.mensagem', AssertionOperator::Exists)],
        body: ['cpf' => '<cpf>'],
        blocked: $blocked,
    );
}

it('calls the environment host with the pipeline major version in the path', function () {
    withRuntime();
    Http::fake(['*' => Http::response(['mensagem' => 'ok'])]);

    $run = app(RunPipelineTestSuite::class)->handle(suiteWith([okCase()]));

    expect($run->url)->toBe('https://test.example.test/pipeline/leomadeiras/v2/meu-pipeline')
        ->and($run->passed())->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->url() === $run->url && $request->method() === 'POST');
});

it('refuses to fire a synthetic battery at an environment outside the allowed list', function () {
    withRuntime();
    Http::fake();

    expect(fn () => app(RunPipelineTestSuite::class)->handle(suiteWith([okCase()], 'prod')))
        ->toThrow(DigibeeApiException::class, 'malformed and incomplete');

    Http::assertNothingSent();
});

it('never sends a blocked case, and reports it as never sent', function () {
    withRuntime();
    Http::fake(['*' => Http::response(['mensagem' => 'ok'])]);

    $run = app(RunPipelineTestSuite::class)->handle(suiteWith([
        okCase('Caminho feliz'),
        okCase('Precisa de CPF real', blocked: 'Preencha valores reais para: cpf.'),
    ]));

    // The placeholder NAMES the field; sending it would put an invented CPF
    // into a real system and read the rejection as a pipeline defect.
    expect($run->tally())->toBe(['ran' => 1, 'passed' => 1, 'failed' => 0, 'skipped' => 1]);
    Http::assertSentCount(1);
});

it('reports a failed assertion as a line naming the case', function () {
    withRuntime();
    Http::fake(['*' => Http::response(['outra' => 'coisa'])]);

    $run = app(RunPipelineTestSuite::class)->handle(suiteWith([okCase('Caminho feliz')]));

    expect($run->passed())->toBeFalse()
        ->and($run->failures())->toHaveCount(1)
        ->and($run->failures()[0])->toStartWith('[Caminho feliz]');
});

it('reports a status mismatch with what it expected', function () {
    withRuntime();
    Http::fake(['*' => Http::response(['mensagem' => 'ok'], 500)]);

    $run = app(RunPipelineTestSuite::class)->handle(suiteWith([okCase()]));

    expect(implode(' ', $run->failures()))->toContain('veio 500');
});

it('does not retry a failing case', function () {
    withRuntime();
    Http::fake(['*' => Http::response('boom', 500)]);

    app(RunPipelineTestSuite::class)->handle(suiteWith([okCase()]));

    // A design read is idempotent and retried; a test case is not — re-firing
    // a POST that already half-ran duplicates whatever it wrote.
    Http::assertSentCount(1);
});

it('turns a pipeline that never answers into a result instead of an exception', function () {
    withRuntime();
    Http::fake(fn () => throw new ConnectionException('timeout'));

    $run = app(RunPipelineTestSuite::class)->handle(suiteWith([okCase('A'), okCase('B')]));

    expect($run->tally()['ran'])->toBe(2)
        ->and($run->failed())->toHaveCount(2)
        ->and($run->results[0]->status)->toBe(0);
});

it('sends a string body raw, which is the whole point of the malformed case', function () {
    withRuntime();
    Http::fake(['*' => Http::response([], 400)]);

    $malformed = new PipelineTestCase(
        name: 'Payload malformado',
        category: TestCaseCategory::Contract,
        expects: StatusExpectation::handled(),
        body: '{"cpf": ',
    );

    app(RunPipelineTestSuite::class)->handle(suiteWith([$malformed]));

    Http::assertSent(fn (Request $request) => $request->body() === '{"cpf": ');
});

it('carries the endpoint credential, which is not the design one', function () {
    withRuntime();
    Http::fake(['*' => Http::response(['mensagem' => 'ok'])]);

    $run = app(RunPipelineTestSuite::class)->handle(
        suiteWith([okCase()]),
        EndpointCredential::apiKey('chave-do-consumidor', 'X-Api-Key'),
    );

    expect($run->authenticated)->toBeTrue();
    Http::assertSent(fn (Request $request) => $request->header('X-Api-Key')[0] === 'chave-do-consumidor'
        // never the realm-wide design token
        && $request->header('Authorization') === []);
});

it('builds a basic auth header the way a consumer sends it', function () {
    expect(EndpointCredential::basic('leo', 'senha')->headers())
        ->toBe(['Authorization' => 'Basic ' . base64_encode('leo:senha')])
        ->and(EndpointCredential::jwt('t0ken')->kind)->toBe(DigibeeTriggerAuth::Jwt);
});

it('flags a run that was refused at the door rather than executed', function () {
    withRuntime();
    Http::fake(['*' => Http::response(['message' => 'unauthorized'], 401)]);

    $run = app(RunPipelineTestSuite::class)->handle(suiteWith([okCase('A'), okCase('B')]));

    // The single most misleading input this feature can hand a model: a wall
    // of 401s looks exactly like a pipeline that rejects everything.
    expect($run->refusedForCredentials())->toBeTrue()
        ->and($run->passed())->toBeFalse();
});

it('does not flag a credential problem when a credential was given', function () {
    withRuntime();
    Http::fake(['*' => Http::response(['message' => 'nope'], 403)]);

    $run = app(RunPipelineTestSuite::class)->handle(
        suiteWith([okCase()]),
        EndpointCredential::basic('leo', 'senha'),
    );

    expect($run->refusedForCredentials())->toBeFalse();
});

it('does not flag a credential problem when something actually ran', function () {
    withRuntime();
    $responses = [Http::response(['mensagem' => 'ok']), Http::response([], 401)];
    Http::fake(fn () => array_shift($responses));

    $run = app(RunPipelineTestSuite::class)->handle(suiteWith([okCase('A'), okCase('B')]));

    expect($run->refusedForCredentials())->toBeFalse();
});

it('refuses an environment with no host mapped instead of defaulting to one', function () {
    withRuntime(['deployable_environments' => ['test', 'homolog']]);
    Http::fake();

    expect(fn () => app(RunPipelineTestSuite::class)->handle(suiteWith([okCase()], 'homolog')))
        ->toThrow(DigibeeApiException::class, 'No runtime host configured');
});

/*
|--------------------------------------------------------------------------
| The one claim about a header
|--------------------------------------------------------------------------
*/

function contentTypeCase(string $expected = 'application/json'): PipelineTestCase
{
    return new PipelineTestCase(
        name: 'Caminho feliz',
        category: TestCaseCategory::HappyPath,
        expects: StatusExpectation::ok(),
        expectsContentType: $expected,
    );
}

it('passes when the response carries the declared media type, charset and all', function () {
    withRuntime();
    Http::fake(['*' => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json;charset=UTF-8'])]);

    // Comparing the raw header would fail on every correct answer.
    expect(app(RunPipelineTestSuite::class)->handle(suiteWith([contentTypeCase()]))->passed())->toBeTrue();
});

it('fails when the pipeline answers with another media type', function () {
    withRuntime();
    Http::fake(['*' => Http::response('<xml/>', 200, ['Content-Type' => 'text/xml'])]);

    $run = app(RunPipelineTestSuite::class)->handle(suiteWith([contentTypeCase()]));

    // A perfectly shaped body with the wrong type still breaks the caller's
    // parser, which is the whole reason this claim exists.
    expect($run->passed())->toBeFalse()
        ->and(implode(' ', $run->failures()))->toContain('Esperava Content-Type application/json, veio text/xml');
});

it('fails when the pipeline sends no content type at all', function () {
    withRuntime();
    Http::fake(['*' => Http::response('', 200, [])]);

    expect(implode(' ', app(RunPipelineTestSuite::class)->handle(suiteWith([contentTypeCase()]))->failures()))
        ->toContain('veio nenhum');
});

it('claims nothing about the media type when the document declared none', function () {
    withRuntime();
    Http::fake(['*' => Http::response(['mensagem' => 'ok'], 200, ['Content-Type' => 'text/plain'])]);

    $run = app(RunPipelineTestSuite::class)->handle(suiteWith([okCase()]));

    expect($run->results[0]->contentTypeMatched())->toBeNull()
        ->and($run->passed())->toBeTrue();
});

it('calls the URL the platform reported rather than composing one', function () {
    withRuntime();
    Http::fake(['*' => Http::response(['mensagem' => 'ok'])]);

    $reported = 'https://test.example.test/pipeline/leomadeiras/v7/outro-nome';
    $run = app(RunPipelineTestSuite::class)->handle(suiteWith([okCase()]), null, $reported);

    expect($run->url)->toBe($reported);
    Http::assertSent(fn (Request $request) => $request->url() === $reported);
});
