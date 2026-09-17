# Inventário de Soluções — Claude Guidelines

Catalog of Leo Madeiras' solutions/integrations: solution, people and company
records, a documentation module (**cadernos** — a page tree per `Notebook`,
each linked to 0..N solutions),
a **diagrams** module (the graphical topology editor, one drawing at a time),
an internal **knowledge base** (`/docs` — the cadernos an admin published, read
by anybody with a Leo account, most of whom arrive through Entra SSO),
a read-only map of the ecosystem derived from those drawings, and an **MCP
server** (`POST /mcp`) that lets a chat client read all of it. Fork of the
generic infra from the
**akop-pro** reference project (forms, slots, JS modules, layout shells) — that
project's legacy domain (CRM, DISC, multi-tenancy) is not part of this one.
See `README.md` for an overview and feature list.

## Language

All code and everything the end user never sees — comments, docblocks,
commit messages, **pull request titles and descriptions**, this file,
internal error/log messages, seeder/migration comments — must be written in
English, regardless of the surrounding code's language. The one deliberate
exception is **user-facing text**: Toast/flash
messages, validation error strings, Blade view content, UI labels/
placeholders, and any string a Brazilian end user actually reads on screen —
those stay in Portuguese, since this app's real UI is PT-BR for Leo Madeiras
staff. When editing a file, don't "fix" surrounding user-facing Portuguese
strings while translating a comment near them, and don't translate a
comment's quoted reference to an actual on-screen label/button name (e.g. a
comment that says `the "Adicionar bloco" button` keeps that name verbatim —
it's what's really printed on the button).

The PR is named explicitly because it is the surface that drifted. Commit
subjects have held the line on their own; PR descriptions had been written in
both languages, which is the worse half to lose — a body is read by the same
people, about the same code, as the commits it collects, so a reviewer ends up
switching languages between two halves of one change. The same exception
applies there: a PT-BR string the user actually sees on screen is quoted
verbatim in the description, not translated.

## Detailed rules live in `.claude/rules/`

This file holds what applies everywhere. Everything module-specific was moved
into `.claude/rules/*.md`, each carrying a `paths:` front-matter block so it is
read only when a file it covers is being read or written. Nothing was deleted —
if a rule you expect is not here, it is in one of these:

| file | covers |
|---|---|
| `diagram-chain-canvas.md` | the `chain` topology invariant, node kinds, the owner-agnostic canvas |
| `cadernos-notebooks.md` | `Notebook` as the one container, the page tree, `page:` links, `{% diagram %}` |
| `docs-reader-surfaces.md` | the magic link and `/docs`, one screen in two audiences |
| `docs-search-index.md` | the documentation search index and its ⌘K palette |
| `docs-secrets.md` | `{% secret %}`, the reveal action, the throttle |
| `documentation-assistant.md` | the Assiste IA chat, its vaults and its prompt contract |
| `page-to-diagram-draft.md` | drawing a page: the draft IR, exact name resolution, the one synchronous model call |
| `diagram-models.md` | the four diagram models, the semantics/geometry split, the lifeline and the AS IS × TO BE diff |
| `digibee-knowledge-base.md` | the two Digibee corpora, redaction, `digibeectl` boundaries |
| `flowspec-pipeline-write.md` | writing a flowSpec into a pipeline, deploy and the test matrix |
| `mcp-server.md` | the MCP server, its bearer token and what a token may read |
| `eloquent-strict-and-search.md` | strict mode's blind spot, `whereFolded()` |
| `slugs-and-routing.md` | a slug is lowercase ASCII, reserved segments, scoped bindings |
| `access-people-accounts.md` | `people.user_id`, access links, grant/revoke/unlink |
| `entra-sso.md` | silent sign-on, domain checks, middleware priority |
| `roles-and-policies.md` | the four roles and the four predicates |
| `errors-and-json-responses.md` | render callbacks, the `ValidationException` shape, JSON cache headers |
| `blade-compiler-traps.md` | the four ways the Blade compiler fails silently |
| `css-and-docs-rendering.md` | Tailwind-first CSS, the two Markdown renderers, the reading screen, code blocks |
| `js-modules-and-ajax.md` | the module conventions, `ajax.js`'s Promise contract, `data-ak-ajax` |
| `updatable-slots.md` | updatable slots and preserving filters across a mutation |
| `side-panel.md` | the side panel shell, docked mode |
| `media-and-uploads.md` | the MediaLibrary collections and the upload rules |
| `testing-time.md` | freezing time in window/TTL tests |
| `data-ak-attribute-reference.md` | the `data-ak-*` hook table |
| `chain-viz-pointer-events.md` | the canvas runs on pointer events |
| `flowspec-context-attachments.md` | flowSpec chat attachments and the token meter |
| `gitbook-import-and-doc-markdown.md` | `gitbook:import` and the Markdown round trip |
| `inline-edit-pattern.md` | `x-ui.inline-edit` |

When a rule changes, edit it where it lives. When adding one, give it a
`paths:` list of globs that actually resolve — a glob pointing at a renamed file
never loads, and fails silently.


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

