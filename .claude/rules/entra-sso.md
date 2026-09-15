---
paths:
  - "app/Support/Auth/EntraSso.php"
  - "app/Actions/Auth/ResolveEntraUser.php"
  - "app/Http/Controllers/Auth/EntraController.php"
  - "app/Http/Middleware/AttemptEntraSilentSignOn.php"
  - "app/Exceptions/EntraSignInRejected.php"
  - "bootstrap/app.php"
---

### Entra SSO signs people in; it never decides what they may do

`/docs` is reachable by anybody at Leo, and the way most of them arrive is
Microsoft Entra ID — OIDC authorization-code flow, through `laravel/socialite`
plus the `azure` driver. `config/services.php` § `azure` documents the values;
the feature ships OFF and stays off until the app registration exists, because
`App\Support\Auth\EntraSso::configured()` gates every route AND the button, and
a button that leads to a Microsoft error page is worse than no button.

**The behaviour being copied is GitBook's, and it has two halves.** A guest
reaching a knowledge-base page is sent to Entra with `prompt=none`
(`AttemptEntraSilentSignOn` → `EntraController::silent()`): if their browser
already holds a Microsoft session they come straight back signed in, having seen
nothing. If not, Entra refuses without showing them anything either, and the
callback puts them on the login screen with the button.

**The silent attempt is spent at most ONCE per session, and that is the most
important rule in the feature.** `prompt=none` fails by REDIRECTING back, so a
failed attempt that leads to another attempt is an infinite loop between two
hosts — the browser spins and the app looks down. The flag is written BEFORE the
redirect leaves, never after the answer comes back (which is the version that
loops when the answer never comes).

Four more things, each of which was a way in that had to be closed:

- **A tenant is not a payroll.** `ResolveEntraUser` checks the mailbox domain
  again on the way back, exactly on the part after the last `@` — `str_ends_with`
  on the whole address passes `@evil-leomadeiras.com.br`, which is a different
  company. An empty allow-list refuses everybody: a mistyped
  `ENTRA_ALLOWED_DOMAINS` should fail closed.
- **A GUEST account is the case the domain check cannot see.** An external
  person invited into the Leo tenant gets a UPN in a Leo domain, so the domain
  check passes; `#EXT#` is the marker Entra puts in every one of them.
- **A revoked account is never restored.** Revoking soft-deletes
  (`GrantPersonAccess::revoke()`), and SSO that quietly brought it back would
  make "remover acesso" mean nothing at all for anybody who still has a mailbox.
- **The ROLE is never derived from a claim.** A first-time signer is a `Reader`
  and an account we already hold keeps whatever it has — an admin who signs in
  through SSO stays an admin, a reader promoted last week is not demoted by
  their next sign-in. A permission this app derived from somebody else's
  directory is a permission this app does not control. Matching is by `entra_id`
  (the `oid`, which survives a rename) and by e-mail exactly ONCE, on the visit
  that links the two — without that one match, an editor invited last year
  arrives as a brand-new reader with their submissions stranded on the old row.

Socialite owns the `state` parameter, which is the CSRF protection for the whole
flow — `stateless()` is therefore never called here, since it would turn a login
route into one anybody can make somebody else's browser complete.

#### Route middleware is SORTED, so "listed first" is not "runs first"

`entra.silent` has to run before `auth`, and putting it first in the group's
middleware array does not achieve that. `Router::gatherRouteMiddleware()` sorts
what it gathered through `$middlewarePriority`, and the authentication
middleware is on that list while anything of ours is not — so `auth` was hoisted
ahead of it, every guest met the login screen, and the silent sign-on never ran
a single time. It failed **silently**, and from the outside it looked exactly
like a middleware that had not been registered at all: `route:list` showed it on
the route, the alias resolved, and `handle()` was simply never reached.

`bootstrap/app.php` fixes it with `prependToPriorityList()`, and the anchor is
the CONTRACT (`Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests`),
not `Authenticate::class`. The priority list names the interface, and
`prependToPriorityList()` given a class that is not literally in the list does
not throw — it APPENDS, which puts the middleware last and reproduces the exact
bug it was added to fix. Anything that must run before `auth` needs this, and
needs a test that a guest is actually redirected somewhere other than the login
screen.
