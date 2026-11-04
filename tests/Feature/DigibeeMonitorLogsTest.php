<?php

use App\Exceptions\DigibeeApiException;
use App\Support\Digibee\Monitor\DigibeeMonitorClient;
use App\Support\Digibee\Monitor\LogRedactor;
use App\Support\Digibee\Monitor\MonitorSession;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The Monitor client replays a person's whole web session, so the HTTP layer is
 * always faked here — and what is asserted first is what it must NOT send.
 */
function monitorJwt(int $exp): string
{
    $part = fn (array $data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

    return $part(['alg' => 'HS256']) . '.' . $part(['realm' => 'leomadeiras', 'exp' => $exp]) . '.signature';
}

function monitorSession(?int $exp = null): MonitorSession
{
    return new MonitorSession('Bearer ' . monitorJwt($exp ?? now()->addDay()->timestamp), 'the-apikey');
}

function withMonitorConfig(string $sessionPath = '/nonexistent/session.json'): void
{
    config()->set('services.digibee.monitor', [
        'endpoint'           => 'https://core.example.test',
        'app_url'            => 'https://www.example.test',
        'login_url'          => 'https://auth.example.test/authorize',
        'realm'              => 'leomadeiras',
        'timeout'            => 5,
        'session_path'       => $sessionPath,
        'profile_path'       => sys_get_temp_dir(),
        'playwright_path'    => '',
        'ld_library_path'    => '',
        'login_wait_seconds' => 1,
    ]);
}

beforeEach(function () {
    Http::preventStrayRequests();
    withMonitorConfig();
});

it('replays the captured headers verbatim and asks for the executions window', function () {
    Http::fake(['core.example.test/monitor/api/v1/executions*' => Http::response(['data' => []])]);

    (new DigibeeMonitorClient(monitorSession()))->executions('prod', now()->subHour(), now(), 10);

    Http::assertSent(function (Request $request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), 'environment=prod')
            && str_starts_with($request->header('authorization')[0], 'Bearer ')
            && $request->header('apikey')[0] === 'the-apikey'
            && $request['realm'] === 'leomadeiras'
            && $request['pagination'] === ['limit' => 10, 'order' => 'descending'];
    });
});

it('sends no filters as an empty object, as the tab does', function () {
    Http::fake(['core.example.test/*' => Http::response(['data' => []])]);

    (new DigibeeMonitorClient(monitorSession()))->executions('prod', now()->subHour(), now());

    Http::assertSent(fn (Request $request) => str_contains($request->body(), '"filters":{}'));
});

it('asks the platform for the pipeline, the payload text and the next page', function () {
    Http::fake(['core.example.test/*' => Http::response(['data' => []])]);

    (new DigibeeMonitorClient(monitorSession()))
        ->executions('prod', now()->subDay(), now(), 500, ' evt-send-estoque-vtex ', '5000321', 'WzE3OTM3');

    // Read off the wire: the fake keeps the unencoded data, where `filters`
    // is still the object the client cast it to.
    Http::assertSent(fn (Request $request) => json_decode($request->body(), true)['filters'] === [
        'pipelineName'  => 'evt-send-estoque-vtex',
        'searchType'    => 'EXACT',
        'payloadSearch' => '5000321',
    ] && $request['pagination'] === ['limit' => 100, 'order' => 'descending', 'cursor' => 'WzE3OTM3']);
});

