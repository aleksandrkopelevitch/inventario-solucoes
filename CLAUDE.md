# Inventário de Soluções — Claude Guidelines

Catalog of Leo Madeiras' solutions/integrations: solution, people and company
records, a graphical topology editor for a solution's integrations, and a
read-only map of the ecosystem. Fork of the generic infra from the
**akop-pro** reference project (forms, slots, JS modules, layout shells) — that
project's legacy domain (CRM, DISC, multi-tenancy) is not part of this one.
See `README.md` for an overview and feature list.

## Stack
- Laravel 13+, Blade, Vanilla JS, Tailwind CSS 4, Vite 8, SQLite (dev) / PostgreSQL (prod)

## Commands

```bash
composer dev          # serve + queue + pail + vite in parallel
composer test         # pest
./vendor/bin/pint     # code style
npm run build && php artisan optimize
```

## Language

All code and everything the end user never sees — comments, docblocks,
commit messages, this file, internal error/log messages, seeder/migration
comments — must be written in English, regardless of the surrounding code's
language. The one deliberate exception is **user-facing text**: Toast/flash
messages, validation error strings, Blade view content, UI labels/
placeholders, and any string a Brazilian end user actually reads on screen —
those stay in Portuguese, since this app's real UI is PT-BR for Leo Madeiras
staff. When editing a file, don't "fix" surrounding user-facing Portuguese
strings while translating a comment near them, and don't translate a
comment's quoted reference to an actual on-screen label/button name (e.g. a
comment that says `the "Adicionar bloco" button` keeps that name verbatim —
it's what's really printed on the button).

## Conventions

- Thin controllers:
  - broader logic lives in Services
  - Actions for single-purpose operations (single-purpose, constructor DI)
- Complex queries in Scopes or Query Builders
- Routes always with `->name()`
- Mobile-first with Tailwind
- Avoid:
  - fat controllers
  - `dd()` committed
  - raw queries
  - heavy logic in Blade
  - raw `<button>` tags (use `<x-forms.button>` always)
- `env()` only inside `config/*.php` files — never call it directly in application code
- Sensitive env values must use Laravel's encrypted environment variables

## Controller Response Pattern

Controllers serve both HTML (normal GET) and JSON (AJAX) from the same action using `$request->wantsJson()`. Never create separate actions for AJAX variants:

```php
public function index(Request $request)
{
    if ($request->wantsJson()) {
        return response()->json([
            'updatableSlots' => [Users\Index::slot()],
        ]);
    }

    return view('users.index');
}

public function update(UserRequest $request, User $user)
{
    $user->update($request->validated());

    return response()->json([
        'message'        => 'Alterações salvas.',
        'updatableSlots' => [
            Users\Index::slot(),
            Users\HeaderWidget::slot(),
        ],
    ]);
}
```

## Architecture — Actions

Actions are single-purpose classes. Use constructor dependency injection:

```php
class AnalyzeProposal
{
    public function __construct(
        private readonly RagService $rag,
        private readonly ClaudeClient $claude,
    ) {}

    public function handle(Proposal $proposal): AnalysisResult
    {
        // single responsibility
    }
}
```

### Integration topology invariant — the chain is the single source of truth

An `Integration`'s topology lives in its `chain` json — a genuinely free
graph: `{nodes: [{solution_id, label, kind}], edges: [{from, to, arrow, protocol}]}`,
where `from`/`to` are indices into `nodes` (not consecutive positions) and
each edge carries its own direction (`'->'|'<-'|'<->'`) and protocol.

**Nodes and edges are created independently.** `addNode()` appends a PURE
node — kind + Solution/free text, never an edge —, so a block is always born
isolated; wiring is a separate gesture (dragging an arrow out of a block's
port, "modo ligar", or retargeting an existing edge), which is why a node with
zero edges is a normal state, not a leftover. `kind`
(`App\Enums\ChainNodeKind`: `system` | `decision` | `actor` | `start` | `end` |
`image`) is what each block *is*: only `system` may reference a Solution
(`solution_id`) — every other kind is free text (or, for `image`, a pasted
image) and therefore never a participant. `start`/`end` carry a default label
the server fills in when left blank; `image` has no label/kind picker at all
(see `ChainNodeKind::pickable()`). Nodes
written before kinds existed have no `kind` key at all and read as `system`
(`ChainNodeKind::fromNode()`); the three consumers that care —
`SyncIntegrationFromChain`, `ChainLabeler::nodeLabel()` and
`IntegrationsMap::resolveNode()` — all decide via
`ChainNodeKind::referencesSolution()`, so a stale `solution_id` on a
decision/actor node can never resurrect it as a participant. Both endpoints
that write a node (`addNode`/`updateNode`) validate the same three fields via
the `ValidatesChainNode` trait.

**Removing a node is the one mutation that REINDEXES.** `removeNode()` drops
the block, every edge touching it, and decrements every surviving `from`/`to`
above the removed index — then reindexes `viz_layout` in three places, because
`nodes` and `comments` there are keyed by NODE index while `edges` (anchors) is
keyed by EDGE index. Miss one and blocks silently inherit their neighbour's
position or comment. Root (index 0) is never removable. It's also the only
chain endpoint that returns a **whole rebuilt graph** (`IntegrationsMap::graph()`,
public for this reason) instead of a patch: after a reindex there's nothing the
client can safely patch, so it calls `render()` again — and drops its
`savedLayouts` cache entry first, since that cache is keyed by the old node
count.

Two edges between the same pair of blocks are legitimate when they say
something different (A `->` B over REST *and* over SFTP, or one edge each
way), so `AddIntegrationChainEdgeRequest` refuses only an **exact** duplicate
(same `from`/`to`/`arrow`/`protocol`) — dragging an arrow out of a port creates
`->`/no-protocol with no dialog on the way, so repeating the gesture is easy to
do by accident, and the second arrow would double-count in the degree math
above while being indistinguishable in the canvas.

`App\Actions\SyncIntegrationFromChain`
is the ONLY thing that writes the derived columns (`participants` pivot with
`position`, `source/target_solution_id`, `direction`, and the summary scalar
`protocol` = first non-null edge protocol) — it runs after every mutation to
`chain` (`SolutionIntegrationController::store/updateNode/updateProtocol/
addNode/retargetEdge/addEdge/removeEdge/removeNode`). `Integration.viz_layout`
(`{nodes: [{x,y}], edges: [{from,to}], comments}`) is a purely **visual**
concern — node position/style and per-block comments in the graphical canvas
(`resources/js/modules/integration-viz.js`) — and must NEVER drive topology;
`saveLayout()` writes only `viz_layout`, never touching `chain` or the derived
columns. Don't write the derived columns directly — edit `chain` and let the
action re-derive. There is no separate diagram/canvas editor page — the same
canvas that displays the chain is what authors it (see `SolutionIntegrationController`'s
docblock and `integration-viz.blade.php`).

## Eloquent

- Always define return types on relationships:

```php
public function proposals(): HasMany
{
    return $this->hasMany(Proposal::class);
}
```

- Use local scopes for reusable query constraints:

```php
public function scopeActive(Builder $query): void
{
    $query->where('status', 'active');
}
```

- Avoid N+1: always eager load with `with()`, constrain to needed columns only:

```php
Proposal::with(['user:id,name', 'analysis:id,proposal_id,score'])->get();
```

- Use subquery selects and dynamic relationships for advanced queries instead of loading extra models
- Prefer `cursor()` for memory-efficient iteration over large datasets; use `lazyById()` for chunked processing; `lazy()` only for non-keyed sets

### Strict mode — no implicit lazy loading

`AppServiceProvider` calls `Model::shouldBeStrict(! $this->app->isProduction())`
— accessing an unloaded relationship outside production throws
`LazyLoadingViolationException` (a 500 in dev/test, not a silent query). This
mostly surfaces inside a Blade partial that assumes a relation is loaded
because the CALLER happens to have it in scope.

**This guard only arms on multi-row hydration — don't assume it protects a
single-model fetch.** `Illuminate\Database\Eloquent\Builder::hydrate()` sets
the per-instance `$preventsLazyLoading` flag only `if (count($items) > 1)`; a
single-row fetch (`find()`, `firstOrFail()`, a `belongsTo`/`hasOne` relation,
or a model a queued job restores via `SerializesModels`) never arms it, so an
unloaded relation on it silently lazy-loads with **no exception, in any
environment** — verified 2026-07-15 by calling
`Integration::query()->find($id)->source` inside a `LazilyRefreshDatabase`
Feature test with `app()->isProduction()` confirmed false: no exception.
Jobs are where this bites most — `handle(SomeModel $thing)` then
`$thing->relatedModel->...` gets zero protection from strict mode, which is
exactly the pattern it's supposed to catch. Eager-load explicitly
(`$thing->loadMissing('relatedModel')`) at the top of job/service methods
that walk a relation off a single fetched model — don't rely on a missing
`with()` being caught by strict mode or by a test.

If a View Component maps a parent's already-loaded collection and a child
partial needs to walk back up (`$block->integration` when the component only
has `$this->integration`), set the relation in memory instead of eager
loading a query you don't need:

```php
$block->setRelation('integration', $this->integration); // no query — already in hand
```

## DB Performance

- Eager load relationships — never lazy load in loops
- Constrain eager loads to only the columns actually needed
- Use `cursor()` / `lazyById()` for large result sets instead of `get()`
- Index foreign keys; use `constrained()` on migrations

## Migrations

- Always generate via `php artisan make:migration` — never create files manually
- Use `$table->foreignId('user_id')->constrained()` — never raw integer + separate index
- Never modify a migration that has already been deployed/run in production
- One migration = one concern

## Routing