- Create service-specific macro clients for external APIs. All of them are
  registered in `AppServiceProvider::boot()` — `Http::gitbook()`,
  `Http::gitbookAsset()`, `Http::digibeeDocs()` and `Http::digibeeDesign()`
  (that last one delegates to `DigibeeAuthResolver`, since its credential and
  its `Authorization` scheme are resolved per call). `gitbook` is the plainest
  precedent to copy:

```php
// AppServiceProvider
Http::macro('gitbook', fn () => Http::baseUrl((string) config('services.gitbook.url'))
    ->withToken((string) config('services.gitbook.token'))
    ->timeout((int) config('services.gitbook.timeout'))
    ->connectTimeout(5)
    ->acceptJson());

// Usage
Http::gitbook()->get('/spaces/' . $id . '/content/pages');
```

  Note the LLM calls are not here: every AI feature (flowSpec F8, the
  documentation and CATI chats) goes through the `laravel/ai` package, keyed
  off `config/services.php` (`services.documentation_ai.*` and friends), never
  through `Http::`.

Two traps live in that macro, both found the hard way on the first real import:

- **`retry(..., throw: false)` is load-bearing, not a preference.** `retry()`
  otherwise throws a raw `RequestException` the instant a response fails, which
  jumps straight over the client's own `$response->failed()` check — and with it
  every operator-facing message the domain exception authors. Adding a retry to
  an existing client silently changes its error contract; a test asserting the
  authored message is what catches it.
- **A `Http::macro()` closure is REBOUND to the PendingRequest.**
  `Macroable::__call` does `$macro->bindTo($this, static::class)`, so `self::` /
  `static::` inside the closure resolve to `Illuminate\Http\Client\PendingRequest`
  — `self::isTransient($e)` dies with `Method PendingRequest::isTransient does
  not exist`, pointing at a line that looks perfectly correct. Put shared logic
  in its own class and call it by an imported name (`TransientHttpFailure::
  matches()`); `use` statements are resolved at compile time and are immune.

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

## Collections

- Use higher-order messages where readable: `$proposals->each->analyze()`
- Prefer `cursor()` over `get()` for large Eloquent collections
- Use `->toQuery()` to convert a collection back to a query builder when needed

## Blade Views

- Use `$attributes->merge()` on all custom components to pass through HTML attributes
- Prefer `@pushOnce` over `@push` for scripts/styles that should only appear once
- Always prefer Blade components (`<x-...>`) over `@include` for reusable partials

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

## Style

- Use Laravel helpers over facades where shorter: `auth()`, `request()`, `now()`, `rescue()`
- Follow Laravel naming conventions: `StoreProposalRequest`, `ProposalPolicy`, `AnalysisSeeder`
- Prefer readable, expressive syntax over clever one-liners

---

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

## Blade components

Use custom form components instead of raw HTML — **never write a raw `<button>`, `<input>`, `<select>`, `<textarea>`, or `<label>`**:
`<x-forms.input>`, `<x-forms.select>`, `<x-forms.textarea>`, `<x-forms.button>`, `<x-forms.label>`, `<x-forms.file>`, `<x-forms.checkbox>`, `<x-forms.radio-group>`, `<x-forms.radio>`, `<x-forms.field>` (label+hint+error wrapper), `<x-forms.toggle>` (boolean switch), `<x-forms.image-upload>`, `<x-forms.chips>` (multi-select with role)

The `<x-forms.button>` component emits the `data-spinner` / `data-label` spans
itself — never write them at a call site — and accepts an optional `type`
attribute (default: `submit`):

```html
<x-forms.button>Salvar</x-forms.button>
<x-forms.button type="button">Cancelar</x-forms.button>
```

Never pass `type="button"` on a button that also carries `data-ak-ajax` — Enter
stops working, silently (see `.claude/rules/js-modules-and-ajax.md`).

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
`['solutions.index', 'solutions.show']`, so it stays lit on a solution's detail
page too). A page that got its own top-level section — `/diagrams` did — should
LEAVE the borrowed patterns behind: "Soluções" listing `diagrams.*` would light
two rail items at once.

An item an account may not open declares the ability it needs (`can`) and the
model that ability belongs to (`canModel`, defaulting to `Notebook`) — the rail
hides what would answer 403 rather than offering it. **The check runs ONCE, in
the `$sections` block**, because the desktop rail and the mobile drawer are two
loops over the same array: it used to live in both, each hard-coding `Notebook`,
so an entry gated by a policy of its own was correctly hidden in one and offered
to everybody in the other. `NotebookPolicy::viewAny` says yes to every `Viewer`,
which is why that stayed invisible while the knowledge-base settings were the
only gated entry.

> The dynamic `#dashboard-bg` (gradient/photo by user preference) from the
> akop-pro reference project was **removed** when applying the Leo identity
> — don't reintroduce it. `ProfileController::customizePanel/updatePreferences`
> and `App\Support\BackgroundPhoto`/`App\Enums\BackgroundTheme` are now
> orphaned (no route/active UI pointing to them); don't build on top of this
> without first confirming whether it still makes sense.

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
