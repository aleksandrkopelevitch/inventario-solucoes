---
paths:
  - "app/Services/DocumentationSearchService.php"
  - "app/View/Components/Documentation/**"
  - "resources/js/modules/docs-search.js"
---

### Public documentation search — the corpus is an INDEX, not a query

`/public-docs/{token}/search` backs the search panel on the magic-link
documentation (`docs-search.js` + `x-documentation.search-panel`). It is the
one place the documentation is treated as a queryable dataset rather than as
pages. The token resolves a `Notebook` — what gets shared is ONE caderno,
whatever it happens to be linked to, so linking a notebook to a solution
publishes nothing.

**It is a ⌘K palette (`<dialog>`), triggered from the topbar.** Its controls are
SCOPES rather than facets, and that is what makes a palette the right shell:

**`SCOPES` (`prose` / `table` / `code`) say WHERE a query looks, not what it
narrows to.** Every entry's text is bucketed into exactly those three at index
time, so a scope costs nothing at query time beyond concatenating the buckets
that are on. That is what makes the corpus interrogable: a column name lives in
tables, an env var in code, a policy in prose, and searching all three at once
buries whichever you meant. The switches live in the palette beside the field,
visible the whole time a query is. There is deliberately no second, content-tag
facet row: two rows of table/code vocabulary asking subtly different questions
was the confusing part.

Three rules the scopes carry:

- **An empty selection means everywhere, not nowhere.** Unticking the last box
  must not answer every query with silence.
- **The default is not sent.** All three on is what the server already assumes,
  so the common request stays byte-identical to the pre-scope one — and
  `inScope()` short-circuits, keeping snippets in document order.
- **`filter.scopes` has to be DECLARED in the Form Request.** `validated()`
  returns only what the rules name, so an undeclared key is dropped in silence
  and every scoped search answers as if unscoped. That is exactly how it broke
  first time round.

Two consequences of the palette worth keeping in mind:

- The idle panel renders its chips server-side, so the page render would
  inherit the index build — which is why `DocumentationSearchService::isWarm()`
  exists. A cold index makes the panel ship a placeholder and lets
  `docs-search.js` fetch it, so a big corpus is indexed in the background on
  the first visit instead of inside time-to-first-paint.
- The reading shell is no longer hidden during a search: a `<dialog>` renders
  in the top layer, so the documentation staying visible underneath is the
  point rather than a conflict. `data-ak-docs-search-active` survives on the
  slot as the server's statement that a query is narrowing; nothing toggles the
  shell any more.

Four things about `App\Services\DocumentationSearchService` are easy to undo by
accident:

- **The unit of a result is the SECTION, not the page.** Every H1–H3 opens an
  entry carrying that heading's own body; the page's own entry carries only the
  "lead" — whatever sits BEFORE the first heading. Every character of the corpus
  therefore belongs to exactly one entry, which is what stops a single passage
  coming back twice, once as a page and once as a heading inside it. (One
  deliberate merge: a page opening with an H1 that repeats its own title has no
  lead of its own, and that H1 *is* the page — otherwise every GitBook-imported
  page would produce two results pointing at the same place.)
- **Anchors are read out of the RENDERED HTML, never re-derived.**
  `GitbookRenderer` emits `<a class="heading-permalink" id="{slug}">`; the index
  parses those ids back out with `DOMDocument`. Re-implementing commonmark's
  slug normalizer drifts the moment two headings collide and it starts
  suffixing `-1`, and a drifted anchor fails silently — right page, wrong place.
- **Both cache layers hash CONTENT, deliberately not `updated_at`.** Timestamps
  are stored at second resolution, so a search landing in the same second as an
  edit would cache an index the edit can no longer invalidate — and with a
  multi-day TTL that staleness outlives the day it started in. The per-page key
  hashes the page's own fields; the per-container key hashes the sequence of
  page keys in tree order (so a MOVE invalidates too — it changes the
  breadcrumb of everything under it). Nothing has to remember to flush anything.
  Bump `VERSION` when the entry shape changes, or old parses survive the deploy.
- **The client receives highlight RANGES, never markup.** Results carry
  `{text, match}` segments and are rendered server-side into one updatable slot
  (`SearchResults::DOM_ID`), so a page's own text is escaped by Blade on the way
  out and there is no path from authored content to `innerHTML`. The query input
  lives OUTSIDE that slot — swapping it per keystroke would drop the caret.

Cost, measured 2026-08-26 on the largest corpus in the dev DB (132 pages /
2 MB / 111k words): ~6 s to build cold, ~50–65 ms per warm search. The cold
cost is paid once per page CONTENT, not per index rebuild, so editing one page
of a large corpus re-renders that page alone. The index is fetched on the
palette's first open, never on page render, so a visitor who does not search
never pays for it.
