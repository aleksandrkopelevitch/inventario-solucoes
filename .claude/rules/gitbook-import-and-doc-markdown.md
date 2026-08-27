---
paths:
  - "app/Console/Commands/ImportGitbookCommand.php"
  - "app/Support/Gitbook/**"
  - "app/Support/GitbookRenderer.php"
  - "resources/js/modules/docs-markdown.js"
  - "app/Http/Requests/MoveDocumentationPageRequest.php"
  - "app/Http/Requests/MoveDocumentationPageToContainerRequest.php"
  - "app/Http/Requests/StoreDocumentationPageRequest.php"
  - "app/Services/DocumentationPageService.php"
  - "app/Models/DocumentationPage.php"
  - "resources/views/components/documentation/pages-nav.blade.php"
---

## GitBook import — and the three "firsts" it introduced

`php artisan gitbook:import` pulls existing GitBook content into the
documentation module: one space becomes one `Notebook` ("Caderno"), each of its
pages a `DocumentationPage`. A space IS a caderno — that is what the container
swap made true, and this import is where the two models line up exactly. It used
to land in a standalone `DocumentationGroup` as a temporary holding pen, and the
reasoning behind that still holds (a space rarely maps 1:1 onto one solution, so
guessing scatters pages) — it just costs nothing now: the caderno is a
first-class home, and saying which solutions it documents is a LINK, not a move.
Pages can still be re-filed into another caderno (see "Re-filing a page" below).
`--list` discovers space ids, `--dry-run` writes nothing, `--space=`/`--all` do
the work, `--notebook=` names the caderno (`--group=` still works). Strictly
read-only against GitBook.

### The space's shape comes across, clamped at `MAX_DEPTH`

`GitbookPageTree` reproduces GitBook's nesting instead of flattening it, and
three decisions in there are worth knowing before touching it:

- **A `group` becomes a page with no content.** It is pure structure in GitBook
  (a sidebar heading), but its children need something to hang from, and an
  empty page is exactly how it reads. Counted separately (`sections()`) so it
  doesn't inflate the page count, and its `documentation` is never written —
  not even set to null on a re-run — so notes a person adds to a section page
  survive re-imports, since GitBook has no content there to overwrite them with.
- **Deeper than the cap collapses into the title, and is counted.** GitBook
  nests to any depth; below `DocumentationPage::MAX_DEPTH` a node hangs off the
  deepest ancestor that can still hold children and takes the skipped ancestry
  as a prefix ("Requisitos › Hardware › Disco"). `collapsed()` reports how many,
  and the command warns with that number — an import that says nothing would
  imply the whole shape came across. **`MAX_DEPTH` was raised from 3 to 5
  because of this corpus** (surveyed 2026-08-26): 2 of its 38 spaces nest to
  exactly five, and at three levels 86 of 629 rows collapsed. At five the whole
  corpus comes across losslessly — worth re-measuring before changing the cap
  again, the survey is a read-only walk of `pageTree()` per space.
- **`--flat` still exists** and is the old behaviour, whole: every page a root,
  full ancestry in the title, groups contributing only theirs. Worth keeping for
  a space so deep that nesting only moves the truncation around.

### A re-run is also the migration

A page is matched inside the caderno by title, and one of the titles tried is
`GitbookPage::origin()` — the legacy flattened string ("Começando › Instalação")
that the pre-nesting import wrote. So re-importing a space that came in flat
**renames its pages down to their bare titles and hangs them off each other in
place**, instead of importing a second copy beside the first. There is no
separate data migration for the 613 pages already imported; the re-run is it.

Three details that make that work, each easy to break:

- **Match order is specific → legacy → loose**: (title AND parent), then the
  legacy flattened title, then the title anywhere in the caderno. Matched rows are
  claimed and leave the working set, which is what keeps two identically-named
  GitBook pages (or two sections named the same) from collapsing into one.
- **Slugs deliberately don't follow the rename.** A page's URL stays stable —
  the same rule the rest of the module keeps — so a re-shaped page keeps the
  slug built from its old prefixed title. That is intentional, not an oversight.
- **`position` is per sibling list**, one counter per parent, because that is
  what `position` means since the tree gained depth (§ Cadernos in AGENTS.md). One counter for the whole space would scramble every level.

It is worth knowing this module exists for three reasons beyond GitBook:

