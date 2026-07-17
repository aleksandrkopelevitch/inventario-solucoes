<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Without this, the browser can reuse a JSON `updatableSlots` response as if
 * it were the document of a real navigation to the same URL — exactly what
 * happens when clicking "back" after applying a filter: `execute-filters.js`
 * swaps the address bar's URL (`history.replaceState`) to the same URL used
 * by the AJAX fetch (same path + query string), without sending
 * `Vary: Accept`. The browser's HTTP cache doesn't distinguish the two
 * requests (document vs AJAX) by the same URL and may return the cached
 * JSON instead of reloading the page, showing the raw JSON instead of the
 * rendered catalog.
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
