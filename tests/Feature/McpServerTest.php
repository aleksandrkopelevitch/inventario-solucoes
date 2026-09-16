<?php

use App\Mcp\McpServer;
use App\Mcp\ToolRegistry;
use App\Models\McpToken;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/** A minted token's plaintext — the only way to get one. */
function mcpToken(): string
{
    return McpToken::mint('Test client')['plain'];
}

/** Posts one JSON-RPC message with a valid bearer token. */
function mcp(string $method, array $params = [], int|string|null $id = 1, ?string $token = null)
{
    $body = ['jsonrpc' => '2.0', 'method' => $method, 'params' => $params];

    if ($id !== null) {
        $body['id'] = $id;
    }

    return test()->postJson(route('mcp.handle'), $body, [
        'Authorization' => 'Bearer ' . ($token ?? mcpToken()),
    ]);
}

/** The decoded payload of a `tools/call` whose tool answers with JSON. */
function mcpCall(string $tool, array $arguments = [], ?string $token = null): array
{
    $response = mcp('tools/call', ['name' => $tool, 'arguments' => $arguments], token: $token);
    $response->assertOk();

    $result = $response->json('result');

    return [
        'isError' => $result['isError'],
        'text'    => $result['content'][0]['text'],
        'data'    => json_decode($result['content'][0]['text'], true),
        'content' => $result['content'],
    ];
}

/* ------------------------------------------------------------------ */
/*  Authentication */
/* ------------------------------------------------------------------ */

