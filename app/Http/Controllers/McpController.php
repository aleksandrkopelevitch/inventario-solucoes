<?php

namespace App\Http\Controllers;

use App\Mcp\Actor;
use App\Mcp\JsonRpc;
use App\Mcp\McpServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The MCP endpoint — the app's Streamable HTTP transport, and the only route
 * outside the `web` group.
 *
 * It is a transport and nothing else: it turns a POST body into an array, hands
 * it to `McpServer` and turns the answer back into a response. Anything that
 * knows what `tools/call` means lives there.
 *
 * **Plain JSON, never an SSE stream.** Streamable HTTP lets a server answer
 * either way, and the stream exists for a server that has to send progress
 * notifications or server-initiated requests while a call runs. Every tool here
 * is a database read that answers in one shot, so a stream would be a single
 * `data:` line in a connection somebody has to keep open — all of the
 * buffering-proxy problems and none of the benefit.
 *
 * **It does not extend the app's conventions and should not be made to.** No
 * `wantsJson()` dual response (§ Controller Response Pattern) — there is no HTML
 * half. No Form Request — validation answers in the app's Toast shape, which an
 * MCP client cannot read. No session, no CSRF token, no `web` middleware at all:
 * the caller is a program holding a bearer token, and CSRF protects a browser
 * that carries a cookie it did not choose to send.
 */
class McpController extends Controller
{
    public function __construct(private readonly McpServer $server) {}

    public function handle(Request $request): JsonResponse|Response
    {
        $message = $request->json()->all();

        // `json()->all()` answers `[]` for a body that is not JSON at all, so
        // the emptiness is checked against the raw content rather than trusted.
        if ($message === [] && trim($request->getContent()) === '') {
            return $this->json(JsonRpc::error(null, JsonRpc::INVALID_REQUEST, 'Corpo da requisição vazio.'), 400);
        }

        if (! is_array($message) || $message === []) {
            return $this->json(JsonRpc::error(null, JsonRpc::PARSE_ERROR, 'Corpo da requisição não é JSON válido.'), 400);
        }

        // A JSON-RPC BATCH — a bare array of messages. Removed from MCP in
        // 2025-06-18 and absent from every revision since, so it is refused
        // rather than half-implemented: a client sending one is a client whose
        // expectations about ordering and partial failure this server would not
        // meet. `array_is_list()` is the test, since a single message is always
        // a keyed object.
        if (array_is_list($message)) {
            return $this->json(
                JsonRpc::error(null, JsonRpc::INVALID_REQUEST, 'Lotes (batch) de JSON-RPC não são suportados. Envie uma mensagem por requisição.'),
                400,
            );
        }

        // Put there by `AuthenticateMcpRequest`; the route never runs without
        // it, so an absent one is a wiring mistake rather than a request to
        // answer politely.
        $actor = $request->attributes->get('mcp_actor');
        abort_unless($actor instanceof Actor, 401);

        $response = $this->server->handle($message, $actor);

        // A notification gets no body at all. 202 rather than 204 because the
        // spec names it, and because "aceito, não há resposta" is exactly what
        // it means — `notifications/initialized` is the one every client sends
        // right after `initialize`.
        if ($response === null) {
            return response()->noContent(202);
        }

        return $this->json($response);
    }

    /**
     * `GET /mcp` — the SSE stream a client MAY open to receive server-initiated
     * messages. This server never sends any (no subscriptions, no sampling, no
     * elicitation), and the spec's answer for exactly that case is 405.
     *
     * A real route rather than a fall-through, because the alternative is a 404
     * that reads to whoever is configuring the connector as a wrong URL.
     */
    public function stream(): Response
    {
        return response('', 405)->header('Allow', 'POST');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function json(array $payload, int $status = 200): JsonResponse
    {
        // `no-store` for the same reason `PreventJsonResponseCaching` exists on
        // the `web` group, which this route is deliberately not in: a JSON body
        // cached against a URL is a JSON body served to the next caller of it.
        return response()
            ->json($payload, $status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ->header('Cache-Control', 'no-store, private');
    }
}
