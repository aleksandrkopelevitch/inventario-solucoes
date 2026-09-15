---
paths:
  - "bootstrap/app.php"
  - "app/Exceptions/**"
  - "app/Http/Middleware/PreventJsonResponseCaching.php"
  - "resources/views/errors/**"
  - "resources/js/modules/execute-filters.js"
  - "resources/js/modules/execute-search.js"
---

### Browser caching of the AJAX JSON response — filter URL collides with the fetch URL

`execute-filters.js`/`execute-search.js` call `history.replaceState(null, null, newUrl)`
so the address bar reflects active filters — and `newUrl` is **the exact same
URL** (same path, same query string) that `applyFilters()` then `fetch()`es
for the JSON `updatableSlots` response. Neither Laravel nor the browser is
told these are two different things (a document vs. a JSON payload) for the
same URL: there's no `Vary: Accept`, so nothing stops the browser's HTTP
cache from serving the cached JSON bytes back for a *later, unrelated*
top-level navigation to that identical URL — e.g. clicking a solution card
(pushes a new history entry), then hitting the browser's **Back** button.
When bfcache isn't available for that jump, the browser reloads the entry's
URL from the network/cache instead — and if it reuses the cached JSON
response, the user sees the raw `{"updatableSlots": [...]}` payload
rendered as a whole page (visible via Firefox's built-in JSON viewer)
instead of the catalog.

Fixed globally, not per-controller: `App\Http\Middleware\PreventJsonResponseCaching`
(registered on the `web` group in `bootstrap/app.php`) sets `Cache-Control:
no-store, private` on every JSON response — every controller using the
`wantsJson()` dual-response pattern is covered automatically, no
opt-in needed. Don't try to fix this per-controller with response-specific
cache headers; the middleware is the one place all of them funnel through.

Related, defense-in-depth: filter/search `<form>`s (e.g.
`#solutions-filter-form`) intentionally have no `method`/`action` — they're
AJAX-only. `execute-filters.js` has a delegated `submit` listener that calls
`preventDefault()` on any form containing `[data-ak-filters]` or
`[data-ak-search]`, so pressing Enter can never trigger a native GET
submission (which would itself land on that same collision-prone URL).
Keep that listener if you touch `execute-filters.js` — without it, Enter in
the search box bypasses `applyFilters()`/`history.replaceState()` entirely.

### Render callbacks run AFTER `prepareException()` — some renderers can never fire

Laravel 13's `Handler::render()` calls `prepareException()` **before**
`renderViaCallbacks()`, and that converts several exception classes into plain
`HttpException`s first:

| thrown | what a render callback actually receives |
|---|---|
| `AuthorizationException` (no status) | `AccessDeniedHttpException` (403) |
| `TokenMismatchException` | `HttpException` 419 |
| `ModelNotFoundException` | `NotFoundHttpException` |

So `$exceptions->render(function (AuthorizationException $e) { … })` is **dead
code** — it compiles, reads as correct, and never runs. This file used to carry
exactly that, which is why every `authorize()` failure answered with Laravel's
raw English `This action is unauthorized.` (and, since `ajax-post.js` reads
`errorBody.message ?? messages[status]`, that English string also beat the PT-BR
403 fallback the module already had). Both 403 and 419 are now handled in the
generic `HttpExceptionInterface` renderer, which is where they truly arrive.

Second trap in the same place: **don't call `abort()` from inside a render
callback.** `renderViaCallbacks()` does not catch what a callback throws, so
`abort(404)` inside the 404 renderer threw a fresh exception straight out of the
exception handler — with `app.debug` off (i.e. in production) every HTML 404
escaped instead of rendering any page. Return a response
(`response()->view('errors.404', status: 404)`) or return `null` to hand over to
Laravel's default rendering.

### ValidationException JSON shape — not Laravel's default

`bootstrap/app.php` reformats every `ValidationException` JSON response to
`{message, title, type}` — **there is no `errors` key**. `message` is the
first flattened validation error (`collect($e->errors())->flatten()->first()`).
This matches the `Toast`/`Modal.loadAlert` convention (`ajax-post.js` reads
`data.message`/`data.title`/`data.type` directly), but it means:

- `assertJsonValidationErrors()` in Pest/PHPUnit **never works** against this
  app's JSON error responses — it asserts against Laravel's default `errors`
  shape, which doesn't exist here. Test validation failures like this instead:

```php
$response = $this->postJson(...)->assertStatus(422)->assertJson(['type' => 'warning']);
expect($response->json('message'))->toContain('campo esperado');
```

- Client-side error handling should read `data.message` directly, not
  `data.errors` (see `resources/js/modules/inline-edit.js` for the pattern).
