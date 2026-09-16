<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\View\Components\Mcp\Connections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * `/mcp/connect` — the screen a person is actually sent to, and the reason the
 * OAuth work was done at all.
 *
 * It is deliberately NOT `/mcp-tokens`. That screen is an admin surface: it
 * mints a credential that reads the whole catalog, so it answers to
 * `McpTokenPolicy` and shows a list of secrets. This one answers to `auth` and
 * nothing else, because the person who needs it is the person connecting — a
 * `Reader` provisioned by Entra SSO included, which is why it lives OUTSIDE the
 * `inventory` group with the `/docs` routes rather than inside it with the
 * catalog.
 *
 * What it holds is one URL and the connections that URL produced. Revoking is
 * here for the same reason: the consent screen promises it, and a promise about
 * where to undo something has to land somewhere the person can open.
 */
class ConnectController extends Controller
{
    public function index(): View
    {
        return view('mcp.connect', [
            'endpoint' => route('mcp.handle'),
        ]);
    }

    /**
     * Revokes one connection — the "sair de todos os dispositivos" of this
     * feature.
     *
     * Scoped to the account's OWN tokens by construction rather than by a
     * policy: the lookup goes through the `tokens()` relation, so a token
     * belonging to somebody else is not refused, it is not found. That is the
     * narrower of the two and it cannot be got wrong by a later edit.
     *
     * Passport's `revoke()` marks the access token dead and the refresh token
     * with it, so the client's next call is a 401 and its next refresh fails —
     * which is what sends a connector back through the consent screen instead of
     * quietly continuing.
     */
    public function destroy(Request $request, string $token): JsonResponse
    {
        $accessToken = $request->user()->tokens()->findOrFail($token);

        $accessToken->revoke();
        $accessToken->refreshToken?->revoke();

        return response()->json([
            'message'        => 'Conexão revogada.',
            'updatableSlots' => [Connections::slot()],
        ]);
    }
}
