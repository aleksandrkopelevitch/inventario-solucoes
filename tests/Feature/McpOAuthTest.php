<?php

use App\Enums\UserRole;
use App\Mcp\OAuth;
use App\Models\McpToken;
use App\Models\User;
use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

uses(LazilyRefreshDatabase::class);

function oauthUser(UserRole $role = UserRole::Viewer): User
{
    return User::factory()->create(['role' => $role->value]);
}

/** One JSON-RPC message, sent as an account rather than as a token. */
function mcpAs(User $user, string $method, array $params = [])
{
    Passport::actingAs($user, [OAuth::SCOPE]);

    return test()->postJson(route('mcp.handle'), [
        'jsonrpc' => '2.0',
        'id'      => 1,
        'method'  => $method,
        'params'  => $params,
    ]);
}

/* ------------------------------------------------------------------ */
/*  Discovery — what a client reads before it has any credential */
/* ------------------------------------------------------------------ */

it('publishes both discovery documents, with and without the resource path', function () {
    // RFC 9728 tells a client holding `https://host/mcp` to ask for the
    // path-suffixed form; several shipping clients ask for the bare one and
    // report "could not connect" on a 404 rather than trying the other.
    foreach (['', 'mcp'] as $path) {
        $this->getJson(route('mcp.oauth.protected-resource', ['path' => $path]))
            ->assertOk()
            ->assertJsonPath('resource', route('mcp.handle'))
            ->assertJsonPath('authorization_servers.0', url('/'))
            ->assertJsonPath('scopes_supported.0', OAuth::SCOPE);

        $this->getJson(route('mcp.oauth.authorization-server', ['path' => $path]))
            ->assertOk()
            ->assertJsonPath('authorization_endpoint', route('passport.authorizations.authorize'))
            ->assertJsonPath('token_endpoint', route('passport.token'))
            ->assertJsonPath('registration_endpoint', route('mcp.oauth.register'))
            // PKCE, S256 only: `plain` lets anything that can see the request
            // mint the verifier, and every MCP client can hash.
            ->assertJsonPath('code_challenge_methods_supported.0', 'S256');
    }
});

/* ------------------------------------------------------------------ */
/*  Dynamic client registration */
/* ------------------------------------------------------------------ */

it('registers a client for an allowed redirect and refuses any other', function () {
    config(['mcp.redirect_origins' => ['https://claude.ai']]);

    $this->postJson(route('mcp.oauth.register'), [
        'client_name'   => 'Claude',
        'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
    ])
        ->assertCreated()
        ->assertJsonPath('token_endpoint_auth_method', 'none')
        ->assertJsonPath('scope', OAuth::SCOPE)
        ->assertJsonStructure(['client_id']);

    // Loopback is accepted with any port — a desktop client picks a free one at
    // runtime, so it cannot be written into the allowlist.
    $this->postJson(route('mcp.oauth.register'), ['redirect_uris' => ['http://localhost:57219/callback']])
        ->assertCreated();

    // The allowlist is the entire security of an endpoint that, by design,
    // anybody may call: without it, registering a client that redirects
    // somebody's authorization code elsewhere is a POST away.
    $this->postJson(route('mcp.oauth.register'), ['redirect_uris' => ['https://attacker.example/cb']])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_redirect_uri');
});

it('answers a malformed registration in the RFC 7591 shape, not the app shape', function () {
    // A connector dialog prints `error_description` and nothing else — the
    // app's own ValidationException envelope would reach the person as a blank
    // failure.
    $this->postJson(route('mcp.oauth.register'), [])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_client_metadata')
        ->assertJsonStructure(['error', 'error_description'])
        ->assertJsonMissingPath('errors');
});

/* ------------------------------------------------------------------ */
/*  The whole handshake, end to end */
/* ------------------------------------------------------------------ */