- **It is the first (and only) external HTTP client** — see the macro above.
- **It is the first `app/Console/Commands/`.** `ImportGitbookCommand` is the
  only artisan command in the app; `routes/console.php` still holds only
  Laravel's `inspire` stub.
- **It is the first `app/Exceptions/`.** `GitbookApiException` is
  self-contained (it authors its own operator-facing message, per § Error
  Handling) and is CAUGHT by the command rather than left to bubble — a stack
  trace tells an operator nothing the message doesn't. That is the exception to
  "never try/catch"; it applies to controllers, where exceptions must reach the
  central handler.

### Anything that WRITES documentation Markdown must emit this app's exact dialect

The real lesson of the import, and it applies to every future feature that
generates documentation content programmatically (the AI assist drafts already
do): the `documentation` column's dialect is narrower than it looks, and every
way of missing it **fails silently** — the page saves, and the construct shows
up as literal `{% … %}` text on screen, or quietly disappears from the editor.

- **Only four `{% %}` constructs exist here** — `hint`, `tabs`/`tab`, `file`,
  `embed`. They are parsed TWICE, by `App\Support\GitbookRenderer` (read-only
  render) and by `resources/js/modules/docs-markdown.js` (the editor), with
  near-duplicate regexes that must stay in step.
- **Those regexes accept ONE attribute and nothing more.** The editor matches
  `^\{%\s*embed\s+url="([^"]*)"\s*%\}$`, so GitBook's perfectly valid
  `{% embed url="…" fullWidth="false" %}` matches neither parser. Strip extras.
