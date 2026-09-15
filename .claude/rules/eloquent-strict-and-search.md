---
paths:
  - "app/Models/**"
  - "app/Services/**"
  - "app/Jobs/**"
  - "app/Support/Fold.php"
  - "app/Providers/AppServiceProvider.php"
  - "resources/js/modules/fold.js"
---

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
`Diagram::query()->find($id)->source` inside a `LazilyRefreshDatabase`
Feature test with `app()->isProduction()` confirmed false: no exception.
Jobs are where this bites most — `handle(SomeModel $thing)` then
`$thing->relatedModel->...` gets zero protection from strict mode, which is
exactly the pattern it's supposed to catch. Eager-load explicitly
(`$thing->loadMissing('relatedModel')`) at the top of job/service methods
that walk a relation off a single fetched model — don't rely on a missing
`with()` being caught by strict mode or by a test.

If a View Component maps a parent's already-loaded collection and a child
partial needs to walk back up (`$page->container` when the component only has
`$this->solution`), set the relation in memory instead of eager loading a query
you don't need:

```php
$page->setRelation('container', $this->solution); // no query — already in hand
```

Both page controllers do exactly that before rendering the editor, and for a
sharper reason than performance: `DocumentationPagePolicy` delegates every
answer to `$page->container`, so without it the policy is what lazy-loads.

### Searching — `whereFolded()`, never `like`

**Every "does this column contain what the person typed" goes through
`whereFolded()` / `orWhereFolded()`** (macros on the query builder, registered
in `AppServiceProvider`). They fold BOTH sides — the column and the term — to
lowercase ASCII via `App\Support\Fold`, so `big` finds "Google BigQuery" and
`solucao` finds "Solução", as does `solução` against a name written without
accents. Half this catalog is named with accents and half without; matching
only one direction leaves the other half unreachable.

Do not reach for a bare `where(..., 'like', "%$term%")` again, and do not reach
for Laravel's own `whereLike($column, $value, caseSensitive: false)` either: it
answers the case half (it emits `ILIKE` on Postgres) and nothing about accents.
A macro cannot shadow a real method, so a macro named `whereLike` would be dead
code — that is why the name is `whereFolded`.

Three things this depends on:

- **The folding is a real SQLite function, not a fallback.** Postgres gets
  `translate(lower(col), …)` built from the same PHP map; SQLite (`lower()` is
  ASCII-only there, and it has no `translate()`) gets the PHP folding
  registered on the connection. One map, so the two can never drift, and a
  test on SQLite says something true about Postgres.
- **The escape character is `!`, not `\`.** The term is data — searching for
  "100%" means the character — so wildcards are escaped, and SQLite has no
  default escape character, so the clause has to be spelled out. A backslash
  inside `escape '\'` leaves PDO's own placeholder scanner believing the
  string literal is still open: it then reports "Invalid parameter number" on a
  statement whose placeholders and bindings match perfectly.
- **Client-side filters fold too** (`resources/js/modules/fold.js` — the
  flowSpec document picker, the diagram picker, the ecosystem map). A list
  narrowed in the browser must answer a query the same way the database does,
  or the same word finds different things on two screens.

**A search has to reach every column the thing is actually filed under.** The
people catalog folded correctly and still answered "0 registros" for
`admin@leomadeiras.com.br`, because `Person::scopeFilter()` searched name,
company and solution and no e-mail at all (reported 2026-09-02). An e-mail lives
in THREE places for one person and the box asks all three now: `people.email`,
the `contacts` repeater (whose raw `value` makes a phone number findable too),
and — the half the report was really about — the linked ACCOUNT's `users.email`,
since linking an account is how an address gets attached to a person without
their own column ever being filled in. Folding is only half of "can this be
found"; the other half is which columns the `orWhere` chain names, and that half
has no macro to get right for you.

The one deliberate exception is `PublicDocumentationController::diagramPicture()`,
which asks whether a caderno cites an exact slug. That is authorisation, not
search: folding it would let `SLUG-A` stand in for `slug-a`.

**This is also the shape of a bug the test suite cannot see.** All of these were
case-INsensitive while the app ran on SQLite and turned case-sensitive the day
it moved to Postgres, with the suite still green — it runs SQLite
(`phpunit.xml`), whose `LIKE` is case-insensitive for ASCII. Anything whose
behaviour depends on the DRIVER needs a test written to fail on SQLite too
(accented capitals do it: `ORAMA SERVICOS` against `Órama Serviços` needs both
halves of the folding on either driver).
