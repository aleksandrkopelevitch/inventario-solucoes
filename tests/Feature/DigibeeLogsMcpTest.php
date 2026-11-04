<?php

use App\Mcp\JsonRpc;
use App\Support\Digibee\Monitor\Mcp\LogsMcpServer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The local Digibee logs MCP server: stdio framing, the four tools, and what
 * they must never let into a chat — an unmasked credential, a path outside
 * the reports directory, a call without a session.
 *
 * Helpers carry a `digibeeMcp` prefix: McpServerTest already declares an
 * `mcpCall()`, and Pest functions are
 * global, and two files declaring one name is a fatal error.
 */
function digibeeMcpJwt(int $exp): string
{
    $part = fn (array $data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

    return $part(['alg' => 'HS256']) . '.' . $part(['realm' => 'leomadeiras', 'exp' => $exp]) . '.signature';
}

function digibeeMcpSetup(bool $withSession = true): void
{
    $storage = sys_get_temp_dir() . '/digibee-mcp-' . uniqid();
    mkdir($storage . '/app/private', 0700, true);
    app()->useStoragePath($storage);

    $session = $storage . '/session.json';

    if ($withSession) {
        file_put_contents($session, json_encode(['authorization' => 'Bearer ' . digibeeMcpJwt(now()->addDay()->timestamp), 'apikey' => 'k']));
    }

    config()->set('app.display_timezone', 'America/Sao_Paulo');
    config()->set('services.digibee.monitor', [
        'endpoint'     => 'https://core.example.test',
        'realm'        => 'leomadeiras',
        'timeout'      => 5,
        'session_path' => $session,
    ]);
}

/** @return array<string, mixed> */
function digibeeMcpCall(string $tool, array $arguments = []): array
{
    return app(LogsMcpServer::class)->handle([
        'jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call',
        'params'  => ['name' => $tool, 'arguments' => $arguments],
    ]);
}

function digibeeMcpText(array $response): string
{
    return $response['result']['content'][0]['text'];
}

beforeEach(function () {
    Http::preventStrayRequests();
    digibeeMcpSetup();
});

it('speaks newline-delimited JSON-RPC on stdio and answers nothing to a notification', function () {
    $in = fopen('php://memory', 'r+');
    fwrite($in, json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]) . "\n");
    fwrite($in, json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']) . "\n");
    fwrite($in, "this is not json\n");
    fwrite($in, json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']) . "\n");
    rewind($in);
    $out = fopen('php://memory', 'r+');

    app(LogsMcpServer::class)->serve($in, $out);

    rewind($out);
    $responses = array_map(fn (string $line) => json_decode($line, true), array_filter(explode("\n", stream_get_contents($out))));

    expect($responses)->toHaveCount(3)
        ->and($responses[0]['result']['protocolVersion'])->toBe('2025-06-18')
        ->and($responses[0]['result']['instructions'])->toContain('mascarados')
        ->and($responses[1]['error']['code'])->toBe(JsonRpc::PARSE_ERROR)
        ->and(array_column($responses[2]['result']['tools'], 'name'))->toBe([
            'count_digibee_executions', 'search_digibee_logs', 'query_digibee_report', 'get_digibee_execution',
        ])
        ->and(collect($responses[2]['result']['tools'])->every(fn (array $tool) => $tool['annotations']['readOnlyHint'] === true))->toBeTrue();
});

it('counts both doors without opening anything, and says a capped total is a floor', function () {
    Http::fake([
        'core.example.test/monitor/api/v1/pipeline-logs*' => Http::response(['data' => [], 'total' => 10000]),
        'core.example.test/monitor/api/v1/executions*'    => Http::response(['data' => [], 'total' => 64]),
    ]);

    $answer = json_decode(digibeeMcpText(digibeeMcpCall('count_digibee_executions', ['term' => '5000321', 'since' => '03/11 14:00', 'until' => '03/11 16:00'])), true);

    expect($answer['lines'])->toBe('10000+')
        ->and($answer['payloads'])->toBe(64)
        ->and($answer['window']['from'])->toStartWith('03/11/');

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/logs/export'));
});

it('collects both doors into one report, opens each execution once, and answers a redacted digest', function () {
    $line = fn (string $key, string $body) => ['attributes' => ['pipeline.name' => 'evt-send-estoque-vtex', 'pipeline.version' => '1.1', 'pipeline.key' => $key], 'body' => $body];
    $execution = fn (string $key) => ['attributes' => [
        'pipeline.name'             => 'evt-send-estoque-vtex', 'pipeline.version' => '1.1', 'pipeline.key' => $key,
        'execution.elapsed_time_ms' => 400, 'payload.request' => '{"SKU":"5000321","Authorization":"Basic c2VjcmV0OnBhc3M="}',
        'payload.response'          => '{"code":200}',
    ]];
    $csv = fn (string $order) => "timestamp;logLevel;logMessage\n"
        . "2026-11-03T17:00:02Z;ERROR;Timeout on order {$order} after 30000 ms\n"
        . "2026-11-03T17:00:01Z;INFO;URL https://leob2c.vtexcommercestable.com.br:443/api/logistics/pvt/inventory/skus/5000321/warehouses/1001 responded with status code 200. Took: 378\n";

    Http::fake([
        'core.example.test/monitor/api/v1/pipeline-logs*' => Http::response(['data' => [$line('a', 'sku 5000321'), $line('a', 'again 5000321')], 'pagination' => ['hasNext' => false], 'total' => 2]),
        'core.example.test/monitor/api/v1/executions*'    => Http::response(['data' => [$execution('a'), $execution('b')], 'pagination' => ['hasNext' => false], 'total' => 2]),
        'core.example.test/runtime/*pipelineKey=a*'       => Http::response($csv('111')),
        'core.example.test/runtime/*pipelineKey=b*'       => Http::response($csv('222')),
    ]);

    $text = digibeeMcpText(digibeeMcpCall('search_digibee_logs', ['term' => '5000321', 'pipeline' => 'evt-send-estoque-vtex', 'since' => '1d']));
    $answer = json_decode($text, true);

    expect($text)->not->toContain('c2VjcmV0OnBhc3M=')
        ->and($answer['report'])->toStartWith('mcp-')->not->toContain('5000321')
        ->and($answer['coverage']['executions_found'])->toBe(2)
        ->and($answer['by_pipeline'][0])->toMatchArray(['pipeline' => 'evt-send-estoque-vtex', 'executions' => 2, 'with_error' => 2])
        // Two orders, one message: grouped by shape.
        ->and($answer['messages']['distinct'])->toBe(1)
        ->and($answer['messages']['top'][0])->toMatchArray(['level' => 'ERROR', 'count' => 2, 'executions' => 2])
        ->and($answer['http_calls'][0])->toMatchArray([
            'target' => 'https://leob2c.vtexcommercestable.com.br:443/api/logistics/pvt/inventory/skus/{id}/warehouses/{id}',
            'status' => 200,
            'count'  => 2,
        ]);

    // Each execution exported once, though both searches found "a".
    Http::assertSentCount(4);

    $path = storage_path('app/private/digibee-monitor/reports/' . $answer['report']);
    expect(decoct(fileperms($path) & 0777))->toBe('600')
        ->and(file_get_contents($path))->not->toContain('c2VjcmV0OnBhc3M=');

    // And the saved report answers a drill-down without calling Digibee.
    Http::fake();
    $query = json_decode(digibeeMcpText(digibeeMcpCall('query_digibee_report', ['report' => $answer['report'], 'text' => 'order 222', 'full' => true])), true);

    expect($query['matching'])->toBe(1)
        ->and($query['executions'][0]['key'])->toBe('b');
    Http::assertNothingSent();
});

it('refuses a search with neither a pipeline nor a term', function () {
    Http::fake();

    $response = digibeeMcpCall('search_digibee_logs', ['since' => '1h']);

    expect($response['result']['isError'])->toBeTrue()
        ->and(digibeeMcpText($response))->toContain('pipeline');
    Http::assertNothingSent();
});

it('tells the model to ask the person to sign in when there is no session', function () {
    digibeeMcpSetup(withSession: false);
    Http::fake();

    $response = digibeeMcpCall('get_digibee_execution', ['key' => 'abc']);

    expect($response['result']['isError'])->toBeTrue()
        ->and(digibeeMcpText($response))->toContain('digibee:monitor:login');
    Http::assertNothingSent();
});

it('sends the person to sign in whenever Digibee cannot be reached, whatever the failure', function (string $failure) {
    // Built here, not in the dataset: with the fakes as dataset closures,
    // every case ended in a StrayRequestException.
    Http::fake(['*' => match ($failure) {
        'expired' => Http::response(['message' => 'expired'], 401),
        'refused' => Http::response(['code' => 'INSUFFICIENT_PERMISSIONS'], 403),
        'offline' => fn () => throw new ConnectionException('cURL error 28: Resolving timed out'),
    }]);

    $response = digibeeMcpCall('count_digibee_executions', ['pipeline' => 'p']);

    expect($response['result']['isError'])->toBeTrue()
        ->and(digibeeMcpText($response))->toContain('Não consegui falar com a Digibee')
        ->toContain('php artisan digibee:monitor:login');
})->with([
    'a session that died mid-day' => 'expired',
    'a refusal'                   => 'refused',
    'no connection'               => 'offline',
]);

it('reads a report by its bare name only', function (string $name) {
    $response = digibeeMcpCall('query_digibee_report', ['report' => $name]);

    expect($response['error']['code'])->toBe(JsonRpc::INVALID_PARAMS);
})->with(['../../.env', '/etc/passwd', 'reports/../x.json', '..json']);

it('refuses an unreadable window before calling anything', function () {
    Http::fake();

    $response = digibeeMcpCall('count_digibee_executions', ['pipeline' => 'p', 'since' => 'ontem']);

    expect($response['result']['isError'])->toBeTrue()
        ->and(digibeeMcpText($response))->toContain('Cannot read "ontem"');
    Http::assertNothingSent();
});

it('refuses to serve on the server', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('digibee:mcp')->assertFailed();
});
