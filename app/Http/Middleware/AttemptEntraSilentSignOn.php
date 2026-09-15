<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Auth\EntraController;
use App\Support\Auth\EntraSso;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The "já estou logado no Windows, me deixa entrar" half of the SSO.
 *
 * A guest asking for a knowledge-base page is sent to Entra with
 * `prompt=none`: if their browser already holds a Microsoft session, they come
 * straight back signed in and land on the page they asked for, having seen
 * nothing. If it does not, Entra refuses without showing them anything either,
 * and `EntraController::callback()` puts them on the login screen with the
 * button.
 *
 * Four conditions, and every one of them is there to stop a redirect somebody
 * would experience as the app being broken:
 *
 * - **Configured.** Without an app registration there is nowhere to send them.
 * - **A guest.** Obviously — but it also has to run BEFORE `auth`, or `auth`
 *   sends them to the login screen first and this never gets a turn.
 * - **A GET navigation that wants HTML.** A redirect to another host is only
 *   survivable for a request the browser is navigating with. An `ajax-slot.js`
 *   fetch would follow it and try to parse Microsoft's login page as JSON; a
 *   POST would lose its body on the way.
 * - **Once per session.** `prompt=none` FAILS by redirecting back here, so a
 *   second attempt after a failed one is an infinite loop between two hosts.
 *   The flag is written before the redirect leaves (see the controller).
 */
class AttemptEntraSilentSignOn
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldAttempt($request)) {
            // Where they were going, so the callback can put them back. Same
            // key `redirect()->guest()` uses, so `redirect()->intended()` picks
            // it up with nothing extra to remember.
            $request->session()->put('url.intended', $request->fullUrl());

            return redirect()->route('entra.silent');
        }

        return $next($request);
    }

    private function shouldAttempt(Request $request): bool
    {
        return EntraSso::configured()
            && $request->user() === null
            && $request->isMethod('GET')
            && ! $request->wantsJson()
            && ! $request->ajax()
            && ! $request->session()->get(EntraController::SILENT_ATTEMPTED, false);
    }
}
