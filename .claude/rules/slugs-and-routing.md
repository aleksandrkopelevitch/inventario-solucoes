---
paths:
  - "routes/**"
  - "app/Rules/AsciiSlug.php"
  - "app/Http/Requests/**"
---

### A slug is lowercase ASCII, always

`[a-z0-9]` and single hyphens. **No accents, no `ç`, no spaces, no
underscores, no leading or trailing dash** — `Soluções` is `solucoes`,
`Operação & Manutenção` is `operacao-manutencao`. `App\Rules\AsciiSlug`
enforces it and its message suggests the transliterated form.

Everything that GENERATES a slug already complied, because `Str::slug()`
transliterates. The hole was everything that ACCEPTS one: the six
Store/Update requests for solutions, people and companies took `slug` as
`string|max:255|unique` and nothing else, so a posted `Soluções & Cia` would
have been stored verbatim and become part of a URL. Pages, cadernos and
diagrams never take a client slug at all — theirs are always derived from the
title — so they were never exposed.

The reason is that a slug is an ADDRESS, and it is compared as bytes in places
where nothing forgives a difference: route model binding, `page:{slug}` and
`{% diagram slug="…" %}` inside documentation, and
`PublicDocumentationController::diagramPicture()`, which is authorisation and
therefore deliberately NOT folded (see `.claude/rules/eloquent-strict-and-search.md` — folding it would let `SLUG-A`
stand in for `slug-a`). Add an accent and `operações` and `operacoes` become
two strings that look like one, in URLs that get pasted into Teams and
percent-encoded by some clients and not others.

Heading ANCHORS are a different construct and keep their accents on purpose
(`id="autenticação"`): they are read back out of the rendered HTML rather than
re-derived, so they must match what commonmark emitted. Don't "fix" those.

**URL paths in this app are in English** (`/solutions`, `/companies`,
`/people`, `/notebooks`, `/documentation`, `/docs`, `/map`, `/flowspec`) even
though every label the user reads is PT-BR — `/notebooks` is where a **caderno**
is edited and `/docs` is where a published one is read. Keep
new paths English too, and always build URLs with `route()` rather than a
literal. When you need a path, `php artisan route:list --path=<fragment>` is the
only reliable source — this file has already drifted from reality once by citing
Portuguese paths that 404.

Reference implementation in this app: `routes/web.php`'s
`Route::scopeBindings()->group(...)` around the
`notebooks/{notebook}/{page}/...` routes. `{page}` 404s unless it belongs to the
`{notebook}` in the URL (resolved via `Notebook::pages()`), so a page can never
be edited through the wrong owner.

Two rules that go together in that family, because `notebooks/{notebook}/{page}`
puts a wildcard where static segments also live:

- **Static segments come BEFORE the `scopeBindings()` group**, or they collide
  with `{page}` (same segment shape).
- **Every one of them is reserved as a slug.** `DocumentationPageService::RESERVED_SLUGS`
  refuses `pages`/`share`/`context`/`context-pages`/`chat`/`solutions`/`panel`/
  `secret-code`/`link-targets` for a page, and
  `NotebookController::RESERVED_SLUGS` refuses `panel` and `settings` for a
  caderno (there are real `notebooks/panel` and `docs/settings` routes). That
  list was PT-BR and stale for months — reserving five words no route used while
  leaving the five that mattered free to collide — so check it against
  `route:list` when adding a segment.

  **It is ONE list for TWO route families now.** `docs/{notebook}/{page}` has the
  same shape as `notebooks/{notebook}/{page}`, so a page is only reachable if its
  slug collides with neither — which is why `search`/`file`/`diagram`/`secrets`
  are in the same constant rather than in a second one that goes stale. It is
  only ever applied when a slug is GENERATED, so it cannot rescue a page that
  already carries one; verified against the dev corpus when `/docs` landed (207
  pages, 38 imported GitBook spaces, no collision).

The **diagrams** routes are flat for the same reason `/notebooks` is:
`diagrams/{diagram}/...` — a diagram is addressed by itself. Both used to be
nested under a participating solution, which meant every URL carried a
`{solution}` the endpoint didn't need plus a scope check to keep the two in
agreement; a diagram reaches a solution the other way round now (a page points
at it), and a caderno through the `notebook_solution` pivot. Its chain-editing routes (`chain/nodes/{node}`,
`chain/protocol/{edge}`, `chain/edge/{edge}`) take a plain integer INDEX into
`chain.nodes`/`chain.edges` (`whereNumber(...)`), not a model — those aren't
bindings at all, just route params validated as numeric and range-checked
inside the controller. The submission-diagram routes mirror all nine one for
one.
