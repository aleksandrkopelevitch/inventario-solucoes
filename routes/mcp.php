<?php

use App\Http\Controllers\McpController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| MCP — Model Context Protocol
|--------------------------------------------------------------------------
|
| One endpoint, in a file of its own because it is the one part of this app
| that is not a browser surface: no session, no CSRF, no `web` middleware.
| Its whole authentication is the bearer token `AuthenticateMcpToken` checks
| (see `bootstrap/app.php`, the `mcp` middleware group).
|
| The path is `/mcp` and not `/api/mcp`, which is the convention every client's
| configuration help assumes, and short enough to be read aloud while somebody
| pastes it into a connector dialog.
|
*/

Route::post('mcp', [McpController::class, 'handle'])->name('mcp.handle');

// The optional SSE stream, answered with 405. Named, because the token admin
// screen builds the connector URL with `route()` (§ Routing — never a literal).
Route::get('mcp', [McpController::class, 'stream'])->name('mcp.stream');
