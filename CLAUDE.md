# Inventário de Soluções — Claude Guidelines

Catálogo das soluções/integrações da Leo Madeiras: cadastro de soluções,
pessoas e empresas, editor gráfico de topologia de integrações por solução, e
mapa read-only do ecossistema. Fork da infra genérica do projeto de
referência **akop-pro** (forms, slots, módulos JS, shells de layout) — o
domínio legado daquele projeto (CRM, DISC, multi-tenancy) não faz parte
daqui. Ver `README.md` para visão geral e lista de funcionalidades.

## Stack
- Laravel 13+, Blade, Vanilla JS, Tailwind CSS 4, Vite 8, SQLite (dev) / PostgreSQL (prod)

## Commands

```bash
composer dev          # serve + queue + pail + vite em paralelo
composer test         # pest
./vendor/bin/pint     # code style
npm run build && php artisan optimize
```

## Conventions

- Controllers finos: 
  - lógica mais ampla em Services
  - Actions para ações únicas (single-purpose, constructor DI)
- Queries complexas em Scopes ou Query Builders
- Rotas sempre com `->name()`
- Mobile-first com Tailwind
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
graph: `{nodes: [{solution_id, label}], edges: [{from, to, arrow, protocol}]}`,
where `from`/`to` are indices into `nodes` (not consecutive positions) and
each edge carries its own direction (`'->'|'<-'|'<->'`) and protocol. A node
can have zero edges (isolated block). `App\Actions\SyncIntegrationFromChain`
is the ONLY thing that writes the derived columns (`participants` pivot with
`position`, `source/target_solution_id`, `direction`, and the summary scalar
`protocol` = first non-null edge protocol) — it runs after every mutation to
`chain` (`SolutionIntegrationController::store/updateNode/updateProtocol/
addNode/retargetEdge/addEdge/removeEdge`). `Integration.viz_layout`
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

Reference implementation in this app: `routes/web.php`'s
`Route::scopeBindings()->group(...)` around the
`solucoes/{solution}/integracoes/{integration}/...` routes. `{integration}`
404s unless the `{solution}` participates in it (resolved via
`Solution::integrations()`). The nested chain-editing routes under it
(`chain/nos/{node}`, `chain/protocolo/{edge}`, `chain/aresta/{edge}`) take a
plain integer index into `chain.nodes`/`chain.edges` (`whereNumber(...)`), not
a model — those aren't scoped bindings, just route params validated as
numeric and range-checked inside the controller.

- Catch-all `Route::get('{post:slug}', ...)` must remain the **last** route in `web.php`

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
  consumers). Reference implementation: `App\Jobs\GenerateFlowspecReply` (F8
  flowSpec chat generator) + `resources/js/modules/flowspec-chat.js` — a
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
  `data.errors` (see `resources/js/modules/solution-attributes.js` for the
  pattern).

## Collections

- Use higher-order messages where readable: `$proposals->each->analyze()`
- Prefer `cursor()` over `get()` for large Eloquent collections
- Use `->toQuery()` to convert a collection back to a query builder when needed

## Blade Views

