<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * O papel `agent` é reservado para acesso programático (geradores de flowSpec
 * F8/F9 via API). Ele não deve acessar a interface web — seção 15 do briefing:
 * "agent permanece bloqueado por middleware (sem acesso web)".
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