it('follows the cursor for --pages and stops when the platform says there is no more', function () {
    $path = sys_get_temp_dir() . '/monitor-session-' . uniqid() . '.json';
    file_put_contents($path, json_encode(['authorization' => 'Bearer ' . monitorJwt(now()->addDay()->timestamp), 'apikey' => 'k']));
    withMonitorConfig($path);

    $execution = fn (string $key) => ['timestamp' => '2026-11-04T12:00:00Z', 'attributes' => [
        'pipeline.name'             => 'evt-send-estoque-vtex', 'pipeline.version' => '3', 'pipeline.key' => $key,
        'execution.elapsed_time_ms' => 12, 'payload.response' => '{"code":200}',
    ]];

    Http::fakeSequence('core.example.test/*')
        ->push(['data' => [$execution('first')], 'pagination' => ['nextCursor' => 'c1', 'hasNext' => true], 'total' => 2])
        ->push(['data' => [$execution('second')], 'pagination' => ['nextCursor' => null, 'hasNext' => false], 'total' => 2]);

    $this->artisan('digibee:logs', ['--pipeline' => 'evt-send-estoque-vtex', '--pages' => 5])
        ->expectsOutputToContain('first')
        ->expectsOutputToContain('second')
        ->expectsOutputToContain('2 of 2 execution(s)')
        ->assertSuccessful();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => ($request['pagination']['cursor'] ?? null) === 'c1');

    unlink($path);
});

it('searches log lines with the text under logMessage', function () {
    Http::fake(['core.example.test/monitor/api/v1/pipeline-logs*' => Http::response(['data' => []])]);

    (new DigibeeMonitorClient(monitorSession()))
        ->pipelineLogs('prod', now()->subDay(), now(), 50, 'evt-send-estoque-vtex', '54430275572');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/monitor/api/v1/pipeline-logs?environment=prod')
        && json_decode($request->body(), true)['filters'] === [
            'pipelineName' => 'evt-send-estoque-vtex',
            'searchType'   => 'EXACT',
            'logMessage'   => '54430275572',
        ]);
});

it('prints matching log lines redacted, with the execution to open', function () {
    $path = sys_get_temp_dir() . '/monitor-session-' . uniqid() . '.json';
    file_put_contents($path, json_encode(['authorization' => 'Bearer ' . monitorJwt(now()->addDay()->timestamp), 'apikey' => 'k']));
    withMonitorConfig($path);

    // The measured record shape, and the measured cap on `total`.
    Http::fake(['core.example.test/monitor/api/v1/pipeline-logs*' => Http::response([
        'data' => [[
            'attributes'   => ['pipeline.name' => 'zfl-busca-preco', 'pipeline.version' => '1.11', 'pipeline.key' => '377c4ab2-1ce2'],
            'body'         => 'Entrada {"Authorization":"Basic c2VjcmV0OnBhc3M=","order":"54430275572"} <xml/>',
            'severityText' => 'ERROR',
            'timestamp'    => '2026-11-04T12:32:09.488Z',
        ]],
        'pagination' => ['nextCursor' => 'c1', 'hasNext' => true],
        'total'      => 10000,
    ])]);

    $this->artisan('digibee:logs', ['--message' => '54430275572'])
        // One expectation per written line: Laravel spends a write on the
        // first expectation it satisfies.
        ->expectsOutputToContain('ERROR zfl-busca-preco 1.11 377c4ab2-1ce2 Entrada {"Authorization":"[redacted]","order":"54430275572"}')
        ->doesntExpectOutputToContain('c2VjcmV0OnBhc3M=')
        ->expectsOutputToContain('1 of 10000+ line(s)')
        ->assertSuccessful();

    unlink($path);
});

it('refuses to mix a payload search with a log-line search', function () {
    $path = sys_get_temp_dir() . '/monitor-session-' . uniqid() . '.json';
    file_put_contents($path, json_encode(['authorization' => 'Bearer ' . monitorJwt(now()->addDay()->timestamp), 'apikey' => 'k']));
    withMonitorConfig($path);
    Http::fake();

    $this->artisan('digibee:logs', ['--message' => 'a', '--search' => 'b'])->assertFailed();

    Http::assertNothingSent();
    unlink($path);
});