- Use `$attributes->merge()` on all custom components to pass through HTML attributes
- Prefer `@pushOnce` over `@push` for scripts/styles that should only appear once
- Always prefer Blade components (`<x-...>`) over `@include` for reusable partials

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
search UI on `/solucoes`, `/integracoes`, `/empresas`, and `/pessoas` — every
`<x-forms.select>`/`<x-forms.input>`/`<x-forms.checkbox>` carried
`data-ak-filters='@json($filterBind)'` or `data-ak-search='@json([...])'`.
Confirmed via curl that the rendered HTML contained the literal uncompiled
`@json(...)` text. Pest feature tests did not catch it — they call
controllers directly with `filter[...]` query params, bypassing the
Blade/JS rendering layer entirely. Fixed by converting every occurrence
(including the ones on plain tags, which weren't broken, for a single
consistent convention) to `attr="{{ json_encode(...) }}"`.

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
- `.ak-viz-md` (inline `<style>` in `integration-viz.blade.php`) — styles a
  node's markdown comment preview in the F3 integration canvas: arbitrary
  rendered-markdown content with no fixed element to attach a utility class
  to before it exists. Same reasoning as `.html-content` in `app.css` (a
  leftover exception from the now-removed F4 documentation-blocks feature —
  its class is no longer rendered anywhere, but the CSS wasn't worth deleting
  as part of an unrelated change; don't add new content to it).
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

## Media (Spatie MediaLibrary)

Each model has a single-file collection (e.g. `post-image`). Three conversions are registered: `thumb` (180×180), `medium` (497×290), `large` (1100×500).

```php
$post->image('medium')
```

Never register new conversions or collections without checking existing ones first.

## Style

- Use Laravel helpers over facades where shorter: `auth()`, `request()`, `now()`, `rescue()`
- Follow Laravel naming conventions: `StoreProposalRequest`, `ProposalPolicy`, `AnalysisSeeder`
- Prefer readable, expressive syntax over clever one-liners

---

## JavaScript — use modules before creating new ones

Before writing any new JS behavior, check if an existing module in `resources/js/modules/` already handles it. The project has modules for: toggle, tabs, side panel, AJAX form submission, file upload, masks, autocomplete, filters, search, and more.

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
window.initListOfModules(['toggle', 'fileUpload'])
```

All JS hooks use `data-ak-*` attributes. Internal component slots (`data-spinner`, `data-label`, `data-content`, etc.) are exempt.

## data-ak-* attribute reference

All JS hooks use the `data-ak-*` prefix. Internal slots (`data-spinner`, `data-label`, `data-content`) are private to their component and exempt.

| Attribute | Module | Description |
|---|---|---|
| `data-ak-ajax="form-id"` | `ajax-post.js` | Triggers AJAX form submission on click |
| `data-ak-action="url"` | `ajax-post.js` | POST destination URL |
| `data-ak-confirm="msg"` | `ajax-post.js` | `window.confirm` gate before the AJAX fires |
| `data-ak-toggle="element-id"` | `toggle.js` | ID of element to toggle |
| `data-ak-toggle-classes="cls"` | `toggle.js` | Classes to toggle on target |
| `data-ak-toggle-event="click"` | `toggle.js` | Trigger event (default: `click`) |
| `data-ak-toggle-once` | `toggle.js` | Fire only once |
| `data-ak-toggle-blur="true"` | `toggle.js` | Close on outside click |
| `data-ak-toggle-mouseout` | `toggle.js` | Also toggle on mouseout |
| `data-ak-panel-open` | `side-panel.js` | Opens `#side-panel` |
| `data-ak-panel-url="url"` | `side-panel.js` | URL to fetch content from |
| `data-ak-panel-close` | `side-panel.js` | Closes `#side-panel` and clears content |
| `data-ak-panel-overlay="false"` | `side-panel.js` | Open without overlay |
| `data-ak-tabs='{"targetId":"…"}'` | `tabs.js` | JSON config for tab switching |
| `data-ak-switch='{"checkName":"…"}'` | `switch-button.js` | JSON config for toggle switch |
| `data-ak-filters='{"formId":"…"}'` | `execute-filters.js` | JSON config for filter execution |
| `data-ak-filters-clear='{"formId":"…","field":"filter[x]","url":"…"}'` | `execute-filters.js` | Clears one filter field (active-filter chip ✕) and resubmits via AJAX |
| `data-ak-filters-clear-all='{"formId":"…","url":"…"}'` | `execute-filters.js` | Clears every `filter[...]` field except `filter[sort]` and resubmits ("Limpar tudo" / empty-state action) |
| `data-ak-filters-loading` | `execute-filters.js` | Shown (removes `hidden`) while a filter/search AJAX request is in flight |
| `data-ak-filters-dim` | `execute-filters.js` | Dimmed (`opacity-50 pointer-events-none`) while a filter/search AJAX request is in flight — put on a persistent wrapper *around* the swapped slot, not the slot node itself, since the slot node gets replaced wholesale |
| `data-ak-search='{"inputId":"…"}'` | `execute-search.js` | Debounced (350ms) search-as-you-type; searches immediately on Enter |
| `data-ak-search-hint="input-id"` | `execute-search.js` | Element whose text is set to the "digite N+ letras" hint while 1–2 chars are typed, cleared otherwise |
| `data-ak-mask="phone\|cpf\|cnpj\|zip"` | `mask.js` | Input mask type |
| `data-ak-copy='{"fromId":"…"}'` | `copy-content.js` | Copy innerHTML from another element |
| `data-ak-file-upload` | `file-upload.js` | File upload root element |
| `data-ak-avatar-upload='{"inputId":"…"}'` | `avatar-upload.js` | Avatar upload trigger |
| `data-ak-autocomplete='{"resultsId":"…"}'` | `autocomplete.js` | Autocomplete input |
| `data-ak-top-nav` | `top-nav.js` | Element that receives scroll shadow |
| `data-ak-url="url"` | `url-location.js` | Navigate to URL on click |
| `data-ak-enter-click="btn-id"` | `event-helpers.js` | Click button on Enter keypress |
| `data-ak-make-slug='{"sourceId":"…"}'` | `string-helpers.js` | Generate slug from source field |
| `data-ak-searchable` | `search-in-container.js` | Marks element as searchable |
| `data-ak-solution-attribute` (on a `<select>`) + `data-solution-attributes`/`data-action="url"` (on the wrapping `<dl>`) | `solution-attributes.js` | Auto-persists one Solution attribute on `change`, no save button |
| `data-ak-chips` (root) + `data-ak-chips-input`/`data-ak-chips-list`/`data-ak-chips-results`/`data-ak-chips-result`/`data-ak-chip`/`data-ak-chip-remove` | `chips.js` | Multi-select-with-autocomplete chips input (e.g. Pessoa↔Solução role linking) |
| `data-ak-integration-select="slug"` (on a row) + `data-ak-integration-list` (on the container) | `integration-select.js` | Selects an integration row (`aria-pressed`), dispatches `ak:integration-selected` `{name, slug, graph}` |
| `data-ak-flowspec-poll="status-url"` | `flowspec-chat.js` | Presence in the thread slot = a reply is still generating; module polls the URL every 2.5s (capped at `MAX_POLL_ATTEMPTS`) until the slot swap removes this marker |
| `data-ak-flowspec-copy="pre-id"` | `flowspec-chat.js` | Copies the target element's `textContent` (not `innerHTML` — the flowSpec JSON's `jsonPath` has literal `&&`) to the clipboard |