- Implicit route model binding always preferred over manual `findOrFail()`
- Use scoped bindings for nested resources:

```php
Route::get('/proposals/{proposal}/analyses/{analysis}', ...)
    ->scopeBindings();
```

**URL paths in this app are in English** (`/solutions`, `/companies`,
`/people`, `/documentation`, `/map`, `/flowspec`) even though every label the
user reads is PT-BR. Keep new paths English too, and always build URLs with
`route()` rather than a literal. When you need a path, `php artisan route:list
--path=<fragment>` is the only reliable source — this file has already drifted
from reality once by citing Portuguese paths that 404.

Reference implementation in this app: `routes/web.php`'s
`Route::scopeBindings()->group(...)` around the
`solutions/{solution}/integrations/{integration}/...` routes. `{integration}`
404s unless the `{solution}` participates in it (resolved via
`Solution::integrations()`). The nested chain-editing routes under it
(`chain/nodes/{node}`, `chain/protocol/{edge}`, `chain/edge/{edge}`) take a
plain integer index into `chain.nodes`/`chain.edges` (`whereNumber(...)`), not
a model — those aren't scoped bindings, just route params validated as
numeric and range-checked inside the controller.

## Security

- Every model must define `$fillable` — no `$guarded = []` shortcuts
- Every controller action that mutates data must call `$this->authorize()` or use a Policy
- Validate all input via Form Request classes — no inline `$request->validate()` in controllers:

```php
class StoreProposalRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
        ];
    }
}
```

- Use array notation for validation rules (never pipe strings):

```php
// ✅
'email' => ['required', 'email', 'unique:users']

// ❌
'email' => 'required|email|unique:users'
```

## Caching

- Use `Cache::remember()` for standard TTL caching
- Use `Cache::flexible()` for stale-while-revalidate patterns (serve stale, refresh in background)
- Use `Cache::memo()` for per-request in-memory caching of repeated identical calls
- Never cache inside a loop — cache the full result set, then iterate

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

## Queue & Jobs

- `retry_after` in `config/queue.php` must always be greater than the job's `$timeout`
- Implement exponential backoff for retries:

```php
public function backoff(): array
{
    return [10, 30, 60];
}
```

- Use `ShouldDispatchAfterCommit` on jobs dispatched inside DB transactions to avoid race conditions
- **UI reflects a queued job's outcome via polling, not broadcasting**
  (`resources/js/modules/websocket.js` was removed as dead code with zero
  consumers). Reference implementation: `App\Jobs\GenerateFlowspecReply` (F8,
  Especialista em Integrações chat) + `resources/js/modules/flowspec-chat.js` — a
  `data-ak-*-poll` marker rendered inside the slot, a module-level
  `setInterval` that stops itself once the marker disappears from the DOM
  after a slot swap, and a **client-side give-up ceiling with a user-visible
  Toast** (don't poll forever in silence if the queue worker is down or the
  job never completes). The status endpoint must stay cheap while the job is
  still pending: build/render the actual updatable slot only once the result
  is ready — computing it on every poll tick (every 2–3s, for a job that can
  take minutes) wastes a full query+render cycle on data the client discards.
- **A job representing one turn of a sequential conversation/thread** (one
  reply per prior message, order matters) needs `WithoutOverlapping` keyed by
  the thread/chat id — without it, two rapid submissions to the same thread
  run concurrently and violate the "one pending turn at a time" assumption
  the UI/polling logic makes. Bump `$tries` well above 1 when adding it: each
  blocked overlap is released back to the queue and retried (a wait, not a
  real failure), and a release counts against `$tries`.

## Events & Notifications

- Use event discovery (auto-discovery via `EventServiceProvider`) instead of manually mapping listeners
- Run `php artisan event:cache` in production
- Implement `ShouldDispatchAfterCommit` on events dispatched inside transactions
- Queue notifications — never send synchronously in a request cycle

## Mail

- All Mailables must implement `ShouldQueue`
- Call `->afterCommit()` when dispatching mail inside a transaction
- Prefer Markdown mailables for maintainable templates

## HTTP Client

- Always set explicit timeouts — never leave them at default:

```php
Http::timeout(30)->connectTimeout(5)->get($url);
```

- Create service-specific macro clients for external APIs:

```php
// AppServiceProvider
Http::macro('claude', fn () =>
    Http::baseUrl(config('services.claude.url'))
        ->withToken(config('services.claude.key'))
        ->timeout(60)
        ->connectTimeout(5)
);

// Usage
Http::claude()->post('/messages', $payload);
```

## Scheduling

- Always use `withoutOverlapping()` on commands that may run longer than the interval
- Use `onOneServer()` for multi-server deployments
- Use `runInBackground()` for non-blocking tasks
- Group related scheduled tasks with Schedule Groups

## Error Handling

- All exception renderers (`ValidationException`, `NotFoundHttpException`, etc.) are registered in `bootstrap/app.php` — both JSON (`wantsJson()`) and HTML responses are handled there
- **Never use try/catch in controllers** — exceptions bubble up and are handled centrally
- Define `report()` and `render()` directly on custom Exception classes for domain-specific handling
- Application exceptions should be self-contained
- Custom error PAGES live in `resources/views/errors/{status}.blade.php` (403, 404). They are deliberately self-contained (own `<html>`, no `x-layouts.layout`): the app shell renders a sidebar with `auth()->user()`, and a 404 also serves the one unauthenticated surface in the app — an expired public-documentation magic link
- Flash messages (`->with('error'|'status', …)` on a redirect) surface as a Toast, rendered once at the bottom of `layout.blade.php`. Nothing read them at all before that existed, so `back()->with('error', …)` used to be written and silently dropped

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

## Collections

- Use higher-order messages where readable: `$proposals->each->analyze()`
- Prefer `cursor()` over `get()` for large Eloquent collections
- Use `->toQuery()` to convert a collection back to a query builder when needed

## Blade Views

- Use `$attributes->merge()` on all custom components to pass through HTML attributes
- Prefer `@pushOnce` over `@push` for scripts/styles that should only appear once
- Always prefer Blade components (`<x-...>`) over `@include` for reusable partials

### Never write a component TAG inside a Blade file's comment

The component-tag compiler runs over the raw file text before anything else,
and it does **not** know what a comment is. So naming a component the way you
would in prose — with the angle brackets — inside a `@php`/`@props` block's
`//` comment (or a `{{-- --}}` one) compiles into a real component
invocation, dumped in the middle of the PHP it was sitting in:

```blade
{{-- ❌ Compiles `<x-ui.inline-edit-field>` into an actual @component(...) call
     spliced into the @props array — "Undefined variable $component" +
     "Call to a member function withAttributes() on null", pointing at a
     compiled view, with nothing wrong at the line the error names. --}}
@props([
    // see the notes on <x-ui.inline-edit-field>
    'name' => null,
])

{{-- ✅ Same sentence, no brackets --}}
@props([
    // see the notes on x-ui.inline-edit-field
    'name' => null,
])
```

Hit on 2026-08-14 while building `x-ui.inline-edit`. Refer to components in
comments as `x-ui.thing` (or by view path), never `<x-ui.thing>`.

### Never use `@json()` for a `data-ak-*` (or any) HTML attribute value

`@json($value)` written as a quoted attribute — `data-ak-filters='@json($value)'`
— **silently fails to compile whenever that attribute sits on a Blade
component tag** (`<x-forms.select data-ak-filters='@json($value)'>`).
Laravel's `ComponentTagCompiler` treats static (non-`:`-prefixed) attribute
values on `<x-...>` tags as opaque strings and never re-runs the directive
compiler over them — the literal text `@json($value)` reaches the browser,
`JSON.parse()` on it throws, and whatever JS behavior that config was
driving (filters, search, tabs, chips, …) just doesn't happen. No error is
visible anywhere except in the raw rendered HTML.

The `:attr="json_encode($value)"` dynamic-attribute form looks like the fix,
but it has the mirror-image bug: it only compiles on `<x-...>` component
tags — on a **plain** HTML tag (`<div>`, `<button>`) the `:`-prefix is left
completely untouched as literal text.

There is no version of `@json()` or `:attr=` that is safe on both plain tags
and component tags. **Always use the universal form instead — a normal
Blade echo inside a double-quoted attribute:**

```blade
{{-- ✅ Works identically on a plain <div> and on any <x-...> component tag --}}
<div data-ak-chips="{{ json_encode($config) }}">
<x-forms.select data-ak-filters="{{ json_encode($filterBind) }}">

{{-- ❌ Never do this — breaks on component tags, and the failure is invisible in the source --}}
<x-forms.select data-ak-filters='@json($filterBind)'>

{{-- ❌ Never do this either — breaks on plain HTML tags --}}
<div data-ak-chips=":data-ak-chips="json_encode($config)"">
```

`{{ }}` is a plain textual substitution Blade always applies, regardless of
whether it's inside a component tag's attribute or a plain tag's — unlike
`@directive()` and the `:`-prefix binding, which are special-cased by two
different, mutually incompatible compiler passes.

Real incident (2026-07-02): this exact bug silently broke **all** filter and
search UI on `/solutions`, `/companies`, `/people` and the standalone
integrations index (since removed) — every
`<x-forms.select>`/`<x-forms.input>`/`<x-forms.checkbox>` carried
`data-ak-filters='@json($filterBind)'` or `data-ak-search='@json([...])'`.
Confirmed via curl that the rendered HTML contained the literal uncompiled
`@json(...)` text. Pest feature tests did not catch it — they call
controllers directly with `filter[...]` query params, bypassing the
Blade/JS rendering layer entirely. Fixed by converting every occurrence
(including the ones on plain tags, which weren't broken, for a single
consistent convention) to `attr="{{ json_encode(...) }}"`.