it('collects every matched execution into one redacted report file', function () {
    $session = sys_get_temp_dir() . '/monitor-session-' . uniqid() . '.json';
    file_put_contents($session, json_encode(['authorization' => 'Bearer ' . monitorJwt(now()->addDay()->timestamp), 'apikey' => 'k']));
    withMonitorConfig($session);
    $report = sys_get_temp_dir() . '/monitor-report-' . uniqid() . '.json';

    // A response body that is JSON in a string inside JSON in a string — one
    // escape deeper than redacting the encoded document alone would reach.
    $response = json_encode(['code' => 500, 'body' => json_encode(['document' => '56212410000150'])]);
    $execution = fn (string $key, string $at) => ['timestamp' => $at, 'attributes' => [
        'pipeline.name'             => 'evt-send-estoque-vtex', 'pipeline.version' => '1.1', 'pipeline.key' => $key,
        'execution.elapsed_time_ms' => 40, 'payload.request' => '{"sku":"5000321"}', 'payload.response' => $response,
    ]];

    Http::fake([
        'core.example.test/monitor/*' => Http::response([
            'data'       => [$execution('newer', '2026-11-04T12:00:00Z'), $execution('older', '2026-11-04T11:00:00Z')],
            'pagination' => ['hasNext' => false], 'total' => 2,
        ]),
        'core.example.test/runtime/*pipelineKey=newer*' => Http::response("timestamp;logLevel;logMessage\n2026-11-04T12:00:01Z;ERROR;boom\n2026-11-04T12:00:00Z;INFO;start\n"),
        'core.example.test/runtime/*pipelineKey=older*' => Http::response('gone', 404),
    ]);

    $this->artisan('digibee:logs', ['--search' => '5000321', '--collect' => $report])
        ->expectsOutputToContain('1 execution(s) the platform would not export')
        ->assertSuccessful();

    $written = file_get_contents($report);
    $data = json_decode($written, true);

    expect($written)->not->toContain('56212410000150')
        ->and(decoct(fileperms($report) & 0777))->toBe('600')
        ->and($data['query'])->toMatchArray(['searched' => 'executions', 'search' => '5000321'])
        ->and($data['unreadable'])->toBe(['older'])
        // Chronological: the unreadable one has no start, the read one reads top to bottom.
        ->and(array_column($data['executions'], 'key'))->toBe(['older', 'newer'])
        ->and($data['executions'][1]['status'])->toBe(500)
        ->and($data['executions'][1]['levels'])->toEqualCanonicalizing(['ERROR' => 1, 'INFO' => 1])
        ->and(array_column($data['executions'][1]['lines'], 'message'))->toBe(['start', 'boom']);

    unlink($session);
    unlink($report);
});

/** A valid session on disk, for the tests that go through the command. */
function withMonitorSessionFile(): string
{
    $path = sys_get_temp_dir() . '/monitor-session-' . uniqid() . '.json';
    file_put_contents($path, json_encode(['authorization' => 'Bearer ' . monitorJwt(now()->addDay()->timestamp), 'apikey' => 'k']));
    withMonitorConfig($path);
    config()->set('app.display_timezone', 'America/Sao_Paulo');

    return $path;
}

function sentTimeRange(): array
{
    $range = null;
    Http::assertSent(function (Request $request) use (&$range) {
        $range = $request['timeRange'];

        return true;
    });

    return $range;
}

it('reads --since and --until as local dates and sends them as UTC', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 12:00:00', 'UTC'));
    $this->freezeTime();
    $session = withMonitorSessionFile();
    Http::fake(['core.example.test/*' => Http::response(['data' => []])]);

    $this->artisan('digibee:logs', ['--since' => '03/11 14:00', '--until' => '2026-11-03 16:30'])
        ->expectsOutputToContain('between 03/11/2026 14:00 and 03/11/2026 16:30')
        ->assertSuccessful();

    expect(sentTimeRange())->toBe(['from' => '2026-11-03T17:00:00.000Z', 'to' => '2026-11-03T19:30:00.000Z']);

    unlink($session);
});

