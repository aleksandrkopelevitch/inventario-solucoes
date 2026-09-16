<?php

namespace App\Http\Controllers;

use App\Http\Requests\DestroyMcpTokenRequest;
use App\Http\Requests\StoreMcpTokenRequest;
use App\Models\McpToken;
use App\View\Components\Mcp\TokenList;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * The admin screen behind the MCP server: the tokens, and the two lines of
 * configuration somebody has to paste into a chat client.
 *
 * It is a screen and not a `config/services.php` key, which is the other obvious
 * way to hand out one token. A configuration value would need a deploy per
 * client, could not be told apart when three of them exist, and could not be
 * revoked without taking the other two down with it — and this app already has
 * the precedent for the opposite: `Notebook::rotateSecretCode()`, a credential
 * an admin mints and destroys from the screen it belongs to.
 *
 * The connection instructions live on this page rather than in the README for
 * the same reason the caderno's share panel names both audiences side by side:
 * the person reading it is deciding what to hand over, and that is the moment
 * the answer has to be in front of them.
 */
class McpTokenController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', McpToken::class);

        return view('mcp.index', [
            // `route()` rather than a literal, so a deploy behind a different
            // host or prefix hands out an address that actually works.
            'endpoint' => route('mcp.handle'),
        ]);
    }

    /**
     * Mints a token and answers with the list — carrying the plaintext, which
     * this is the only response that will ever contain.
     *
     * A slot rather than a redirect, and here that is not only about preserving
     * the screen: a redirect could not carry the plaintext at all without
     * putting a live credential in the session (§ `TokenList`).
     */
    public function store(StoreMcpTokenRequest $request): JsonResponse
    {
        ['token' => $token, 'plain' => $plain] = McpToken::mint(
            $request->validated('name'),
            $request->user(),
        );

        return response()->json([
            'type'           => 'success',
            'message'        => "Token \"{$token->name}\" criado. Copie-o agora — ele não aparece de novo.",
            'updatableSlots' => [TokenList::slot($plain)],
        ]);
    }

    /** Deleting IS revoking: the row is the credential, and there is no soft delete. */
    public function destroy(DestroyMcpTokenRequest $request, McpToken $mcpToken): JsonResponse
    {
        $name = $mcpToken->name;
        $mcpToken->delete();

        return response()->json([
            'type'           => 'success',
            'message'        => "Token \"{$name}\" apagado. Quem o estiver usando perdeu o acesso.",
            'updatableSlots' => [TokenList::slot()],
        ]);
    }
}
