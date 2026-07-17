<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `agent` role is reserved for programmatic access (F8 flowSpec
 * generator via API). It must not access the web interface — section 15 of
 * the briefing: "agent stays blocked by middleware (no web access)".
 */
class BlockAgentFromWeb
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role === UserRole::Agent) {
            abort(403, 'Agentes não têm acesso à interface web.');
        }

        return $next($request);
    }
}