it('takes a client from registration to a working access token', function () {
    config(['mcp.redirect_origins' => ['https://claude.ai']]);

    $redirect = 'https://claude.ai/api/mcp/auth_callback';
    $clientId = $this->postJson(route('mcp.oauth.register'), [
        'client_name'   => 'Claude',
        'redirect_uris' => [$redirect],
    ])->assertCreated()->json('client_id');

    $verifier = str_repeat('a', 64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    // The consent screen, as the person signing in sees it.
    $user = oauthUser(UserRole::Viewer);
    $authorize = $this->actingAs($user)->get(route('passport.authorizations.authorize', [
        'client_id'             => $clientId,
        'redirect_uri'          => $redirect,
        'response_type'         => 'code',
        'scope'                 => OAuth::SCOPE,
        'state'                 => 'xyz',
        'code_challenge'        => $challenge,
        'code_challenge_method' => 'S256',
    ]));

    $authorize->assertOk()->assertSee('Autorizar')->assertSee('Claude');

    $approved = $this->post(route('passport.authorizations.approve'), [
        'state'      => 'xyz',
        'client_id'  => $clientId,
        'auth_token' => session('authToken'),
    ]);

    $approved->assertRedirectContains($redirect);
    parse_str((string) parse_url($approved->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query)->toHaveKey('code');

    $token = $this->post(route('passport.token'), [
        'grant_type'    => 'authorization_code',
        'client_id'     => $clientId,
        'redirect_uri'  => $redirect,
        'code_verifier' => $verifier,
        'code'          => $query['code'],
    ])->assertOk()->json('access_token');

    // And the point of all of it: that string opens the MCP endpoint.
    $this->postJson(route('mcp.handle'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], [
        'Authorization' => 'Bearer ' . $token,
    ])->assertOk()->assertJsonPath('result.tools.0.name', 'search_solutions');
});

/* ------------------------------------------------------------------ */
/*  What a connection reaches — the app's own answer, per account */
/* ------------------------------------------------------------------ */

it('gives an account exactly what its role reaches in the app', function () {
    $all = collect(mcpAs(oauthUser(UserRole::Viewer), 'tools/list')->assertOk()->json('result.tools'))
        ->pluck('name');

    expect($all)->toContain('search_solutions', 'search_people', 'list_notebooks');

    // A Reader — the tier Entra SSO provisions, which in the browser reaches
    // `/docs` and nothing else. Signing in is a door every Leo account already
    // has, so a connector that handed this account the catalog would be a hole
    // in the gate the app already enforces.
    $reader = collect(mcpAs(oauthUser(UserRole::Reader), 'tools/list')->assertOk()->json('result.tools'))
        ->pluck('name');

    expect($reader->all())->toBe(['list_notebooks', 'get_notebook', 'get_documentation_page', 'search_documentation']);
});

it('refuses a catalog tool to a reader, as an unknown tool', function () {
    // The same sentence a typo gets, and deliberately: a tool the account was
    // never shown is a tool that does not exist for it.
    mcpAs(oauthUser(UserRole::Reader), 'tools/call', ['name' => 'search_solutions', 'arguments' => []])
        ->assertOk()
        ->assertJsonPath('error.code', -32602);
});

it('tells a reader what the connection covers, in the instructions', function () {
    $instructions = mcpAs(oauthUser(UserRole::Reader), 'initialize')->assertOk()->json('result.instructions');

    // A model that cannot SEE the catalog tools does not conclude "não tenho
    // acesso"; it concludes the catalog is empty, and reports that to somebody.
    expect($instructions)->toContain('SOMENTE a documentação publicada');
});

it('keeps a minted token on the full read, since it is not a tier', function () {
    $plain = McpToken::mint('APLA')['plain'];

    $tools = collect($this->postJson(route('mcp.handle'), [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list',
    ], ['Authorization' => 'Bearer ' . $plain])->assertOk()->json('result.tools'))->pluck('name');

    expect($tools)->toHaveCount(12);
});

/* ------------------------------------------------------------------ */
/*  The connect screen */
/* ------------------------------------------------------------------ */

it('opens for every signed-in tier, including the one kept out of the inventory', function () {
    foreach ([UserRole::Admin, UserRole::Writer, UserRole::Viewer, UserRole::Reader] as $role) {
        $this->actingAs(oauthUser($role))->get(route('mcp.connect'))->assertOk()->assertSee(route('mcp.handle'));
    }

    auth()->logout();
    $this->get(route('mcp.connect'))->assertRedirect(route('login.create'));
});

it('offers the token screen only to an admin', function () {
    $this->actingAs(oauthUser(UserRole::Admin))->get(route('mcp.connect'))
        ->assertSee(route('mcp-tokens.index'));

    $this->actingAs(oauthUser(UserRole::Viewer))->get(route('mcp.connect'))
        ->assertDontSee(route('mcp-tokens.index'));
});

it('revokes one connection, and only the account own', function () {
    $user = oauthUser(UserRole::Viewer);
    $other = oauthUser(UserRole::Viewer);

    $client = app(ClientRepository::class)
        ->createAuthorizationCodeGrantClient('Claude', ['https://claude.ai/cb'], false);

    $token = Passport::token()->forceFill([
        'id'         => Str::random(40),
        'user_id'    => $user->getKey(),
        'client_id'  => $client->getKey(),
        'scopes'     => [OAuth::SCOPE],
        'revoked'    => false,
        'expires_at' => now()->addWeek(),
    ]);
    $token->save();

    // Somebody else's connection is NOT FOUND rather than refused — the lookup
    // goes through the account's own relation, which cannot be got wrong later.
    $this->actingAs($other)->deleteJson(route('mcp.connections.destroy', $token->getKey()))
        ->assertNotFound();

    $this->actingAs($user)->deleteJson(route('mcp.connections.destroy', $token->getKey()))
        ->assertOk()
        ->assertJsonPath('message', 'Conexão revogada.');

    expect($token->fresh()->revoked)->toBeTrue();
});

/* ------------------------------------------------------------------ */
/*  When the OAuth half is broken */
/* ------------------------------------------------------------------ */

it('still answers 401 when the guard itself cannot run', function () {
    // The production incident this test exists for: with Passport's signing keys
    // absent from the server, league/oauth2-server throws while merely looking at
    // the request, and the anonymous probe every connector sends came back 500.
    // A client reads a 500 as "not an MCP server" and gives up before it ever
    // sees the 401 that tells it where to sign in.
    Auth::extend('exploding', fn () => new class implements Guard
    {
        use GuardHelpers;

        public function user(): never
        {
            throw new LogicException('Invalid key supplied');
        }

        public function validate(array $credentials = []): bool
        {
            return false;
        }
    });
    config(['auth.guards.api.driver' => 'exploding']);

    $this->postJson(route('mcp.handle'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertStatus(401)
        // And it still points at the metadata, which is the whole content of
        // that answer: "sign in over there".
        ->assertHeader('WWW-Authenticate', sprintf(
            'Bearer resource_metadata="%s", error="invalid_token"',
            route('mcp.oauth.protected-resource', ['path' => 'mcp']),
        ))
        ->assertJsonPath('error.code', -32001);
});

it('keeps the token half working while the OAuth half is broken', function () {
    // The two credentials are independent: a minted token is checked BEFORE the
    // guard is ever asked, so a missing key cannot take the programs down with it.
    Auth::extend('exploding2', fn () => new class implements Guard
    {
        use GuardHelpers;

        public function user(): never
        {
            throw new LogicException('Invalid key supplied');
        }

        public function validate(array $credentials = []): bool
        {
            return false;
        }
    });
    config(['auth.guards.api.driver' => 'exploding2']);

    $this->postJson(route('mcp.handle'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], [
        'Authorization' => 'Bearer ' . McpToken::mint('APLA')['plain'],
    ])->assertOk();
});