it('reads --until as how long ago, like --since', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 12:00:00', 'UTC'));
    $this->freezeTime();
    $session = withMonitorSessionFile();
    Http::fake(['core.example.test/*' => Http::response(['data' => []])]);

    $this->artisan('digibee:logs', ['--since' => '6h', '--until' => '2h'])->assertSuccessful();

    expect(sentTimeRange())->toBe(['from' => '2026-11-04T06:00:00.000Z', 'to' => '2026-11-04T10:00:00.000Z']);

    unlink($session);
});

it('reads a date without a year as the latest one that is not in the future', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-02 12:00:00', 'UTC'));
    $this->freezeTime();
    $session = withMonitorSessionFile();
    Http::fake(['core.example.test/*' => Http::response(['data' => []])]);

    $this->artisan('digibee:logs', ['--since' => '31/12 22:00', '--until' => '1/1 2:00'])->assertSuccessful();

    expect(sentTimeRange())->toBe(['from' => '2027-01-01T01:00:00.000Z', 'to' => '2027-01-01T05:00:00.000Z']);

    unlink($session);
});

it('refuses a window it cannot read, or one that ends before it starts, before calling anything', function (array $options, string $says) {
    $session = withMonitorSessionFile();
    Http::fake();

    $this->artisan('digibee:logs', $options)->expectsOutputToContain($says)->assertFailed();

    Http::assertNothingSent();
    unlink($session);
})->with([
    'a typo'            => [['--since' => '6hrs'], 'Cannot read "6hrs"'],
    'an impossible day' => [['--since' => '31/02 10:00'], 'Cannot read "31/02 10:00"'],
    'reversed'          => [['--since' => '1h', '--until' => '2h'], 'must be before --until'],
]);

it('parses the CSV export into rows keyed by its header', function () {
    Http::fake(['core.example.test/runtime/realms/leomadeiras/logs/export*' => Http::response(
        "\xEF\xBB\xBFtimestamp;logLevel;logMessage\n2026-11-04T10:18:41Z;INFO;\"Calling URL; with a separator\"\n2026-11-04T10:18:42Z;ERROR;boom\n",
    )]);

    $rows = (new DigibeeMonitorClient(monitorSession()))->executionLogs('prod', 'abc-123');

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toBe(['timestamp' => '2026-11-04T10:18:41Z', 'logLevel' => 'INFO', 'logMessage' => 'Calling URL; with a separator'])
        ->and($rows[1]['logLevel'])->toBe('ERROR');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'pipelineKey=abc-123')
        && str_contains($request->url(), 'separator=%3B'));
});