## `ajax.js` — contrato Promise, não XHR

`resources/js/modules/ajax.js::init(method, url, formData?)` é uma função
**assíncrona baseada em `fetch`** — devolve uma `Promise<Response>` (rejeita
em `!response.ok`, com `error.response` anexado). Ela **não** tem API de
`XMLHttpRequest` (`.onload`, `.status`, `.response`, `.send()`) — isso é
resquício do `ajax.js` antigo do akop-pro, que era baseado em XHR.

```js
// Correto
ajaxModule.init('GET', url)
    .then((response) => response.json())
    .then((data) => updateSlots(data))
    .catch((error) => { /* error.response, se veio de status != 2xx */ })

// Quebrado — ajaxObj é uma Promise, não tem .onload/.send()
let ajaxObj = ajaxModule.init('GET', url)
ajaxObj.onload = function () { ... }
ajaxObj.send()
```

Achado real (2026-07-02): `execute-filters.js::applyFilters()`, `modal.js::loadFromURLAndOpen()`
e `avatar-upload.js::uploadAddedImage()` (removida — código morto, nunca
chamada) ainda usavam a API antiga contra o `ajax.js` já reescrito — todo
clique num filtro/busca (Soluções, Pessoas, Empresas) lançava
`TypeError: ajaxObj.send is not a function`, silenciosamente engolido pelo
listener, deixando busca/filtro sem efeito nenhum. `ajax-post.js` era o único
consumidor já correto (usa `await`). Ao adicionar um novo consumidor de
`ajaxModule.init()`, sempre tratar o retorno como Promise.

## AJAX — Submissão de forms

O módulo `ajax-post.js` intercepta **cliques** em `[data-ak-ajax]` e também **submit** do form (Enter ou submit nativo), prevenindo a submissão nativa em ambos os casos.

```html
<form id="meu-form">
    @csrf
    <!-- campos -->
</form>

<x-forms.button data-ak-ajax="meu-form" data-ak-action="{{ route('minha.rota') }}">
    <span data-label>Salvar</span>
    <span data-spinner class="opacity-0 absolute">...</span>
</x-forms.button>
```

