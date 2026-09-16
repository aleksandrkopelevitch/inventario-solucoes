<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Mcp\OAuth;
use Illuminate\Http\JsonResponse;

/**
 * The two documents an MCP client reads BEFORE it has any credential — how a
 * connector goes from a pasted URL to a sign-in screen without anybody typing a
 * client id.
 *
 * `/.well-known/oauth-protected-resource` (RFC 9728) is what the 401 points at:
 * it names the authorization server for this resource. `/.well-known/
 * oauth-authorization-server` (RFC 8414) is that server describing itself —
 * where to send the person, where to exchange the code, and where a client may
 * register itself.
 *
 * **Both are served with and without a path suffix, and that is not belt and
 * braces.** RFC 9728 tells a client holding `https://host/mcp` to ask for
 * `/.well-known/oauth-protected-resource/mcp`; several shipping clients ask for
 * the bare path instead, and one that gets a 404 gives up with "could not
 * connect" rather than trying the other. Two routes cost nothing and remove the
 * single most common way this handshake fails.
 *
 * They are PUBLIC on purpose — a document that required a credential could only
 * be read by a client that already had one, which is the opposite of what it is
 * for.
 */
class OAuthDiscoveryController extends Controller
{
    /** RFC 9728 — this resource and the server that guards it. */
    public function protectedResource(): JsonResponse
    {
        return $this->json([
            'resource'                 => route('mcp.handle'),
            'authorization_servers'    => [url('/')],
            'scopes_supported'         => [OAuth::SCOPE],
            'bearer_methods_supported' => ['header'],
        ]);
    }

    /** RFC 8414 — the authorization server Passport actually implements. */
    public function authorizationServer(): JsonResponse
    {
        return $this->json([
            'issuer'                   => url('/'),
            'authorization_endpoint'   => route('passport.authorizations.authorize'),
            'token_endpoint'           => route('passport.token'),
            'registration_endpoint'    => route('mcp.oauth.register'),
            'scopes_supported'         => [OAuth::SCOPE],
            'response_types_supported' => ['code'],
            'grant_types_supported'    => ['authorization_code', 'refresh_token'],
            // S256 and nothing else. `plain` is still in the spec for clients
            // that cannot hash; every MCP client can, and offering it lets a
            // proxy that sees the request also mint the verifier.
            'code_challenge_methods_supported' => ['S256'],
            // The clients here are public (a desktop app cannot keep a secret),
            // so they authenticate at the token endpoint with PKCE alone.
            'token_endpoint_auth_methods_supported' => ['none'],
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): JsonResponse
    {
        // Cacheable, briefly: these documents change only when this app is
        // redeployed, and a client re-reads them on every reconnect.
        return response()
            ->json($payload, 200, [], JSON_UNESCAPED_SLASHES)
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