it('refuses an environment outside test and prod before calling anything', function () {
    Http::fake();

    expect(fn () => (new DigibeeMonitorClient(monitorSession()))->executionLogs('staging', 'k'))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

it('turns a 401 into the instruction to log in again', function () {
    Http::fake(['*' => Http::response(['message' => 'expired'], 401)]);

    expect(fn () => (new DigibeeMonitorClient(monitorSession()))->executionLogs('prod', 'k'))
        ->toThrow(DigibeeApiException::class, 'digibee:monitor:login');
});

it('can only reach the read routes it lists', function () {
    $client = new DigibeeMonitorClient(monitorSession());
    $call = (new ReflectionClass($client))->getMethod('call');

    expect(fn () => $call->invoke($client, 'DELETE', '/runtime/realms/{realm}/deployments', []))
        ->toThrow(DigibeeApiException::class, 'only calls the read routes');
});

it('reads the session expiry from the JWT without verifying it', function () {
    $this->freezeTime();

    expect(monitorSession(now()->addHour()->timestamp)->expired())->toBeFalse()
        ->and(monitorSession(now()->subMinute()->timestamp)->expired())->toBeTrue()
        ->and(monitorSession(now()->addSeconds(30)->timestamp)->expired())->toBeTrue();
});

it('masks the credentials and personal data seen in real production logs', function () {
    $line = 'LogConnector: Entrada pipeline - {"headers":{"Authorization":"Basic bGVvOnNlY3JldA==","X-Consumer-ID":"07f2"},'
        . '"customer":{"email":"someone@example.com","phone":"+5511999466944","document":"20335613802","name":"Erica"},'
        . '"token":"abc","CustomerRegistrationAddress":{"zipcode":"05707-001"}} Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.abcdefghij '
        . 'cpf 203.356.138-02 contato outro@empresa.com.br';

    $redacted = (new LogRedactor)->redact($line);

    expect($redacted)
        ->not->toContain('bGVvOnNlY3JldA==')
        ->not->toContain('someone@example.com')
        ->not->toContain('+5511999466944')
        ->not->toContain('20335613802')
        ->not->toContain('203.356.138-02')
        ->not->toContain('eyJhbGciOiJIUzI1NiJ9')
        ->not->toContain('outro@empresa.com.br')
        ->toContain('"Authorization":"[redacted]"')
        ->toContain('"token":"[redacted]"')
        // What makes a log worth reading stays: ids, names, structure.
        ->toContain('"X-Consumer-ID":"07f2"')
        ->toContain('"name":"Erica"');
});

it('masks keys inside JSON held in a JSON string, and numeric values', function () {
    // The shape of an execution payload: the response body is a JSON string.
    $payload = json_encode(['payload.response' => json_encode([
        'cl'      => ['document' => '56212410000150', 'phone' => '11964101358', 'firstName' => 'CONCEITO CASA'],
        'headers' => ['Authorization' => 'Basic c2VjcmV0'],
        'cpf'     => 20335613802,
        // Keys that only CONTAIN the word — measured in evt-insert-bq.
        'CpfCnpj'       => '09007432000107',
        'NumeroCelular' => 11987654321,
    ])]);

    $redacted = (new LogRedactor)->redact($payload);

    expect($redacted)
        ->not->toContain('56212410000150')
        ->not->toContain('11964101358')
        ->not->toContain('20335613802')
        ->not->toContain('c2VjcmV0')
        ->not->toContain('09007432000107')
        ->not->toContain('11987654321')
        ->toContain('CONCEITO CASA');
});

it('stays linear on the long blobs production payloads carry', function () {
    // A payload with a few hundred KB of base64 and no `@` — the shape that
    // made an unanchored e-mail rule quadratic.
    $blob = str_repeat(base64_encode(random_bytes(3000)), 100);

    $started = microtime(true);
    $redacted = (new LogRedactor)->redact('{"file":"' . $blob . '","to":"a@b.com"}');

    expect(microtime(true) - $started)->toBeLessThan(1.0)
        ->and($redacted)->toContain('[email]');
});

it('refuses to run on the server', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('digibee:logs')->expectsOutputToContain('workstation')->assertFailed();
    $this->artisan('digibee:monitor:login')->expectsOutputToContain('workstation')->assertFailed();
});

it('asks for a login when no session was captured', function () {
    $this->artisan('digibee:logs')->expectsOutputToContain('digibee:monitor:login')->assertFailed();
});

it('prints an execution\'s log lines redacted', function () {
    $path = sys_get_temp_dir() . '/monitor-session-' . uniqid() . '.json';
    file_put_contents($path, json_encode(['authorization' => 'Bearer ' . monitorJwt(now()->addDay()->timestamp), 'apikey' => 'k']));
    withMonitorConfig($path);

    Http::fake(['core.example.test/runtime/*' => Http::response(
        "timestamp;logLevel;logMessage\n2026-11-04T10:18:41Z;INFO;\"{\"\"Authorization\"\":\"\"Basic c2VjcmV0OnBhc3M=\"\"}\"\n",
    )]);

    $this->artisan('digibee:logs', ['--key' => 'abc'])
        ->expectsOutputToContain('[redacted]')
        ->doesntExpectOutputToContain('c2VjcmV0OnBhc3M=')
        ->assertSuccessful();

    unlink($path);
});
