<?php

namespace App\Http\Middleware;

use App\Models\McpToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The whole authentication of the MCP server: one bearer token, checked against
 * `mcp_tokens`.
 *
 * MCP's own authorization spec is OAuth 2.1 with dynamic client registration and
 * a protected-resource metadata document. That is the right answer for a server
 * strangers connect to, and the wrong one here: the clients are three chat apps
 * configured by hand, by the admin who minted the token, inside Leo. What OAuth
 * would buy — a consent screen, per-user identity, revocable grants — is bought
 * more plainly by a named token an admin deletes.
 *
 * So the 401 deliberately carries a BARE `WWW-Authenticate: Bearer`, with no
 * `resource_metadata` parameter. That is not an omission: the parameter is how a
 * spec-aware client discovers an authorization server and starts the OAuth
 * dance, and advertising a flow this app does not implement turns a legible
 * "your token is wrong" into a client hanging on a discovery document that 404s.
 *
 * The body is JSON-RPC rather than the app's Toast shape (`{message, title,
 * type}`), which every other JSON error in this app uses. Nothing here is read
 * by `ajax-post.js`; it is read by an MCP client, and the one thing that gives
 * a person a legible error in Claude's connector panel is an `error.message` at
 * the top level of a JSON-RPC envelope.
 */
class AuthenticateMcpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = McpToken::findByPlaintext($this->bearer($request));

        if (! $token) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id'      => null,
                'error'   => [
                    // -32001 rather than a JSON-RPC reserved code: the reserved
                    // range describes malformed messages, and this message was
                    // perfectly well formed. The HTTP status is what actually
                    // says "unauthenticated"; this is what a person reads.
                    'code'    => -32001,
                    'message' => 'Token MCP ausente ou inválido. Envie o cabeçalho "Authorization: Bearer <token>".',
                ],
            ], 401, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)->header('WWW-Authenticate', 'Bearer');
        }

        $token->touchLastUsed();

        // Bound so a tool can name the credential in a log line without going
        // back to the header. Nothing authorizes off it — what an MCP token may
        // read is fixed (see `App\Models\McpToken`), so there is no per-token
        // decision for anything downstream to make.
        $request->attributes->set('mcp_token', $token);

        return $next($request);
    }

    /**
     * The bearer value, accepted case-insensitively on the scheme.
     *
     * `Request::bearerToken()` would do, except that it matches `Bearer ` with
     * exact case; clients that send `bearer ` are refused by it for a reason
     * nobody can see from the outside, and RFC 6750 makes the scheme
     * case-insensitive.
     */
    private function bearer(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');

        return preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m) ? $m[1] : null;
    }
}