### Never echo a `ComponentAttributeBag` inside an x-component tag's attributes

Same compiler, third variant. Forwarding a bag of attributes by echoing it —
`{{ new \Illuminate\View\ComponentAttributeBag($extra) }}` — is the normal way
to splat attributes onto a **plain** tag (`x-forms.image-upload` does exactly
that on its `<input>`s). Put the same echo in the attribute area of an
`<x-...>` tag and `ComponentTagCompiler` fails to parse that tag: it emits the
whole `<x-ui.external-link …>` **verbatim into the HTML** — no exception, no
log line, just a component that silently never renders (and, if the bag were
the only thing making it work, a feature that quietly does nothing).

**Pass the array as a prop and echo the bag inside the child, on its own plain
tag:**

```blade
{{-- ✅ parent: a plain dynamic prop --}}
<x-ui.external-link :href="$link" :extra-attributes="$linkAttributes" />

{{-- ✅ child (external-link.blade.php): the echo lives on a plain <a> --}}
<a href="{{ $href }}" {{ new \Illuminate\View\ComponentAttributeBag($extraAttributes) }} …>

{{-- ❌ parent: breaks the tag's compilation, invisibly --}}
<x-ui.external-link :href="$link" {{ new \Illuminate\View\ComponentAttributeBag($linkAttributes) }} />
```

Hit on 2026-08-14 wiring `target="_blank"`/`rel="noopener"` onto the company
website's ↗. A Pest render test caught it (`assertSee('data-ak-inline-edit-link')`
against the real page) — worth writing one whenever a component is expected to
render an attribute, since nothing else in the stack complains. Static
attributes on a component tag (`<x-ui.external-link target="_blank">`) are fine
as always; it's only the echoed bag that breaks.

And the sibling trap two sections up — never write a component TAG inside a
Blade comment — bit again on the same day, inside a comment explaining *this
very rule*: `<x-...>` in a `//` comment compiles into a real component
invocation and 500s with `Unable to locate a class or view for component [...]`.
Name components in comments as `x-ui.thing`, brackets omitted.

## CSS — Tailwind utilities over custom classes

Default to composing Tailwind utility classes directly on elements, encapsulated
inside a Blade component when the markup repeats. Do **not** reach for a custom
CSS class or a `<style>` block just because a component has several elements —
express the whole thing in utility classes on each element instead.

```blade
{{-- ✅ Utilities on the element, no custom class --}}
<button class="rounded-md px-2.5 py-1 text-xs font-medium text-ink hover:bg-accent-soft">

{{-- ❌ Custom class + separate <style> block for something Tailwind already expresses --}}
<button class="fc-tbtn">
<style>.fc-tbtn { padding: 4px 11px; border-radius: 6px; ... }</style>
```

Use design tokens from `@theme` in `resources/css/app.css` (`bg-surface`,
`text-ink`, `border-line`, `rounded-card`, `rounded-field`, etc.) — never
hardcode a hex color or a one-off `border-radius` that already has a token.

**Legitimate exceptions** (custom CSS is the right tool):
- **Rendered Markdown**, in all three of its flavours — the HTML comes out of a
  renderer, so there is no element to hang a utility class on before it exists:
  `.ak-viz-md` (inline `<style>` in `integration-viz.blade.php`, a node's
  comment preview in the F3 canvas), `.html-content` (`app.css`, a whole
  GitBook documentation PAGE — `documentation/partials/_reader.blade.php`
  renders it and `docs-toc.js` reads it; an older note here claimed it was
  dead, it isn't), and `.ak-rich-text`
  (`resources/css/components/rich-text.css`, the short free-text fields — see
  "Two Markdown renderers" below). Keep them separate: `.html-content` types a
  document, `.ak-rich-text` deliberately sets no size and no color so the text
  keeps reading as part of the card it sits in.
- Global browser-chrome overrides that have no per-element target, e.g. the
  scrollbar styling in `resources/css/components/scrollbar.css`.
- `resources/views/components/ecosystem-map.blade.php` (the DOM+SVG ecosystem
  map, radial hub-and-spoke layout) and
  `resources/views/components/solutions/integration-viz.blade.php` (the F3
  per-solution integration canvas) share the same scoped `--viz-*` token set
  and `.ak-viz-node`/`.ak-viz-node-avatar` classes, so both render nodes
  identically — a legitimate exception since the content (JS-built graph
  nodes/edges) never passes through Blade.

Before adding a new custom class or `<style>` block, check whether the same
result is reachable with `@class([...])`, arbitrary-value utilities (`w-[172px]`),
or an inline `style="height: {{ $dynamic }}"` for genuinely runtime-computed values.
If a past reviewer left dead custom CSS behind (unused decorative classes,
leftover from a copied reference bundle), delete it rather than leaving it —
`grep` the class name across `resources/views` first to confirm it's unused.

### Two Markdown renderers, on purpose

| | `App\Support\GitbookRenderer` | `App\Support\MarkdownText` |
|---|---|---|
| for | documentation pages/integrations, authored in the Editor.js block editor | the short free-text fields: a person's/company's `notes`, a solution's `description` and `support_operation_note` |
| speaks | Markdown **+ GitBook notation** (`{% hint %}`, `{% tabs %}`, `{% file %}`) | plain Markdown (GFM), nothing else |
| raw HTML in the source | `html_input=allow` — it's how images arrive as `<figure><img src="/files/{id}">` | `html_input=strip`, plus `allow_unsafe_links=false`: a `<script>` in someone's notes must never run for the next reader |
| single newline | a normal Markdown soft break | rendered as `<br>` (`renderer.soft_break`), because these fields were plain textareas read back with `whitespace-pre-line` and every note already in the database relies on it |
| read side | `.html-content` + `GitbookRenderer` | `x-ui.markdown` + `.ak-rich-text` |
| write side | Editor.js (`docs-editor.js`) | still a plain `<textarea>` — `x-ui.inline-edit type="textarea"`, which prints its own "Aceita Markdown" hint; the panel forms say the same via `x-forms.field`'s `hint` |

Don't merge the two without deciding which of those `html_input` contracts
wins — and don't reach for the Editor.js editor for a small field: it's a
page-level singleton (one `[data-ak-docs-save]`, module-level `dirty`/`locked`
state) with its own save endpoint and a media collection behind
`App\Contracts\Documentable`.

## Testing

- Use `LazilyRefreshDatabase` instead of `RefreshDatabase` (faster)
- Use model assertions:

```php
$this->assertModelExists($proposal);
$this->assertModelMissing($deletedProposal);
```

- Use factory states for readable test setup:

```php
Proposal::factory()->analyzed()->forUser($user)->create();
```

- Use `Exceptions::fake()` to assert exceptions were thrown without crashing the test

### Freeze time in any test whose subject is a time WINDOW or a cache TTL

If what the test asserts is defined by a period — a rate-limit window, a
`Cache::remember()` TTL, a "stale after N minutes" reaper, a job's `retry_after`
— call `$this->freezeTime()` first. There's nothing to undo: a freeze in one
test does not leak into the next (verified 2026-08-07 — `Carbon::hasTestNow()`
is already false in the following test, so no `travelBack()` is needed). Never
let such a test depend on where the wall clock happens to be while it runs.

Real incident (2026-08-07): `it('throttles repeated login attempts')` failed
**~35% of runs** (measured: 3 of 8 on a clean tree). It fires 6 bad logins
against `throttle:6,1` and expects the 7th to be 429; it intermittently got 200,
i.e. the login went through.

Two things combined, and both are worth knowing:

1. **`RateLimiter::tooManyAttempts()` silently RESETS the counter.** It only
   reports "too many" while a companion `{key}:timer` cache entry still exists;
   if the counter is at/over the limit but that timer is gone, it calls
   `resetAttempts()` and returns **false**. Counter and timer share one TTL
   (60s for `throttle:6,1`), so anything that drops them mid-test doesn't just
   lose the count — it hands out a fresh allowance.
2. **The host clock can step.** Logging `time()` and `Carbon::now()` after each
   request caught one request reporting a timestamp **66 seconds ahead** of the
   requests immediately before *and* after it, with `Carbon::hasTestNow()`
   false — so nothing in the app or the suite was travelling in time; the OS
   clock jumped forward and back (this dev box is WSL2, `timedatectl` reports
   `System clock synchronized: no`). 66s > the 60s TTL, so both entries expired
   and the counter restarted. An earlier symptom of the same thing was an array
   cache entry whose `expiresAt` sat 127s in the future for a 60s TTL.

Freezing fixes it for the right reason, not just because it hides a bad clock:
on a slow CI box six bcrypt hashes could legitimately straddle a real minute
boundary and reset the window, which would be an equally bogus failure.

Debugging recipe when a time-ish test flakes: log `time()`,
`Carbon::now()->getTimestamp()` and `Carbon::hasTestNow()` at each step. If the
first two agree with each other but jump around, it's the machine, not the code
— and a tight `microtime(true)` loop will NOT reproduce it, because the step
happens between requests, not inside a burst.

## Media (Spatie MediaLibrary)

Only 4 models use `HasMedia`/`InteractsWithMedia`, each with its own single collection and its own purpose — there is no generic shared collection/conversion pair to reuse:

- `User` — `avatar` (single-file), with one registered conversion, `thumb` (120×120, `nonQueued()` since the source is tiny). `User::avatarUrl()` falls back to `ui-avatars.com` (an external, third-party image, requested client-side from the `<img src>`) when no avatar was uploaded — a deliberate, low-risk default, not an oversight.
- `Solution` — `context_documents` (`Solution::CONTEXT_COLLECTION`), the "Assiste IA" context documents (PDF/image/text), served by `SolutionContextDocumentController` — never through `MediaController`/`files.show`.
- `Integration` and `DocumentationPage` — both implement `App\Contracts\Documentable` and share the `docs` collection (`Documentable::DOCS_COLLECTION = 'docs'`): images/files embedded in Markdown documentation, referenced as `/files/{id}` and served by `MediaController`/`files.show` (authenticated) or `PublicDocumentationController::file()` (magic-link, token-scoped).

No model has more than one conversion, and nothing uses a `->image()` accessor. **`Solution` and `Company` logos are NOT MediaLibrary** — `logo_path` is a plain string column, uploaded via `$request->file('logo')->store('{solution,company}-logos', 'public')` directly in `SolutionController`/`CompanyController`, a deliberately simpler mechanism since a logo needs no conversions/metadata.

Avatar/logo uploads (the six Person/Solution/Company Store+Update requests) all share `['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']`, and `avatar-upload.js` mirrors that list client-side so a doomed file never gets an encouraging preview — **keep the two in step**. `accept="image/*"` on the input is only a picker hint and enforces nothing. SVG is intentionally absent: Laravel 13's bare `image` rule rejects it unless written `image:allow_svg` (so it never actually worked, even while `mimes:` still listed it), and an SVG served from the public disk executes its own scripts when opened directly by URL. Documentation media is a different rule (`file`, not `image`) and **does** accept SVG.

Never register a new collection/conversion without checking the 4 above first — and note `MediaController::show()`'s guard should compare against `Documentable::DOCS_COLLECTION`, not a hardcoded `'docs'` literal (the two happen to match; don't let them drift apart silently).