it('refuses a request with no credential, pointing at the OAuth metadata', function () {
    // The `resource_metadata` parameter is how a spec-aware client discovers
    // the authorization server and starts the flow — it is the difference
    // between a connector that asks somebody to sign in and one that asks for
    // a token. It was deliberately ABSENT before this app implemented OAuth.
    $this->postJson(route('mcp.handle'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertStatus(401)
        ->assertHeader('WWW-Authenticate', sprintf(
            'Bearer resource_metadata="%s", error="invalid_token"',
            route('mcp.oauth.protected-resource', ['path' => 'mcp']),
        ))
        ->assertJsonPath('error.code', -32001);
});

it('refuses an unknown token, including one shaped like ours', function () {
    // A token that exists in the table proves the lookup is by HASH: the
    // factory stores a hash whose plaintext it threw away, so the row is real
    // and this string still cannot open it.
    McpToken::factory()->create();

    foreach (['Bearer nope', 'Bearer ' . McpToken::PREFIX . str_repeat('a', 40), 'Basic abc', ''] as $header) {
        $this->postJson(route('mcp.handle'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Authorization' => $header])
            ->assertStatus(401);
    }
});

it('accepts the bearer scheme in any case', function () {
    // RFC 6750 makes the scheme case-insensitive, and `Request::bearerToken()`
    // does not — a client sending `bearer ` would be refused for a reason
    // nobody could see from the outside.
    $this->postJson(route('mcp.handle'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], [
        'Authorization' => 'bearer ' . mcpToken(),
    ])->assertOk();
});

it('stores the token hashed and never the plaintext', function () {
    ['token' => $token, 'plain' => $plain] = McpToken::mint('Claude Desktop');

    expect($token->token_hash)->toBe(hash('sha256', $plain))
        ->and($token->token_hash)->not->toBe($plain)
        ->and($token->last_four)->toBe(substr($plain, -4))
        ->and($plain)->toStartWith(McpToken::PREFIX);

    // Nothing anywhere in the row carries the readable value.
    expect(json_encode($token->getAttributes()))->not->toContain($plain);
});

it('records that a token was used', function () {
    ['token' => $token, 'plain' => $plain] = McpToken::mint('Claude');
    expect($token->last_used_at)->toBeNull();

    mcp('ping', token: $plain)->assertOk();

    expect($token->fresh()->last_used_at)->not->toBeNull();
});

it('is not in the web group, so it needs no CSRF token or session', function () {
    // Asserted through BEHAVIOUR rather than through the middleware list: a
    // route that names a group answers `['mcp']` to every introspection method
    // the router offers, and the groups themselves are only assembled once the
    // HTTP kernel boots — so a wiring assertion here would pass on a route that
    // had been silently put back in `web`.
    //
    // What "not in `web`" actually means is these two things. The POST below
    // carries no CSRF token and no session cookie and still succeeds, which
    // `VerifyCsrfToken` would refuse with a 419; and the answer starts no
    // session of its own, which `StartSession` always does.
    $route = app('router')->getRoutes()->getByName('mcp.handle');
    expect($route->gatherMiddleware())->toBe(['mcp']);

    $response = $this->withoutMiddleware([])->postJson(
        route('mcp.handle'),
        ['jsonrpc'       => '2.0', 'id' => 1, 'method' => 'ping'],
        ['Authorization' => 'Bearer ' . mcpToken()],
    )->assertOk();

    $sessionCookie = config('session.cookie');
    expect(collect($response->headers->getCookies())->pluck('name'))->not->toContain($sessionCookie);
});

/* ------------------------------------------------------------------ */
/*  Protocol */
/* ------------------------------------------------------------------ */

it('answers initialize with the protocol the client asked for', function () {
    foreach (McpServer::SUPPORTED_PROTOCOLS as $version) {
        mcp('initialize', ['protocolVersion' => $version])
            ->assertOk()
            ->assertJsonPath('result.protocolVersion', $version);
    }
});

it('falls back to a shipping protocol rather than the newest one', function () {
    // Answering an unknown request with the NEWEST revision would hand a
    // 2024-era client a version that removed the transport it is holding.
    mcp('initialize', ['protocolVersion' => '1999-01-01'])
        ->assertOk()
        ->assertJsonPath('result.protocolVersion', McpServer::DEFAULT_PROTOCOL);
});

it('declares tools and nothing else, and instructs the model', function () {
    $result = mcp('initialize')->assertOk()->json('result');

    expect($result['capabilities'])->toHaveKey('tools')
        ->and($result['capabilities'])->not->toHaveKey('resources')
        ->and($result['capabilities'])->not->toHaveKey('prompts')
        // The one instruction a model must not get wrong about this server.
        ->and($result['instructions'])->toContain('PUBLICADOS');
});

it('answers a notification with 202 and no body', function () {
    // `notifications/initialized` is sent by every client right after
    // `initialize`; a JSON-RPC notification must never be answered.
    $this->postJson(route('mcp.handle'), ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], [
        'Authorization' => 'Bearer ' . mcpToken(),
    ])->assertStatus(202)->assertNoContent(202);
});

it('lists every tool as read-only with a schema', function () {
    $tools = mcp('tools/list')->assertOk()->json('result.tools');

    expect($tools)->toHaveCount(count(ToolRegistry::TOOLS));

    foreach ($tools as $tool) {
        expect($tool['name'])->toMatch('/^[a-z_]+$/')
            ->and($tool['description'])->not->toBeEmpty()
            ->and($tool['inputSchema']['type'])->toBe('object')
            ->and($tool['annotations']['readOnlyHint'])->toBeTrue();
    }
});

it('refuses an unknown method and an unknown tool differently', function () {
    // An unknown METHOD is a protocol error: the client is broken and the model
    // cannot fix it. An unknown TOOL is a result the model reads and recovers from.
    mcp('resources/list')->assertOk()->assertJsonPath('error.code', -32601);

    mcp('tools/call', ['name' => 'delete_everything'])
        ->assertOk()
        ->assertJsonPath('error.code', -32602);
});

it('refuses a JSON-RPC batch rather than half-implementing it', function () {
    $this->postJson(route('mcp.handle'), [
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
        ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'],
    ], ['Authorization' => 'Bearer ' . mcpToken()])
        ->assertStatus(400)
        ->assertJsonPath('error.code', -32600);
});

it('answers GET with 405 rather than 404', function () {
    // A 404 on the connector URL reads to whoever is configuring it as a wrong
    // address; 405 says "right place, this server sends no SSE stream".
    $this->get(route('mcp.stream'), ['Authorization' => 'Bearer ' . mcpToken()])
        ->assertStatus(405)
        ->assertHeader('Allow', 'POST');
});

it('never lets a JSON answer be cached', function () {
    mcp('ping')->assertHeader('Cache-Control', 'no-store, private');
});
