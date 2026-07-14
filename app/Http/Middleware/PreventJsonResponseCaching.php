<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sem isso, o navegador pode reaproveitar uma resposta JSON de
 * `updatableSlots` como se fosse o documento de uma navegação real para a
 * mesma URL — exatamente o que acontece ao clicar "voltar" depois de
 * aplicar um filtro: `execute-filters.js` troca a URL da barra de endereço
 * (`history.replaceState`) para a mesma URL usada pelo fetch AJAX (mesmo
 * path + query string), sem enviar `Vary: Accept`. O cache HTTP do
 * navegador não distingue as duas requisições (documento vs AJAX) pela
 * mesma URL e pode devolver o JSON em cache em vez de recarregar a página,
 * mostrando o JSON cru em vez do catálogo renderizado.
 */
class PreventJsonResponseCaching
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (str_contains($response->headers->get('Content-Type') ?? '', 'json')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
