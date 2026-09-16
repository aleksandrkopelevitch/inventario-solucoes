<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterOAuthClientRequest;
use App\Mcp\OAuth;
use Illuminate\Http\JsonResponse;
use Laravel\Passport\ClientRepository;

/**
 * `POST /oauth/register` — dynamic client registration (RFC 7591), and the
 * reason a person never sees a client id.
 *
 * Without it, connecting means an admin creating an OAuth client by hand for
 * every product anybody wants to use, then sending two strings to each person —
 * which is the token workflow again, wearing a different hat. With it, the
 * connector registers itself the first time somebody adds the URL, and the whole
 * of what a person does is sign in.
 *
 * Everything that makes this safe to leave open lives in
 * `RegisterOAuthClientRequest`: the redirect URI must match an origin Leo
 * decided on, or be loopback. What is left here is deliberately dull.
 *
 * Clients are PUBLIC (`confidential: false`). A desktop app cannot keep a
 * secret — it ships to laptops — so the RFC's answer, and the MCP spec's, is
 * PKCE instead: the client proves at the token endpoint that it is the same one
 * that started the flow. Issuing a secret here would be a secret sitting in a
 * config file pretending to be a credential.
 */
class OAuthClientRegistrationController extends Controller
{
    public function __construct(private readonly ClientRepository $clients) {}

    public function __invoke(RegisterOAuthClientRequest $request): JsonResponse
    {
        $client = $this->clients->createAuthorizationCodeGrantClient(
            name: $request->clientName(),
            redirectUris: $request->redirectUris(),
            confidential: false,
        );

        return response()->json([
            'client_id'                  => (string) $client->getKey(),
            'client_name'                => $client->name,
            'redirect_uris'              => $client->redirect_uris,
            'grant_types'                => ['authorization_code', 'refresh_token'],
            'response_types'             => ['code'],
            'scope'                      => OAuth::SCOPE,
            'token_endpoint_auth_method' => 'none',
        ], 201, [], JSON_UNESCAPED_SLASHES);
    }
}