### SSRF surface — documentation editor's "paste image URL"

`EditsDocumentation::storeDocumentationMedia()` (shared by `SolutionDocumentationController`/`IntegrationDocumentationController`/`DocumentationGroupPageController`) has two upload paths: a multipart `file`, or a `url` the SERVER downloads via Spatie's `addMediaFromUrl()` (Editor.js's Image plugin "paste a URL" flow). `UploadDocumentationMediaRequest` only validates `starts_with:http://,https://` — same as Spatie's own internal check — with **no private/loopback/link-local guard**, so without `App\Rules\PublicUrl` (validates the resolved IP via `FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE`) an admin could make the server fetch an internal-only URL (cloud metadata endpoint, internal admin panel, etc.) — exploitability is admin-scoped (only `update` on Solution/Integration/DocumentationGroupPage reaches this), but still real. The guard resolves DNS at validation time, so it does **not** close a DNS-rebinding race (attacker's DNS answers public at validation, private moments later at fetch time) — accepted as a documented residual risk, not something this rule claims to solve.

## Style

- Use Laravel helpers over facades where shorter: `auth()`, `request()`, `now()`, `rescue()`
- Follow Laravel naming conventions: `StoreProposalRequest`, `ProposalPolicy`, `AnalysisSeeder`
- Prefer readable, expressive syntax over clever one-liners

---

## JavaScript — use modules before creating new ones

Before writing any new JS behavior, check if an existing module in `resources/js/modules/` already handles it. The project has modules for: toggle, tabs, side panel, AJAX form submission, filters, search, chips (multi-select with autocomplete), and more. (Modules inherited from akop-pro with zero consumers — mask, standalone autocomplete, copy-content, url-location, event-helpers, string-helpers, search-in-container, switch-button, radio-group — were removed on 2026-07-16; they weren't even part of `app.js`'s bundle. `file-upload.js` was removed on 2026-07-27 for the same reason, just discovered later — it WAS registered in `app.js`'s `globalModules`, but no Blade view ever rendered its `data-ak-file-upload` hook; actual image/logo upload UI goes through `avatar-upload.js`/`<x-forms.image-upload>` instead. `solution-attributes.js` went on 2026-08-15, for a different reason: its one consumer — the Solution header's 8 attribute badges — moved to `inline-edit.js`, which left it with no `data-ak-solution-attribute` hook anywhere in the app.)

Only create a new module when the behavior is genuinely not covered. When creating one, follow the delegation pattern:

```js
// Prefer pure event delegation (no init() guard needed)
document.addEventListener('click', (e) => {
    const trigger = e.target.closest('[data-ak-my-thing]')
    if (!trigger) return
    // handle
})

export function init() {} // no-op — keeps globalModules interface
```

For modules that need per-element initialization (e.g., mounting a library instance), use WeakSet:

```js
const initialized = new WeakSet()
export function init() {
    document.querySelectorAll('[data-ak-my-thing]').forEach((el) => {
        if (initialized.has(el)) return
        initialized.add(el)
        // initialize
    })
}
```

Register new modules in `window.globalModules` in `app.js`.

After slot updates, prefer re-initializing only the affected modules instead of all:

```js
// Re-initialize everything (called automatically after slot updates)
window.initAllModules()

// Preferred after partial updates — only re-init what's needed
window.initListOfModules(['toggle', 'avatarUpload'])
```

All JS hooks use `data-ak-*` attributes. Internal component slots (`data-spinner`, `data-label`, `data-content`, etc.) are exempt.

## data-ak-* attribute reference

All JS hooks use the `data-ak-*` prefix. Internal slots (`data-spinner`, `data-label`, `data-content`) are private to their component and exempt.

