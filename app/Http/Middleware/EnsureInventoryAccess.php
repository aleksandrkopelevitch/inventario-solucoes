<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a `Reader` inside the knowledge base.
 *
 * Until the `Reader` tier existed, holding an account and being able to read
 * the whole inventory were the same thing: every `viewAny` answered `true`, so
 * the `auth` middleware WAS the authorization for `/solutions`, `/people`,
 * `/diagrams` and `/flowspec`. Entra SSO breaks that equivalence — an account
 * is no longer something an admin created one at a time, it is something
 * anybody with a Leo mailbox gets by visiting once.
 *
 * So the gate is drawn twice, deliberately:
 *
 * - **Here**, once, at the route group — which is what makes it complete. A
 *   policy per controller is a list somebody has to keep adding to, and the
 *   entry that gets forgotten is a leak nobody sees.
 * - **In the policies** (`UserRole::canReadInventory()`), which is what makes
 *   it true rather than merely enforced — an `authorize()` call is supposed to
 *   answer correctly on its own, and a policy that says yes while a middleware
 *   says no is a rule waiting to be reached another way.
 *
 * A REDIRECT rather than a 403, because for the audience this is written for it
 * is not a refusal at all: somebody following a link into the app who only has
 * the knowledge base should land in the knowledge base, not on an error page
 * about a catalog they have never heard of. A JSON caller gets the 403 it can
 * actually parse.
 */
class EnsureInventoryAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->role->canReadInventory()) {
            abort_if($request->wantsJson(), 403, 'Esta área não está disponível para o seu acesso.');

            return redirect()->route('docs.index');
        }

        return $next($request);
    }
}
