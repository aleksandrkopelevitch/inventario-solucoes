<?php

use App\Http\Controllers\Mcp\OAuthClientRegistrationController;
use App\Http\Controllers\Mcp\OAuthDiscoveryController;
use App\Http\Controllers\McpController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| MCP — Model Context Protocol
|--------------------------------------------------------------------------
|
| One endpoint, in a file of its own because it is the one part of this app
| that is not a browser surface: no session, no CSRF, no `web` middleware.
| Its authentication is the `mcp` middleware group (`AuthenticateMcpRequest`),
| which accepts either a minted bearer token or an OAuth access token this app
| issued. The discovery documents below carry NO middleware at all: they are what
| a client reads before it has any credential, so requiring one would make them
| readable only by a client that no longer needs them.
|
| The path is `/mcp` and not `/api/mcp`, which is the convention every client's
| configuration help assumes, and short enough to be read aloud while somebody
| pastes it into a connector dialog.
|
*/

Route::post('mcp', [McpController::class, 'handle'])->middleware('mcp')->name('mcp.handle');

// The optional SSE stream, answered with 405. Named, because the token admin
// screen builds the connector URL with `route()` (§ Routing — never a literal).
Route::get('mcp', [McpController::class, 'stream'])->name('mcp.stream');

/*
| OAuth — the handshake that lets somebody connect by pasting one URL.
|
| Public, all four. `{path?}` on the two well-known documents is RFC 9728's
| resource-specific form (`/.well-known/oauth-protected-resource/mcp`); the bare
| form answers the same document because clients are split on which they ask
| for, and the one that guesses wrong reports "could not connect" rather than
| retrying.
|
| `/oauth/register` sits beside Passport's own `/oauth/*` routes (registered by
| its service provider) and completes them: Passport implements the grant,
| RFC 7591 registration is the part it leaves to the application.
*/
Route::get('.well-known/oauth-protected-resource/{path?}', [OAuthDiscoveryController::class, 'protectedResource'])
    ->where('path', '.*')
    ->name('mcp.oauth.protected-resource');

Route::get('.well-known/oauth-authorization-server/{path?}', [OAuthDiscoveryController::class, 'authorizationServer'])
    ->where('path', '.*')
    ->name('mcp.oauth.authorization-server');

Route::post('oauth/register', OAuthClientRegistrationController::class)
    ->middleware('throttle:mcp-register')
    ->name('mcp.oauth.register');