| Attribute | Module | Description |
|---|---|---|
| `data-ak-ajax="form-id"` | `ajax-post.js` | Triggers AJAX form submission on click |
| `data-ak-action="url"` | `ajax-post.js` | POST destination URL |
| `data-ak-confirm="msg"` | `ajax-post.js` | `window.confirm` gate before the AJAX fires |
| `data-ak-toggle="element-id"` | `toggle.js` | ID of element to toggle. Anything with id `<element-id>-opened-state` / `-closed-state` has its `hidden` flipped along with it — each independently, so a target may have only one of the two (the docs rail has no "opened" counterpart: collapsing it only ADDS the solution crumb to the top bar) |
| `data-ak-toggle-classes="cls"` | `toggle.js` | Space-separated classes to toggle on the target. A class may be deferred with `class:<ms>` (`hidden:300`) — only an **all-digits** suffix counts, so Tailwind variants (`md:!w-0`, `max-md:!translate-x-0`) pass through untouched. Splitting on the first `:` instead is what silently broke the documentation editor's pages rail until 2026-08-15: it toggled a class literally named `md` |
| `data-ak-toggle-event="click"` | `toggle.js` | Trigger event (default: `click`) |
| `data-ak-toggle-once` | `toggle.js` | Fire only once |
| `data-ak-toggle-blur="true"` | `toggle.js` | Close on outside click |
| `data-ak-toggle-mouseout` | `toggle.js` | Also toggle on mouseout |
| `data-ak-panel-open` | `side-panel.js` | Opens `#side-panel` |
| `data-ak-panel-url="url"` | `side-panel.js` | URL to fetch content from |
| `data-ak-panel-close` | `side-panel.js` | Closes `#side-panel` and clears content |
| `data-ak-panel-overlay="false"` | `side-panel.js` | Open without overlay |
| `data-ak-panel-size="small\|medium\|large"` | `side-panel.js` | Panel width — 1/4 (`w-96`, default), 1/2, or 3/4 of the viewport |
| `data-ak-tabs='{"targetId":"…"}'` | `tabs.js` | JSON config for tab switching |
| `data-ak-filters='{"formId":"…"}'` | `execute-filters.js` | JSON config for filter execution |
| `data-ak-filters-clear='{"formId":"…","field":"filter[x]","url":"…"}'` | `execute-filters.js` | Clears one filter field (active-filter chip ✕) and resubmits via AJAX |
| `data-ak-filters-clear-all='{"formId":"…","url":"…"}'` | `execute-filters.js` | Clears every `filter[...]` field except `filter[sort]` and resubmits ("Limpar tudo" / empty-state action) |
| `data-ak-filters-loading` | `execute-filters.js` | Shown (removes `hidden`) while a filter/search AJAX request is in flight |
| `data-ak-filters-dim` | `execute-filters.js` | Dimmed (`opacity-50 pointer-events-none`) while a filter/search AJAX request is in flight — put on a persistent wrapper *around* the swapped slot, not the slot node itself, since the slot node gets replaced wholesale |
| `data-ak-search='{"inputId":"…"}'` | `execute-search.js` | Debounced (350ms) search-as-you-type; searches immediately on Enter |
| `data-ak-search-hint="input-id"` | `execute-search.js` | Element whose text is set to the "digite N+ letras" hint while 1–2 chars are typed, cleared otherwise |
| `data-ak-avatar-upload='{"inputId":"…"}'` | `avatar-upload.js` | Avatar upload trigger |
| `data-ak-top-nav` | `top-nav.js` | Element that receives scroll shadow |
| `data-ak-inline-edit="{action,method}"` (root) + `-read`/`-open`/`-form`/`-confirm`/`-cancel`, and `data-ak-inline-edit-field="<field name>"` on each input | `inline-edit.js` | Edit-in-place on an otherwise read-only page — read mode is whatever `<x-ui.inline-edit>`'s caller rendered, and it opens on a **double click** or on a single click of the pencil button (`-open`) beside it; a single click over the value is left to reading/selecting/the ↗. A creator ("+ Adicionar …", `method: POST`) is the exception — its read mode is a chip, so one click opens it. The editor is one field (or a few, for the creators) plus a discreet confirm/cancel icon pair. Enter confirms, Esc cancels, Ctrl/Cmd+Enter in a textarea; clicking away closes it if nothing was typed. `method: POST` makes the same component a creator. The payload is built from the field hooks' own names, so there's no second list to keep in step. Reference implementation: the whole person detail page (`People\DetailHeader`, `People\Notes`, `People\Systems`) — text/select/textarea/photo, plus add/remove of contacts and system links |
| `data-ak-inline-edit-link` (on the ↗ anchor `x-ui.external-link` renders) | `inline-edit.js` | Escape hatch for the one place where navigation and editing compete — see "A datum that links somewhere" below |
| `data-ak-contacts` (root, + `data-ak-contacts-next` counter) + `data-ak-contacts-list`/`data-ak-contact-row`/`data-ak-contact-add`/`data-ak-contact-remove` | `person-contacts.js` | Additional-contacts repeater on the Person form — add/remove rows client-side; synced server-side by `PersonController::syncContacts()` |
| `data-ak-chips` (root) + `data-ak-chips-input`/`data-ak-chips-list`/`data-ak-chips-results`/`data-ak-chips-result`/`data-ak-chip`/`data-ak-chip-remove` | `chips.js` | Multi-select-with-autocomplete chips input (e.g. Person↔Solution role linking) |
| `data-ak-chips-add='{"name":"…","value":"…","label":"…"}'` | `chips.js` | Adds a chip straight to the named field from a JSON config, skipping the picker overlay entirely (e.g. flowSpec's "adicionar ao contexto" suggestion buttons, `thread.blade.php`) |
| `data-ak-chips-trigger` | `chips.js` | Opens the chips picker overlay; can be triggered externally via `.click()` from outside the component (e.g. flowSpec's 📎 attach menu, `data-ak-fs-open` below) |
| `data-ak-integration-select="slug"` (on a row) + `data-ak-integration-list` (on the container) | `integration-select.js` | Selects an integration row (`aria-pressed`), dispatches `ak:integration-selected` `{name, slug, graph}` |
| `data-ak-flowspec-poll="status-url"` | `flowspec-chat.js` | Presence in the thread slot = a reply is still generating; module polls the URL every 2.5s (capped at `MAX_POLL_ATTEMPTS`) until the slot swap removes this marker |
| `data-ak-flowspec-copy="pre-id"` | `flowspec-chat.js` | Copies the target element's `textContent` (not `innerHTML` — the flowSpec JSON's `jsonPath` has literal `&&`) to the clipboard |
| `data-ak-fs-*` (`-input`/`-send`/`-pills`/`-menu`/`-open="name"`/`-toggle-reference`/`-reference-input`/`-reference-pill`/`-reference-clear`/`-scroll`) | `flowspec-chat.js` | Private hooks of the flowSpec composer (`resources/views/components/flowspec/composer.blade.php`) — message textarea, 📎 attach menu (`-open="name"` clicks the matching chips field's `-trigger`), reference-flowSpec panel toggle/clear. Not reused outside that component |
| `data-ak-docs-chat-*` (`-input`/`-send`/`-scroll`/`-trigger`/`-status`) | `docs-chat.js` | "Assiste IA" as a CHAT (mirrors the flowSpec F8 composer): message textarea, send, scroll container, the button that opens the panel, and the "gerando…" indicator |
| `data-ak-docs-chat-poll` | `docs-chat.js` | Presence in the thread slot = a reply is still generating; the module polls until a slot swap removes this marker, with a give-up ceiling + Toast (same contract as `data-ak-flowspec-poll`) |
| `data-ak-docs-chat-lock` | `docs-chat.js` | Locks the editor while a generation runs, so the draft can't be applied onto content that shifted underneath it |
| `data-ak-docs-chat-resume` | `docs-chat.js` | Server-rendered marker: this user has an unresolved generation for this page/integration. On load the module re-locks + polls a pending one, or reopens the review for a finished one — this is what survives navigating away mid-generation |
| `data-ak-docs-chat-draft` / `-view-draft` | `docs-chat.js` | A reply's draft block (4-backtick fenced convention) and the button that opens it for review |
| `data-ak-docs-chat-review-template` + `-review-body` / `-review-close` / `data-ak-docs-chat-apply` | `docs-chat.js` | `<template>` cloned into `#main-modal` to review the draft as a diff (`docs-diff.js`) before it touches the editor. Apply is the intended exit; see the `closeOnEsc = false` caveat under Modal — the `close`-event handler is the real guarantee, since a draft left unresolved comes back on the next load |
| `data-ak-context-doc` (on a checkbox, `value`=media id) | `docs-chat.js` | A Solution context document the AI should consider; checked ids are sent as `media_ids[]` |
| `data-ak-context-upload` (on a file input, + `data-action="url"`) | `docs-chat.js` | Uploads the chosen context document immediately on `change` — there's no separate "Anexar" button, which users kept skipping |
| `data-ak-context-uploading` | `docs-chat.js` | "Enviando documento…" indicator for the upload above |
| `data-ak-docs-editor` (`data-config='{"uploadUrl":"…"}'`) | `docs-editor.js` | Editor.js mount point for a documentation page/integration; the module also self-tags the same element `data-ak-docs-holder` once mounted, for its own click-outside scoping |
| `data-ak-docs-source` | `docs-editor.js` | Hidden `<textarea>` with the raw Markdown the editor is built from on mount |
| `data-ak-docs-save` | `docs-editor.js` | Save button — the one thing `Ctrl/Cmd+S`, autosave and `setEditorLocked()` (raised by `docs-chat.js` during a generation) all enable/disable together |
| `data-ak-docs-status` | `docs-editor.js` | Autosave feedback text ("Salvando…"/"Salvo") |
| `data-ak-docs-toc` (nav target) + `data-ak-docs-content` (scope, read-only view) | `docs-toc.js` | "Nesta página" headings navigator — reads live Editor.js headings while editing, or the `.html-content`/`data-ak-docs-content` permalinks read-only |
| `data-ak-docs-copy` | `docs-copy.js` | "Copiar Markdown" button — reads `window.__akDocsGetMarkdown()` while editing, or the `data-ak-docs-markdown` textarea on the read-only view |
| `data-ak-docs-markdown` | `docs-copy.js` | Hidden `<textarea>` with the raw Markdown, rendered only on the read-only view (no live editor there to query) |
| `data-ak-node-kinds` (on the integrations-map root) | `integration-viz.js` | `App\Enums\ChainNodeKind` as JSON (`{value,label,system,placeholder}`), read once and cached: feeds the kind `<select>` of both block panels. `system` is the only kind that gets the Solution select |
| `data-ak-solutions`/`data-ak-protocols` (on the integrations-map root) | `integration-viz.js` | Same read-once-and-cache pattern as `data-ak-node-kinds` — the Solution/protocol option lists (JSON) feeding the block/edge editor panels. There is no `data-ak-statuses` any more: the integration's own status left the canvas for the page's top bar (see below) |
| `data-ak-integration-summary` (on an integration row) | `integration-viz.js` | Patched via `replaceChildren(document.createTextNode(...))` (never `innerHTML`) after a chain mutation, so the row's chain summary stays current without a page reload |
| `data-viz-*` (F3 canvas internals, e.g. `-toolbar`/`-toolbar-rename`/`-zoom-in`/`-lane-toolbar`) | `integration-viz.js` | Private hooks of the F3 canvas component, NOT `data-ak-*` — they're internal slots of `integration-viz.blade.php`, same exemption as `data-spinner`/`data-label`. `-toolbar-rename` opens the same inline label editor as double-clicking a block, which is the only path a touch device has (see the touch note below) |

### Inline edit — a datum that links somewhere: the ↗ navigates, the text edits

On a page where every datum is editable, a datum that also points at another
record (a person's company, the system a "Sistemas" row links to) has two
competing gestures over the same words. The rule, app-wide:

- **The text always belongs to the editor** — same gesture as every other
  datum on the page, so nothing is an exception the user has to learn. Since
  2026-08-14 that gesture is a **double click** (or the pencil button beside
  the value), which is also what keeps the single click free for reading.
- **Navigation moves to an ↗ icon** (`x-ui.external-link`,
  `heroicon-o-arrow-top-right-on-square`) next to the value, always visible
  (unlike the pencil, which appears on hover — and is permanently visible only
  where there is no hover, `pointer-coarse`).

So pass `<x-ui.inline-edit :link="route(...)" link-label="empresa">` and render
the value as **plain text inside the slot — never as its own `<a>`**. Two click
targets over the same words is precisely the ambiguity the split removes. The
earlier `trigger="icon"` prop did the opposite (link stayed, only a pencil
edited) and is gone — the pencil is back, but as one of two ways in, not as the
only one.

Two things this leans on, easy to break by accident:

- `data-ak-inline-edit-link` on the anchor is load-bearing. The ↗ sits *inside*
  the read block, which double-clicks into the editor, so `inline-edit.js`'s
  `dblclick` listener bails out early on it — an impatient double click on the
  icon would otherwise open the editor on top of the navigation the user asked
  for. The `click` listener checks it too, for a creator's read block (the one
  read mode that IS a single-click trigger).
- `x-ui.external-link` carries **no color utility on purpose** (it inherits the
  surrounding text color), and `x-ui.inline-edit` renders it in *both* branches
  — the read-only one included, since a viewer has no editor and the ↗ is then
  the only way to reach the linked page.

Reference implementations: `People\DetailHeader` (company), `People\Systems`
(one row's system, whose editor re-points the link — that's why
`people.solutions.update` is the one route in that trio with
`scopeBindings()`), `Companies\DetailHeader` (the website, the only outbound
link in the app — hence the `link-attributes` prop carrying
`target`/`rel`), and `Solutions\DetailHeader` (the vendor chip).

### Inline edit — the editor has to look like the value it replaces

The whole promise of an edit-in-place page is that nothing moves and nothing
changes register: you double-click words and keep looking at the same words.
Four things carry that, and each is easy to undo by accident:

- **Typography.** `x-ui.inline-edit`'s `input-class` prop (per-field:
  an `inputClass` key inside `fields`) makes the editor wear the read value's
  type — the three headers pass their h1's own
  `!font-display !text-[28px]/[32px] !font-bold …`. Skip it and a 32px heading
  is retyped in 14px body text, which is the single most jarring thing this
  page can do. Everything read at the app's default `text-sm` needs nothing.
- **Chrome — the editor draws no box.** Since 2026-08-14 `x-ui.inline-edit-field`'s
  one `$chrome` string has **no border, no white fill, no shadow and no focus
  ring**: the open editor's ground IS read mode's own hover wash (`--ie-wash`,
  which simply doesn't leave), plus a 1.5px rule underneath (`--ie-rule`). The
  reason is that hover promised one thing and clicking used to deliver another
  (a white bordered box with a shadow) — a jump of register in the middle of a
  page you're reading. Three details are load-bearing:
  - `var(--ie-wash)` is read **on the control**, never aliased into a `:root`
    variable of its own. A custom property's `var()` is substituted on the
    element that DECLARES it, so `--ie-edit-bg: var(--ie-wash)` in `:root`
    would hand the whole app the ink tint and lose the translucent white the
    three detail headers set on their pastel strip. (Verified: the editor on a
    header strip computes `rgba(255,255,255,.5)`, on a white card ink-5%.)
  - the rule is an **inset box-shadow, not a `border-bottom`** — a border adds
    1.5px of height and shoves the text up as the editor opens. It's repeated
    in the `focus:` variant only to beat `x-forms.input`'s own accent ring,
    which would otherwise be the state the user actually sees (the editor opens
    focused).
  - `placeholder:!italic` is not decoration: with no border, a blank field's
    editor would be a caret alone on the page, so the italic faint placeholder
    (the field's label) is what anchors the eye — the same treatment read mode
    gives "Não informado". A `select` leans on `x-forms.select`'s chevron for
    the same reason, which is why `!pr-7` keeps its gutter.

  Every utility in `$chrome` is `!`-marked — it has to beat the same utility
  inside `x-forms.input`/`select`/`textarea`, and both strings land in one class
  attribute where CSS order, not authoring order, decides. (Leading-`!` still
  compiles in Tailwind 4.3, including inside a variant — `focus:!shadow-[…]`
  emits correctly; confirmed against the built stylesheet.)
- **Geometry.** Confirm/cancel (`x-ui.inline-edit-actions`) sit *beside* a
  one-line field so the block keeps its height and the page below it doesn't
  jump; they only move under the field when it's a textarea, an image, or a
  multi-field creator, which are already changing the height. The editor row's
  `-ml-1.5` cancels the input's own padding so the text doesn't slide sideways
  as it opens — that number, the editor's `!px-1.5` and read mode's
  `-mx-1.5 px-1.5` are **one measurement in three places**, and the alignment
  is only exact while they agree (measured: 0.0px of horizontal travel from
  read to edit on every field of the person header). The `file` editor's tile
  takes the size AND shape (`image-shape`) of the avatar/logo it replaces.
- **The wash.** The hover/focus tint is `--ie-wash` (app.css), overridden to a
  translucent white by the three detail headers' gradient strips and by the
  Solution's notes post-it — an ink tint reads as a grey smudge over a pastel
  or a warm ground. It is now the edit ground too, so a ground that overrides
  it moves both states at once, on purpose.

**Nothing on these pages looks like a form until you ask to edit it.** That
includes the Solution header's 8 attribute badges, which until 2026-08-15 were
the exception: bare `<select>`s that auto-saved on `change` (`solution-attributes.js`,
now deleted along with its `data-ak-solution-attribute` hook). They're
`x-ui.inline-edit type="select"` like everything else now — the read mode is the
same tone-coloured chip a viewer sees, the editor keeps the chip's typography
via `input-class`, and the endpoint stays `solutions.attributes.update` (they're
`attribute_options` values validated per group, not solution columns). If you
add another auto-saving control here, you're re-opening a question this page has
already answered.

Two traps, both hit on 2026-08-14 and both invisible in review:

- **Read mode must not carry a `display` utility.** `inline-edit.js` hides it
  by toggling Tailwind's `hidden`; a sibling `display` utility on the same
  element (`inline-block`, added so the wash would hug the text) turns that
  into a coin toss decided by stylesheet order — it lost, and every creator
  opened its editor UNDER a chip that was still on screen. Width and shape
  belong on the `<span>` inside the read block, which is where the wash lives
  for exactly this reason. Guarded by a test in `PersonInlineFieldUpdateTest`.
- **No percentage `max-width` on that span.** An inline-flex box already
  shrink-to-fits; `max-w-full` inside a shrink-to-fit parent (a cell of the
  contacts strip, a row of the systems list) resolves against a width that
  isn't known yet and collapses to min-content — "Não informado" breaks across
  two lines.

Behaviour that goes with it, in `inline-edit.js`: clicking away closes an
editor **only when nothing was typed** (a half-written value is what the user
can't get back), and opening a `select` calls `showPicker()` so choosing is one
gesture, like the photo tile that opens the file picker straight away.

The gesture that opens it is deliberately two-part, and the parts are not
interchangeable — don't collapse them back into a single click on the value:

- **Double click on the value**, so a single click keeps meaning what it means
  everywhere else (select a phone number to copy it, hit the ↗). `dblclick`
  clears the word the browser just selected before opening, or that highlight
  and the editor's own pre-selected value overlap and read as a glitch.
- **Single click on the pencil**, which is a real `<x-forms.button>` and
  therefore the path a keyboard (Tab, then Enter/Space — nothing in the module
  handles that) and a finger both take. That's why it's `focus-visible:opacity-100`
  and `pointer-coarse:opacity-100`/`!size-8`: a hover-only affordance doesn't
  exist on touch, and double-tap is not a gesture to rely on there.

The read block itself is no longer `role="button"`/`tabindex="0"` — only a
creator's chip is, since one click is genuinely its whole interaction.

### The three detail pages: one field endpoint each

`people.field.update`, `companies.field.update` and `solutions.field.update`
(+ `Update{Person,Company,Solution}FieldRequest`) are the same endpoint three
times: every rule is `sometimes` because the header sends only the field just
confirmed, `slug` is deliberately absent (renaming must not move the URL the
request is rendering), `prepareForValidation()` normalises `''` → `null` for
every nullable field (a multipart request can't carry a JSON null), and the
response returns that page's `DetailHeader::slot()` — never the catalog's index
slot, which doesn't exist on a detail page.

Two things to keep in step when touching them:

- Text-column rules must not be **stricter** than the panel's
  (`Update{Person,Company,Solution}Request`) for the same column, or a value
  the panel accepted can no longer be re-saved inline.
- `logo`/`photo` uploads ride along with a `{name}_action` hidden input
  (`x-forms.image-upload`'s "Remover"). It must be validated *and* excluded
  from the mass-assign array — leaving it in throws under
  `Model::shouldBeStrict()`'s `preventSilentlyDiscardingAttributes` (a 500 in
  dev/test). The panel `payload()` of all three does the same, which is what
  finally made that "Remover" button do something.

### Relations edited in place: one card = one slot = one pair of endpoints

Every detail page also attaches/detaches its relations without the panel. The
shape is always the same — a `+ Adicionar …` creator (`x-ui.inline-edit` with
`method="POST"`), a hover-revealed ✕ per row (**`x-ui.row-remove`**: the hidden
`<form>` with `@csrf`/`@method('DELETE')` plus the ghost `data-ak-ajax` button,
in one component), and a View Component whose `slot()` the mutation answers
with:

| card | relation | endpoints | slot |
|---|---|---|---|
| `People\Systems` | `person_solution` pivot (+ role badge) | `people.solutions.{store,update,destroy}` | `person-systems-slot` |
| `People\DetailHeader` (contacts strip) | `Person::contacts()` | `people.contacts.{store,update,destroy}` | `person-detail-header-slot` |
| `Companies\People` | `Person.company_id` | `companies.people.{store,destroy}` | `company-people-slot` |
| `Companies\ProvidedSolutions` | `Solution.vendor_company_id` | `companies.solutions.{store,destroy}` | `company-solutions-slot` |
| `Solutions\DetailHeader` (owners grid) | `person_solution` pivot | `solutions.people.{store,update,destroy}` | `solution-detail-header-slot` |

Things that are easy to get wrong here:

- **The pivot's unique index is `(person_id, solution_id, role)`**, so the
  DATABASE happily accepts the same person twice under two roles — while the
  rest of the app assumes one link per (person, solution)
  (`Person::solutions()` would list the solution twice, and
  `updateExistingPivot` would hit both rows). Both store requests
  (`StorePersonSolutionRequest`, `StoreSolutionPersonRequest`) carry a
  `Rule::unique` that is deliberately NOT scoped to the role. Don't "fix" it by
  adding the role.
- **A HasMany detach nulls a foreign key on the CHILD record**, so those routes
  are `scopeBindings()`: without it, `DELETE companies/{a}/people/{person}`
  would unlink someone who belongs to company `{b}`. The pivot detaches don't
  need it (`detach` no-ops on a row that isn't there). `{providedSolution}` is
  named for the relation the scoped binding resolves through — Company has no
  `solutions()`.
- **A pivot UPDATE does need scoping**, unlike its detach: `people.solutions.update`
  and `solutions.people.update` both READ the link they're replacing (the pivot's
  identity is the pair, so re-pointing one is detach + attach, carrying `role`
  and `is_primary` over). Without `scopeBindings()` the route would resolve a
  person/solution with no pivot at all and the "carry over" would read from thin
  air.
- **The card components use `loadMissing()`**, so the controller must `load()`
  the mutated relation *before* rendering the slot, or the card answers with the
  pre-mutation copy. That's why those helpers take a closure and reload first
  (`PersonController::fieldSaved`, `CompanyController::relationSaved`,
  `SolutionController::ownersSaved`).
- A picker that has **nothing left to offer** hides itself
  (`$canEdit && filled($options)`) instead of rendering a select whose only
  option is what's already there. A picker that can move a record away from
  another owner puts that owner in the option label (`Ana Silva — Outra
  Empresa`), so the move is never made blind.
- **A row that can be edited must never show two ✕ at once.** The moment an
  `x-ui.inline-edit` inside a row opens, its cancel lands next to the row's
  unlink — the same glyph, one recoverable and one not. `x-ui.row-remove`
  handles it for every card at once with
  `group-has-[[data-ak-inline-edit-form]:not(.hidden)]/row:invisible`, which
  reads the state `inline-edit.js` already publishes instead of inventing a
  second one. Two things it depends on: the enclosing row must be named
  **`group/row`** (the contacts strip said `group/contact` and the rule would
  have silently idled), and the utility is `invisible`, not `hidden` —
  `visibility` keeps the row's height (no reflow as the editor opens), never
  fights `x-forms.button`'s own `inline-flex`, beats the hover `opacity-100`
  without an `!` (different property), and drops the button from the tab order
  so Tab from the open field can't reach the destructive one.

The solution header speaks to **three** endpoints through the one
`x-ui.inline-edit` gesture — its own columns (`solutions.field.update`), the 8
attribute badges (`solutions.attributes.update`), and the owners grid
(`solutions.people.*`). Three requests, not three interactions: the header is
read-only text until something is double-clicked, everywhere.

The owners grid's row editor changes WHO holds a role, not which role — the role
is the column the row sits in, and a role picker inside a column already titled
with one has nowhere honest to live. Re-roling stays on the person's page (where
the role is a badge of its own), even though `UpdateSolutionPersonRequest`
validates `role` too, to stay symmetric with its mirror.

### An integration's name and status live in the editor's top bar — nowhere else

`Solutions\IntegrationMeta` (slot `integration-meta-slot`) renders both in the
top bar of the integration's unified documentation+diagram page, as two
`x-ui.inline-edit`s pointing at the same endpoint
(`solutions.integrations.update` / `UpdateIntegrationMetaRequest`, whose rules
are `sometimes` + `required` so one field can be confirmed without blanking the
other — same shape as the three detail pages' field requests). The response
returns that slot **and** `PagesNav::slot(...)`, since the rail beside it lists
the integration by name.

The canvas had a second editor for exactly these two fields (a pencil in its
own top bar opening a panel over the diagram) until 2026-08-17. It's gone, and
so is its `data-ak-statuses` payload and the canvas's own `data-viz-title` —
two editors of one field desync on the first edit, and the canvas title became
the stale copy the moment the name above it became editable. If you find
yourself adding a status control to the canvas again, this is the question
being reopened.

Everything else about the integration is still authored on the canvas: the
topology is the chain, and only `SyncIntegrationFromChain` derives from it.

### Empty states: `x-ui.empty-state` + inlined unDraw illustrations

A list with nothing in it gets `x-ui.empty-state` (illustration + what's
missing + what to do), not a line of grey text — see both columns of the
solution detail card. `illustration="foo"` resolves to
`x-illustrations.empty-foo` under `resources/views/components/illustrations/`:
an unDraw SVG (free license, no attribution required) inlined into a Blade
component, with its `width`/`height` stripped, its primary color rewritten to
`currentColor` and its greys/darks to `--color-line` / `--color-ink` /
`--color-surface`. Keep new ones on that recipe — a linked or unrecolored SVG
costs a request and reads as someone else's artwork. Illustrations don't share
an aspect ratio, so the caller caps the one it uses (`illustration-class`).

### F3 canvas gestures run on POINTER events — never add a `mouse*` listener

Every gesture in `integration-viz.js` (block drag, drag-an-arrow-out-of-a-port,
retarget an arrow tip, move/resize a swimlane, move a post-it, pan the canvas)
is registered as `pointerdown`/`pointermove`/`pointerup`, which fire for mouse,
touch and pen with one code path. There is exactly ONE drag dispatcher — two
`window` listeners switching on `drag.type` — plus per-element `pointerdown`
initiators that only assign `drag = {...}`.

**Don't reintroduce `mousedown` on anything inside the viewport.** Touch events
are a separate stream from mouse events, so a `mousedown`-based
`stopPropagation()` guard does nothing for a finger: the touch reaches the
viewport anyway and pans the canvas instead of dragging the block. That exact
bug is why the old dedicated `touchstart`/`touchmove`/`touchend` pan was
REMOVED rather than kept alongside the pointer listeners (keeping both makes
the pan run *simultaneously* with the drag). `.ak-viz-viewport` sets
`touch-action: none`, which is what stops the browser from stealing the gesture
to scroll. The two remaining `mousedown` listeners are the autocomplete
suggestion rows, whose `preventDefault()` preserves input focus and whose
parent editor already stops propagation.

`pointercancel` must stay wired next to `pointerup`: a touch pointer can be
cancelled by the browser (system gesture, second finger) without ever firing
`pointerup`, and since a drag lives in `drag` until an end-event clears it,
dropping that leaves the canvas stuck mid-drag until a reload. On cancel only
the *actions* are abandoned (completing a link, retargeting a tip, click-to-
select); anything already moved keeps its new position and is marked dirty.

Two consequences worth remembering when adding UI here: hover-only affordances
don't exist on touch (ports are revealed by `.is-selected` too, which is why
tap-to-select-then-drag works), and `dblclick` has no touch equivalent — so
anything reachable only by double-click needs a second path, which is what
`data-viz-toolbar-rename` is for. Precision targets get a bigger hit area via
`@media (pointer: coarse)` in `integration-viz.blade.php`.

## `ajax.js` — Promise contract, not XHR

`resources/js/modules/ajax.js::init(method, url, formData?)` is an
**async, `fetch`-based function** — it returns a `Promise<Response>`
(rejects on `!response.ok`, with `error.response` attached). It **doesn't**
have the `XMLHttpRequest` API (`.onload`, `.status`, `.response`, `.send()`)
— that's a leftover from the old, XHR-based `ajax.js` from akop-pro.

```js
// Correct
ajaxModule.init('GET', url)
    .then((response) => response.json())
    .then((data) => updateSlots(data))
    .catch((error) => { /* error.response, if it came from a non-2xx status */ })

// Broken — ajaxObj is a Promise, it has no .onload/.send()
let ajaxObj = ajaxModule.init('GET', url)
ajaxObj.onload = function () { ... }
ajaxObj.send()
```

Real incident (2026-07-02): `execute-filters.js::applyFilters()`,
`modal.js::loadFromURLAndOpen()`, and `avatar-upload.js::uploadAddedImage()`
(removed — dead code, never called) still used the old API against the
already-rewritten `ajax.js` — every filter/search click (Solutions, People,
Companies) threw `TypeError: ajaxObj.send is not a function`, silently
swallowed by the listener, leaving search/filter with no effect at all.
`ajax-post.js` was the only already-correct consumer (uses `await`). When
adding a new consumer of `ajaxModule.init()`, always treat the return value
as a Promise.

## AJAX — Form submission

The `ajax-post.js` module intercepts **clicks** on `[data-ak-ajax]` and also form **submit** (Enter or native submit), preventing the native submission in both cases.

```html
<form id="my-form">
    @csrf
    <!-- fields -->
</form>

<x-forms.button data-ak-ajax="my-form" data-ak-action="{{ route('my.route') }}">
    <span data-label>Salvar</span>
    <span data-spinner class="opacity-0 absolute">...</span>
</x-forms.button>
```

No `onsubmit` needed on the form. Enter and click both work automatically.

### Never pass `type="button"` on a button with `data-ak-ajax`

The click still works either way, but **Enter stops working**, with no
visible error. The cause is the HTML, not the module: implicit submission
(Enter inside a text field) only fires if the form has a **submit** button.
Without one, the browser only auto-submits when there's exactly one text
field — so the bug disappears in single-field forms and shows up in forms
with two or more, which makes it look intermittent.

With no `submit` button there's no `submit` event, so `ajax-post.js`'s
listener never runs.

```blade
{{-- ✅ the default — omit `type` and the component already uses submit --}}
<x-forms.button data-ak-ajax="my-form" data-ak-action="{{ route('my.route') }}">Salvar</x-forms.button>

{{-- ❌ Enter dies silently --}}
<x-forms.button type="button" data-ak-ajax="my-form" data-ak-action="{{ route('my.route') }}">Salvar</x-forms.button>
```

**Button outside the form** (e.g. a side panel's footer): associate it via
the `form` attribute — that's what gets Enter through to `ajax-post.js`.

```blade
<form id="my-form"> ... </form>

<x-forms.button form="my-form" data-ak-ajax="my-form" data-ak-action="{{ route('my.route') }}">Salvar</x-forms.button>
```

`type="button"` is still correct on an action button with no fields to
submit — like the trash-icon delete button next to the "Salvar" button in
`attribute-options/group-list.blade.php`, which posts to its own
`data-ak-ajax` target and has nothing for Enter to submit.

## AJAX and Updatable Slots

Use updatable slots when content can change dynamically after a user action (e.g., a list updated by a modal or side panel). Do **not** use for simple one-way forms like login or password reset — a redirect is enough there.

**When to use slots:**
- A list/table that can be edited via a popup or panel
- A widget (e.g., header counter) that reflects a mutation
- Any partial that needs to reflect server state without a full reload

**When NOT to use slots:**
- Login, password reset
- Single-step forms that always redirect after success
- Static content that never changes after load

**Pattern:**

```html
<!-- Blade: mark the updatable region with a stable id -->
<div id="users-index-slot">
    @foreach ($users as $user) ... @endforeach
</div>

<!-- Button that triggers AJAX — always use x-forms.button, never raw <button> -->
<x-forms.button data-ak-ajax="my-form" data-ak-action="{{ route('users.store') }}">
    Salvar
</x-forms.button>
```

```php
// Controller: return slot(s) after mutation
return response()->json([
    'message'        => 'Salvo com sucesso.',
    'updatableSlots' => [Users\Index::slot()],
]);
```

```php
// View Component: slot() renders fresh HTML for the region
public static function slot(): array
{
    return (new static)->toSlot('users-index-slot');
}
```

Render methods live on **View Components** (via `Renderable` trait), never on Models.

If the same HTML needs to replace two elements (e.g., a widget in header and sidebar), pipe-separate the IDs:

```php
public static function slot(): array
{
    return (new static)->toSlot('header-widget-slot|sidebar-widget-slot');
}
```

### Multiple *different* slots from one mutation

That pipe syntax is for the *same* HTML in two places. When a mutation can be
triggered from more than one page showing *different* content for the same
record (e.g., editing a Solution from its own detail page **or** from the
catalog list), return every slot the record could appear in — the client
`ajax-slot.js` silently no-ops on any id that isn't on the current page, so
it's safe to always send both:

```php
private function saved(string $message, ?Solution $solution = null): JsonResponse
{
    $slots = [SolutionsIndex::slot()];
    if ($solution) {
        $slots[] = DetailHeader::slot($solution); // present only when editing an existing record
    }

    return response()->json(['type' => 'success', 'message' => $message, 'updatableSlots' => $slots]);
}
```

If a "show" page has a section whose data could also change from create/update
elsewhere in the app, give that section its own View Component + slot id
(don't inline it in the page) so it's a real target for this pattern —
`Solutions\DetailHeader`, `People\DetailHeader`, `Companies\DetailHeader` are
the reference implementations. Auditing this is easy to skip: check every
controller that mutates a resource with its own detail page, and confirm the
mutation response includes that page's slot, not just the index.

The same pattern also applies to small derived widgets that live *outside*
the main index slot's DOM subtree but must update in lockstep with it on
every filter/search — e.g. the live result counter next to an `<h1>` and an
active-filter chips row above the grid. `Solutions\ResultsCount` and
`Solutions\FilterChips` are the reference implementations: both are tiny
View Components with their own slot id, both recompute from the same
`$filters` array, and `SolutionController::index()`/`saved()` always return
all three slots (`SolutionsIndex`, `ResultsCount`, `FilterChips`) together —
forgetting one leaves that widget stale after the next AJAX filter/search
even though the grid itself updates correctly. Both reuse
`Solution::scopeFilter()` (the same local scope as `Solutions\Index`'s
query) instead of re-deriving the filter conditions, so a new filter field
only needs to be added in one place.

### Preserving filters when a mutation refreshes a filtered index slot

When a side panel (create/edit) is opened *from* a filtered index page
(`/solutions?filter[category]=iam`) and the mutation's response re-renders
that page's index slot, the slot must be rebuilt with the **same filters**
the user had applied — otherwise saving/editing a record while filtered
silently resets the visible list to everything, even though the URL still
shows the filter applied. `{Resource}Index::slot()` defaults to `[]`
(unfiltered) whenever the caller doesn't explicitly pass filters, so this
bug reproduces easily if the plumbing below is skipped for a new resource.

The filters live only in the browser's address bar at the moment the panel
is opened — the controller has no other way to know them — so they're
carried explicitly through the URL, from the index card all the way back
to the mutation response:

1. The index page's create link and each card's edit link append the
   current `$filters` as a `filter` query param:
   `route('solutions.edit', ['solution' => $solution, 'filter' => $filters])`.
2. `create()`/`edit()` read `$request->query('filter', [])` and pass it into
   the panel's form view as `'filters'`.
3. The form's `$action` (the `data-ak-action` URL used by `ajax-post.js`)
   re-embeds the same filters:
   `route('solutions.update', ['solution' => $solution, 'filter' => $filters ?? []])`.
4. `store()`/`update()` read `$request->query('filter', [])` back out and
   pass it through `saved()` into `SolutionsIndex::slot($filters)`.

Reference implementation: `SolutionController`, `CompanyController`,
`PersonController` (all three follow this chain identically). `route()`
with an empty `filter` array produces no query string at all, so this is
safe to always do unconditionally, even when no filters are active.

## AJAX response shape

```json
{
    "message": "...",
    "title": "...",
    "type": "success|warning|error",
    "updatableSlots": [{ "id": "element-id", "content": "<html>" }],
    "redirect": "/url",
    "goToURL": "/url",
    "modalIdToClose": "modal-id",
    "reload": 1,
    "js": "..."
}
```

All fields optional. `Toast` and `Modal` are global singletons — no import needed.

## Button loading state

Buttons used with `data-ak-ajax` must use `data-spinner` / `data-label` internally:

```html
<x-forms.button data-ak-ajax="form-id" data-ak-action="/url">
    <span data-spinner class="opacity-0 absolute">...</span>
    <span data-label>Salvar</span>
</x-forms.button>
```

## Blade components

Use custom form components instead of raw HTML — **never write a raw `<button>`, `<input>`, `<select>`, `<textarea>`, or `<label>`**:
`<x-forms.input>`, `<x-forms.select>`, `<x-forms.textarea>`, `<x-forms.button>`, `<x-forms.label>`, `<x-forms.file>`, `<x-forms.checkbox>`, `<x-forms.radio-group>`, `<x-forms.radio>`, `<x-forms.field>` (label+hint+error wrapper), `<x-forms.toggle>` (boolean switch), `<x-forms.image-upload>`, `<x-forms.chips>` (multi-select with role)

The `<x-forms.button>` component handles the `data-spinner` / `data-label` pattern internally and accepts an optional `type` attribute (default: `submit`):

```html
<x-forms.button>Salvar</x-forms.button>
<x-forms.button type="button">Cancelar</x-forms.button>
```

Never pass `type="button"` on a button that also carries `data-ak-ajax` — see
"Never pass `type=\"button\"` on a button with `data-ak-ajax`" under AJAX
Form submission.

Icons: `<x-heroicon-o-home class="w-5 h-5" />` (outline) or `<x-heroicon-s-home />` (solid).

## Global layout (`layout.blade.php`)

The main layout (green sidebar + light canvas, Leo identity) permanently
includes the following shells — **don't recreate them in individual pages**:

- `#alert-modal` — `Modal.loadAlert({...})`
- `#main-modal` — `Modal.loadFromURLAndOpen('main-modal', url)`
- `#toast-container` — `Toast.show(msg)` / `Toast.open({...})`
- `#side-panel` — generic side panel, content via AJAX

Every new GET-accessible page needs an entry in the sidebar nav (the
`$sections` array at the top of the layout) — without it the page is
orphaned, reachable only by typing the URL directly. The `active` key
accepts a string or an array of `routeIs()` patterns; use an array when an
item needs to light up on several routes (e.g. "Soluções" lists
`['solutions.index', 'solutions.show', 'solutions.integrations.*']`, so it
stays active on the solution's detail page and on integration pages nested
under it too).

> The dynamic `#dashboard-bg` (gradient/photo by user preference) from the
> akop-pro reference project was **removed** when applying the Leo identity
> — don't reintroduce it. `ProfileController::customizePanel/updatePreferences`
> and `App\Support\BackgroundPhoto`/`App\Enums\BackgroundTheme` are now
> orphaned (no route/active UI pointing to them); don't build on top of this
> without first confirming whether it still makes sense.

## Side Panel

Generic shell in `layout.blade.php`. Content is **always loaded via AJAX**
on open; **always cleared on close** (reverts to the 3-dot loading
placeholder).

```html
{{-- Open with overlay (default) --}}
<button data-ak-panel-open data-ak-panel-url="{{ route('my.route.panel') }}">
    Abrir
</button>

{{-- Open without overlay --}}
<button data-ak-panel-open data-ak-panel-url="{{ route('my.route.panel') }}" data-ak-panel-overlay="false">
    Abrir sem overlay
</button>

{{-- Wider panel — "small" (default, current width) | "medium" (1/2) | "large" (3/4) --}}
<button data-ak-panel-open data-ak-panel-url="{{ route('my.route.panel') }}" data-ak-panel-size="large">
    Abrir grande
</button>

{{-- Close from inside the injected content --}}
<button data-ak-panel-close>Fechar</button>
```

The overlay (when visible) also closes the panel when clicked.

The endpoint must return `{ "content": "<html>" }`:
```php
return response()->json([
    'content' => view('module.panels.my-panel', $data)->render(),
]);
```

After injection, `initAllModules()` is called automatically. The
`side-panel.js` listener lives at the module level (outside `init()`), so
`init()` is a no-op — multiple `initAllModules()` calls are safe.

## Modal

```js
// Simple alert (uses #alert-modal)
Modal.loadAlert({ title: 'Atenção', content: 'Mensagem', type: 'warning' })

// AJAX content (uses #main-modal)
Modal.loadFromURLAndOpen('main-modal', '/url')
// Endpoint returns: { "content": "<html>" }
```

Buttons with `data-close` inside any `<dialog>` close automatically.

## Toast

```js
Toast.show('Salvo.')                   // success by default
Toast.show('Atenção.', 'warning')
Toast.open({ title: 'T', content: 'C', type: 'error' })
```
