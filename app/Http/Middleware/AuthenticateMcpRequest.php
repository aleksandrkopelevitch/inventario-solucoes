<?php

namespace App\Http\Middleware;

use App\Mcp\Actor;
use App\Models\McpToken;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The whole authentication of the MCP server — two credentials, one `Actor`.
 *
 * **A minted bearer token** (`McpToken`) is the credential for a PROGRAM: Claude
 * Code, a script, anything with no browser to consent in. It is checked first,
 * and cheaply — the prefix says whether a string is even one of ours before the
 * database is asked.
 *
 * **An OAuth access token** is the credential for a PERSON, issued by this app
 * acting as its own authorization server (Passport, see `routes/oauth.php`). It
 * exists because of who the connector is actually for: a colleague who is not
 * going to install Node, edit a JSON file, or be sent a 49-character secret over
 * Teams. They paste one URL into the connector dialog and sign in.
 *
 * Two things this file gets to decide, and both were decided the other way
 * before OAuth existed:
 *
 * - **The 401 now carries `resource_metadata`.** A bare `WWW-Authenticate:
 *   Bearer` was correct while there was no flow to discover; with one, the
 *   parameter is exactly how a client finds it, and leaving it off is what makes
 *   a connector dialog ask for a token nobody should have to paste. The
 *   `error_description` still says the human sentence, because a client that
 *   cannot start the flow prints it.
 * - **The message names both doors.** "Entre com sua conta Leo" is the answer
 *   for a person; "ou envie um token" is the answer for the script whose token
 *   was deleted. One of the two readers is always the wrong one, so both are
 *   addressed in one line rather than neither.
 *
 * The body stays JSON-RPC rather than the app's Toast shape (`{message, title,
 * type}`), which every other JSON error in this app uses. Nothing here is read
 * by `ajax-post.js`; it is read by an MCP client, and the one thing that gives a
 * person a legible error in a connector panel is an `error.message` at the top
 * level of a JSON-RPC envelope.
 */
class AuthenticateMcpRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $actor = $this->resolve($request);

        if (! $actor) {
            return $this->unauthenticated();
        }

        $actor->token?->touchLastUsed();

        // Bound so the rate limiter can key on the credential and a tool can
        // name it in a log line without going back to the header.
        $request->attributes->set('mcp_actor', $actor);

        // The OAuth half authenticates a real account, so the rest of the
        // request may use it: `auth()->user()` is how every policy in this app
        // asks who is calling, and a request that has a user and hides it is a
        // request where the next feature silently behaves as a guest.
        if ($actor->user) {
            Auth::setUser($actor->user);
        }

        return $next($request);
    }

    private function resolve(Request $request): ?Actor
    {
        if ($token = McpToken::findByPlaintext($this->bearer($request))) {
            return Actor::fromToken($token);
        }

        // Passport's guard parses and verifies the JWT; anything that is not one
        // — a deleted token, a typo, a `isol_mcp_` string that matched no row —
        // simply answers null, which is the same 401 either way. Asked
        // unconditionally rather than only when a header is present, because the
        // guard is the one thing that knows what it accepts.
        //
        // The try/catch is not defensive habit, it is a production incident: with
        // Passport's signing keys absent from the server, league/oauth2-server
        // throws `LogicException: Invalid key supplied` while merely LOOKING at
        // the request — so an anonymous probe, the first thing any connector
        // sends, came back 500. A client reads that as "this is not an MCP
        // server" and stops; it never reaches the 401 that would have told it
        // where to sign in. A credential this server cannot verify is not a
        // credential, and the honest answer is the same 401 an invalid one gets,
        // with the operator's half of the problem written to the log.
        try {
            $user = Auth::guard('api')->user();
        } catch (Throwable $e) {
            Log::error('MCP OAuth guard unavailable — is the Passport key installed?', ['exception' => $e]);

            return null;
        }

        return $user instanceof User ? Actor::fromUser($user) : null;
    }

    private function unauthenticated(): Response
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id'      => null,
            'error'   => [
                // -32001 rather than a JSON-RPC reserved code: the reserved
                // range describes malformed messages, and this message was
                // perfectly well formed. The HTTP status is what actually
                // says "unauthenticated"; this is what a person reads.
                'code'    => -32001,
                'message' => 'Não autenticado. Entre com sua conta Leo pelo próprio conector, ou envie um token MCP no cabeçalho "Authorization: Bearer <token>".',
            ],
        ], 401, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ->header('WWW-Authenticate', sprintf(
                'Bearer resource_metadata="%s", error="invalid_token"',
                route('mcp.oauth.protected-resource', ['path' => 'mcp']),
            ));
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