Não é necessário `onsubmit` no form. Enter e clique funcionam automaticamente.

## AJAX and Updatable Slots

Use updatable slots when content can change dynamically after a user action (e.g., a list updated by a modal or side panel). Do **not** use for simple one-way forms like login, registration, or password reset — a redirect is enough there.

**When to use slots:**
- A list/table that can be edited via a popup or panel
- A widget (e.g., header counter) that reflects a mutation
- Any partial that needs to reflect server state without a full reload

**When NOT to use slots:**
- Login, registration, password reset
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
(`/solucoes?filter[category]=iam`) and the mutation's response re-renders
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
<x-forms.button type="button" data-ak-ajax="form-id" data-ak-action="/url">Salvar</x-forms.button>
```

Icons: `<x-heroicon-o-home class="w-5 h-5" />` (outline) or `<x-heroicon-s-home />` (solid).

## Layout global (`layout.blade.php`)

O layout principal (sidebar verde + canvas claro, identidade Leo) inclui
permanentemente as seguintes shells — **não recriar em páginas individuais**:

- `#alert-modal` — `Modal.loadAlert({...})`
- `#main-modal` — `Modal.loadFromURLAndOpen('main-modal', url)`
- `#toast-container` — `Toast.show(msg)` / `Toast.open({...})`
- `#side-panel` — painel lateral genérico, conteúdo via AJAX

Toda página nova acessível por GET precisa de um item na nav da sidebar (o
array `$sections` no topo do layout) — sem isso a página fica órfã, só
alcançável por URL digitada. A chave `active` aceita string ou array de
padrões `routeIs()`; use array quando um item precisa acender em várias rotas
(ex.: "Soluções" lista `['solutions.index', 'solutions.show',
'solutions.integrations.*']`, para permanecer ativo também no detalhe da
solução e nas páginas de integração aninhadas sob ela).

> O `#dashboard-bg` dinâmico (gradiente/foto por preferência do usuário) do
> projeto de referência akop-pro foi **removido** na aplicação da identidade
> Leo — não reintroduzir. `ProfileController::customizePanel/updatePreferences`
> e `App\Support\BackgroundPhoto`/`App\Enums\BackgroundTheme` ficaram órfãos
> (sem rota/UI ativa apontando pra eles); não construir em cima disso sem
> primeiro confirmar se ainda fazem sentido.

## Side Panel

Shell genérico em `layout.blade.php`. Conteúdo **sempre carregado via AJAX** na abertura; **sempre limpo no fechamento** (volta ao placeholder de carregamento com 3 bolinhas).

```html
{{-- Abrir com overlay (padrão) --}}
<button data-ak-panel-open data-ak-panel-url="{{ route('minha.rota.panel') }}">
    Abrir
</button>

{{-- Abrir sem overlay --}}
<button data-ak-panel-open data-ak-panel-url="{{ route('minha.rota.panel') }}" data-ak-panel-overlay="false">
    Abrir sem overlay
</button>

{{-- Fechar de dentro do conteúdo injetado --}}
<button data-ak-panel-close>Fechar</button>
```

O overlay (quando visível) também fecha o painel ao ser clicado.

O endpoint deve retornar `{ "content": "<html>" }`:
```php
return response()->json([
    'content' => view('modulo.panels.meu-panel', $data)->render(),
]);
```

Após injeção, `initAllModules()` é chamado automaticamente. O listener do `side-panel.js` fica no nível do módulo (fora do `init()`), então `init()` é no-op — chamadas múltiplas de `initAllModules()` são segradas.

## Modal

```js
// Alerta simples (usa #alert-modal)
Modal.loadAlert({ title: 'Atenção', content: 'Mensagem', type: 'warning' })

// Conteúdo AJAX (usa #main-modal)
Modal.loadFromURLAndOpen('main-modal', '/url')
// Endpoint retorna: { "content": "<html>" }
```

Botões com `data-close` dentro de qualquer `<dialog>` fecham automaticamente.

## Toast

```js
Toast.show('Salvo.')                   // success por padrão
Toast.show('Atenção.', 'warning')
Toast.open({ title: 'T', content: 'C', type: 'error' })
```