- **An image is only recognised as `<figure>`/`<img>` at the START of a line**,
  never as `![alt](url)` — `docs-markdown.js`'s `parseLines` has no case for
  Markdown image syntax, so one becomes a paragraph of literal text in the
  editor. Emit the single-line
  `<figure><img src="…" alt="…"><figcaption>…</figcaption></figure>` form, and
  note the figure must be on ONE line (a multi-line one, or a
  `<figcaption><p>…</p></figcaption>`, doesn't parse).
- **Never leave a remote URL in `documentation`.** Embedded media belongs in
  the page's own `docs` collection, referenced as `/files/{id}` — otherwise the
  content is made of hotlinks that break when the source goes away.
  `MediaController::show()` authorizes on the media's COLLECTION only, so media
  keeps working when its page moves caderno.
- **`/files/{id}` is also GitBook's own path shape, and that collision is the
  nastiest thing in this whole import.** GitBook writes an embedded asset as
  `/files/{gitbookFileId}` (`/files/A4QijPsDvYEfSb14PQso`), which is byte-for-byte
  the shape this app serves its own media on. Passed through, it produces a page
  whose markup is flawless and whose every image 404s against our own
  `files.show` with an id belonging to another system — and an import that
  cheerfully reports "0 assets re-hosted". The first real import was exactly
  this: 20 references, none of them absolute URLs, nothing downloaded. The ids
  are resolvable only through `GET /spaces/{id}/content/files`
  (`GitbookClient::files()`), which is where the real `downloadURL` lives; a
  `/files/{digits}` reference is left alone, since a numeric id is one of ours
  from a previous import of the same page.
- **The same reference shows up a THIRD way: a plain `<a href="/files/{id}">`**
  — a document LINKED from running text or a table cell, never wrapped in an
  `<img>` or a `{% file %}` block. Found for real in a "Sprints" table that
  linked ~75 documents this way, none of them even attempted (no warning, no
  failure — the regex simply had no case for an anchor). And when GitBook has
  no display name for a link, it falls back to showing the raw path AS the
  visible text too (`<a href="/files/{id}">/files/{id}</a>`), so the href alone
  resolving correctly still leaves a foreign id sitting in the reader's face —
  fixed by re-checking, after the main pass, for any anchor text that exactly
  mirrors its own original reference. A REAL display name
  (`>Checklist.pdf</a>`) is never touched — the check is exact-match against
  the untouched original value, not "any anchor near a rewritten href".

Two more shapes, both found by auditing the real corpus (613 pages across 38
spaces) rather than by reading GitBook's docs — which describe neither:

- **`file` and `embed` are self-closing here, but GitBook also has a PAIRED
  form** whose body is a caption (`{% file src="…" %}Legenda{% endfile %}`) —
  11 of 408 files and 7 of 23 embeds in that corpus. Re-emitting the closer
  prints a literal `{% endfile %}` on the page. `hint` and `tabs`/`tab` really
  are paired; those keep their closers.
- **Notation cannot live inside a blockquote.** Both parsers are line-anchored
  (`^\{%`), so `> {% code … %}` is unreachable in this dialect at any nesting.
  The normalizer strips the quote marker and does not re-apply it, so the
  construct works at top level instead of being printed as text inside a quote
  that kept its styling.

`App\Support\Gitbook\GitbookMarkdownNormalizer` is where all of the above is
encoded, with a down-converter for the GitBook constructs this app never learned
(`content-ref`, `stepper`, `columns`, `code`) and a visible PT-BR callout for
what can't be converted honestly (`include`, `openapi`). Reuse it rather than
re-deriving the rules.

Worth knowing before planning an import: that corpus is ~613 pages with 459
`<figure>`s and ~402 remote asset references, so a full run is roughly 613
markdown requests plus 400 downloads. `--dry-run` does NOT exercise any of the
above — it returns after walking the page tree, before fetching a single page's
markdown.

One more thing about the import itself (re-running is covered above): it
deliberately has **no wrapping transaction** — it is hundreds of HTTP requests,
and a half-finished import that can be re-run beats one that rolls back an hour
of downloads because page 180 failed. Media is cleared before re-adding, so a
re-import doesn't accumulate orphans; a section page is skipped entirely there,
having no content to re-fetch.

A related but genuinely OUT-of-scope shape, found in the same corpus: a prose
line an author wrote themselves, `Link Gitbook: [texto](https://app.gitbook.com/o/…/s/…)`
— a citation to the page's own GitBook web UI, not an embedded asset. There is
nothing to re-host (it's a link to a page, not a file) and no reliable way to
remap it to an equivalent page in this app, so it is left as-is and will go
stale once the space is retired from GitBook — an accepted limitation of the
migration, not a defect to chase.

When you need a GitBook API fact, read `https://api.gitbook.com/openapi.json`
(fetches fine, ~1.6MB). Their documentation site 404s or returns "query us with
`?ask=`" stubs, and search summaries about it were wrong in both directions.

### Re-filing a page: moving a DocumentationPage to another caderno

`notebooks.pages.notebook` (PATCH) moves a page under a different `Notebook` —
the other half of the import, and the only way content leaves the caderno a
space landed in. Not to be confused with `notebooks.pages.move`, which moves a
page WITHIN its caderno — `up`/`down` among its siblings, `in`/`out` one level
at a time; they are two different endpoints with two different requests
(`MoveDocumentationPageToNotebookRequest` vs `MoveDocumentationPageRequest`).

Six things about it that are easy to get wrong:

- **It answers with `redirect`, not an updatable slot.** Every other rail action
  either stays put (reorder → `PagesNav::slot()`) or renames in place; a caderno
  move changes the page's URL, so swapping the rail would leave the browser on a
  URL where that page no longer exists.
- **Two authorizations, and only one of them is the request's.** The FormRequest
  authorizes the SOURCE (the route model). The destination is a different record
  with its own policy, and a failure there must be a **403, not a 422** — so the
  controller resolves `$request->destination()` and calls
  `authorize('update', …)` on it. Don't fold that into a validation rule.
- **`notebook_id` is not in `$fillable`** (§ Security), so
  `DocumentationPageService::moveToNotebook()` uses
  `$page->notebook()->associate($destination)` — never `update()`, which would
  either be silently discarded or force widening mass assignment.
- **The slug is re-checked against the destination**, because it is unique per
  caderno, not globally (`unique(notebook_id, slug)`). It is kept when free
  there and suffixed when not; `position` always goes to the end of the
  destination.
- **A nesting is never left straddling two cadernos.** A move has to resolve
  the other half of it: a page with subpages takes its WHOLE subtree along
  (grandchildren included — moving one level would leave the rest filed under a
  caderno they were never in), and a SUBPAGE moved on its own lands as a
  top-level page, since its parent stayed behind. Slugs are re-checked against
  the destination depth-first, parents before children.
- **`destinationsFor()` is called ONCE per rail, not per row.** It queries every
  caderno; per-row would be a query per page in the sidebar. It returns a FLAT
  list — it was two `<optgroup>`s ("Soluções"/"Grupos") while a destination
  could be either kind of container. The current caderno is excluded, which is
  what makes the rail hide the "Mover para…" entry when there is nowhere to go.

Embedded media needs nothing: it belongs to the page and `MediaController` gates
on the collection name, so `/files/{id}` keeps resolving after the move.
