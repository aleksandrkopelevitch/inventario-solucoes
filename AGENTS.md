# Inventário de Soluções — Claude Guidelines

Catalog of Leo Madeiras' solutions/integrations: solution, people and company
records, a documentation module (**cadernos** — a page tree per `Notebook`,
each linked to 0..N solutions),
a **diagrams** module (the graphical topology editor, one drawing at a time),
and a read-only map of the ecosystem derived from those drawings. Fork of the
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

### Diagram topology invariant — the chain is the single source of truth

A `Diagram` is a drawing of a flow, and a first-class record: `/diagrams` is its
module, `/diagrams/{diagram}` is the canvas that authors it. It used to be an
`Integration` — reachable only through a solution that took part in it, carrying
a `documentation` column of its own — and both halves of that are gone (see
§ Cadernos for what replaced the second one).

Its topology lives in the `chain` json — a genuinely free
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
`SyncDiagramFromChain`, `ChainLabeler::nodeLabel()` and
`ChainGraph::resolveNode()` — all decide via
`ChainNodeKind::referencesSolution()`, so a stale `solution_id` on a
decision/actor node can never resurrect it as a participant. Both endpoints
that write a node (`addNode`/`updateNode`) validate the same three fields via
the `ValidatesChainNode` trait.

**Three kinds are drawn as a CIRCLE with the label outside the shape** —
`start`, `end` and `actor` (`chain-viz.js::paintNode()`, one branch for all
three). That matters beyond looks: the label is absolutely positioned at
`top: 100%`, deliberately OUTSIDE the node's box, because `node.w`/`h` come
from `offsetWidth`/`offsetHeight` and every port and edge anchor is computed
from them — let the label into the box and the anchors drift toward whatever
the text's height happens to be. Every other kind is a **white** box; shape
carries the kind (chamfered hexagon = decision, dashed border = external free
text) and color is left to mean whatever the author decides
(`viz_layout.nodes[i].color`). Only the two flow terminals keep a fill of their
own, green/red.

**Removing a node is the one mutation that REINDEXES.** `removeNode()` drops
the block, every edge touching it, and decrements every surviving `from`/`to`
above the removed index — then reindexes `viz_layout` in three places, because
`nodes` and `comments` there are keyed by NODE index while `edges` (anchors) is
keyed by EDGE index. Miss one and blocks silently inherit their neighbour's
position or comment. Root (index 0) is never removable. It's also the only
chain endpoint that returns a **whole rebuilt graph** (`ChainGraph::for()`)
instead of a patch: after a reindex there's nothing the
client can safely patch, so it calls `render()` again — and drops its
`savedLayouts` cache entry first, since that cache is keyed by the old node
count.

Two edges between the same pair of blocks are legitimate when they say
something different (A `->` B over REST *and* over SFTP, or one edge each
way), so `AddChainEdgeRequest` refuses only an **exact** duplicate
(same `from`/`to`/`arrow`/`protocol`) — dragging an arrow out of a port creates
`->`/no-protocol with no dialog on the way, so repeating the gesture is easy to
do by accident, and the second arrow would double-count in the degree math
above while being indistinguishable in the canvas.

`App\Actions\SyncDiagramFromChain`
is the ONLY thing that writes the derived columns (`participants` pivot with
`position`, `source/target_solution_id`, `direction`, and the summary scalar
`protocol` = first non-null edge protocol) — it runs after every mutation to
`chain`, via `Diagram::afterChainMutation()`, which
`Concerns\EditsChain` calls for every one of the nine endpoints. The ecosystem
map is a reading of those columns, which is what makes it a reading of the
drawings rather than a second truth. `Diagram.viz_layout`
(`{nodes: [{x,y}], edges: [{from,to}], comments}`) is a purely **visual**
concern — node position/style and per-block comments in the graphical canvas
(`resources/js/modules/chain-viz.js`) — and must NEVER drive topology;
`saveLayout()` writes only `viz_layout`, never touching `chain` or the derived
columns. Don't write the derived columns directly — edit `chain` and let the
action re-derive.

**Adding a block must not change the zoom.** `appendNode()` used to end with
`fit()`, which recomputes `view.scale` — so drawing a ten-block flow meant ten
scale jumps and threw away the zoom the person had chosen to work at. It calls
`panIntoView()` instead: the minimum pan that brings the new block into the
viewport, scale untouched, and nothing at all when it was already visible. Only
"Organizar", "Centralizar" and the initial load re-frame. (Do not confuse
`panIntoView()` with `revealNode()` in the same file — that one is presentation
mode's fade-in. The two names collided in the first version of this and the
second declaration silently won.)

**The canvas is owner-agnostic, and there are two owners.**
`App\Contracts\ChainCanvas` is the contract; `Concerns\EditsChain` performs
all nine mutations against anything implementing it. `Diagram` re-derives its
columns in `afterChainMutation()`; a `SubmissionDiagram` (a proposal's AS IS /
TO BE) derives nothing, deliberately. The client never learns which it is
editing, because every endpoint it calls arrives inside the graph payload
(`ChainCanvas::chainUrls()`) — which is why `chain-viz.js` contains no route of
its own and must keep containing none.

### Cadernos — a Notebook is the one container, and it documents 0..N Solutions

**A body of documentation is a `Notebook` ("Caderno"), modelled on a GitBook
Space.** It is the ONE container of `DocumentationPage`s, and it relates to
**0..N Solutions** through the `notebook_solution` pivot.

Both halves of that matter:

- **A page has one owner, `notebook_id`** — a plain FK, not the polymorphic
  `container` (`Solution` | `DocumentationGroup`) it replaced. That collapse is
  what removed two near-duplicate controller families, two route families and
  every `instanceof` branch that turned a page into a URL.
- **A caderno describes as many systems as it actually describes.** An
  integration's documentation is one text read from both ends of it; the old
  container could only ever name one owner, so the same text had to be written
  twice or filed under one side arbitrarily. **Zero is a normal state too** —
  a cross-cutting process, or a freshly imported GitBook space nobody has filed.

A Solution therefore **owns no pages** (`Solution::pages()` does not exist).
Anything asking "is this solution documented?" goes through
`whereHas('notebooks.documentedPages')`. Two things moved onto the notebook with
the container: the public magic link (`notebooks.public_token`) and the Assiste
IA context documents (`Notebook::CONTEXT_COLLECTION`).

**A page CITES a diagram; it does not own one.** `documentation_pages.diagram_id`
is gone, and with it the Documentação/Diagrama tab pair a linked page used to
grow. A drawing is referenced from prose with `{% diagram slug="…" %}` — a fifth
construct in the dialect, self-closing with one attribute, the same shape as the
four GitBook ones, so both parsers (`GitbookRenderer` and `docs-markdown.js`)
take it the way they take `file`.

It renders as the drawing's current picture plus a link that opens the canvas in
a **new tab — and only for someone signed in.**
`GitbookRenderer::render(..., linkDiagrams: false)` withholds that link from the
public magic link and from the search index. `/diagrams/{slug}` is behind auth,
so for a visitor the button is both a dead end onto the login screen and a
disclosure: it names a drawing they cannot reach and hands over its slug. The
card itself renders either way — the picture and the name are documentation, the
link is an editing affordance. The PICTURE therefore needs a public route of its
own (`public.docs.diagram`), authorised by CITATION rather than by the diagram:
the token grants one caderno, and what that caderno shows is what its pages
cite, so an uncited drawing 404s even with a valid token. Without it every
citation on a shared link rendered a broken image, since
`diagrams.picture.show` redirects a guest to the login screen — withholding the
link and letting the image 302 are not the same thing. The index passes `false` for a second reason: it
is CACHED, so a render that varied by the viewer's auth state would bake one
audience's chrome into everybody's results, and "Abrir diagrama" is a button
nobody should be able to search for.

It degrades on purpose: a diagram with no snapshot yet says so
(the PNG is posted by the BROWSER after a layout save, so a diagram nobody has
opened since that feature landed has none), and a deleted diagram becomes a
"removido" card rather than damaging the prose around it.

Addressed by SLUG, never id: it is what the author picked and what the URL shows,
it survives a database reload between environments, and a stale citation still
reads as something. The picker (`diagrams.catalog`) groups the catalog by
SOLUTION — the only relation a diagram has left — with a trailing group for
drawings that name none, since those are still citable.

**A link between two pages is `[texto](page:{slug})`, resolved per READER.**
It is the one construct of the dialect that is not a `{% … %}` block — it is an
ordinary Markdown link with a made-up scheme, which is what keeps both parsers,
Editor.js's link tool and "Copiar Markdown" working with no change at all.
`#anchor` alone means a heading of the page you are on;
`page:{slug}#anchor` a heading of another page of the same caderno.

The reason it is not simply a URL is that the same page has TWO addresses:
`notebooks/{caderno}/{página}` for somebody signed in, and
`public-docs/{token}/page/{slug}` for a visitor holding the magic link. An
address written into the Markdown is therefore correct for exactly one audience
— a shared caderno full of links to a login screen, or an internal page full of
links carrying a token. `App\Support\Documentation\PageLinks` is what each
reader's render is handed (`internal()` / `shared()` / `none()`), and
`GitbookRenderer::resolvePageLinks()` substitutes the href. It also survives a
caderno being renamed, which a stored URL would not.

Five things that follow, and each of them has a reason that is not obvious:

- **Scoped to ONE caderno, deliberately.** The public reader can only answer for
  the caderno its token grants, so a link that could point outside it would be a
  link that works while you edit and dies the moment the caderno is shared.
  `notebooks.link-targets` (the picker's catalog) is scoped the same way. Context
  for the ASSISTANT is deliberately not (§ below) — reading a page once is not
  the same promise as addressing it forever.
- **A slug the caderno does not have loses its `href` ENTIRELY.** An `<a>` with
  no href is not a link: it renders as the words the author wrote (styled by
  `.html-content a:not([href])`), which is the same promise the "Diagrama
  removido" card makes — deleting a page must never damage the prose that
  mentioned it. Pointing it at `#` instead would look like a link and scroll the
  reader to the top for no stated reason.
- **The resolution runs over the FINISHED html**, not over the Markdown, for the
  same reason `paintSecrets()` does: `renderLines()` recurses, and a link is
  just as legitimate inside a hint, a tab or a table cell. One pass covers every
  nesting — and catches a link pasted as raw HTML, which never reaches the
  Markdown parser.
- **Anchors are never derived in the browser.** The picker
  (`docs-tools/link.js`) is fed `DocumentationSearchService::linkTargets()`,
  which is a READING of the search index — so the anchors are the ones
  commonmark actually emitted, accents and `-1` collision suffixes included
  (`id="autenticação"`, while the same anchor in a link destination comes out
  percent-encoded; the browser matches the two). Re-implementing the slugger
  client-side drifts silently: right page, wrong place.
- **The `link` inline tool REPLACES Editor.js's built-in one** (naming an
  internal tool in `tools` is the supported way). One button, not two: "Link"
  and "Link interno" side by side would be two subtly different answers to the
  same gesture. It keeps upstream's input for a typed URL and adds the picker;
  what it does NOT keep is the fake background (`hiliteColor`), because removing
  that highlight rewrites the very text nodes the saved Range points at — and
  the Range has to survive a modal opening, so the link is inserted with DOM
  calls rather than `execCommand`.

**There is exactly ONE kind of documentation: the page.** There used to be two —
a page tree, and an integration's own single-page `documentation` column with
its own editor route, its own place in the rail and its own coverage
percentage. The second one is gone. What it was really for (text beside a
drawing) is now a page pointing at a `Diagram`
(`documentation_pages.diagram_id`, nullable, written only by
`Concerns\LinksPageDiagram` through `diagram()->associate()` — it is NOT in
`$fillable`, like `parent_id` and `notebook_id`). The FK lives on the PAGE, so
one diagram serves 1..N pages: the same drawing legitimately explains several
pages, often in several cadernos, while a page never has two drawings to
reconcile. A page that has one grows a Documentação/Diagrama tab pair and mounts
the canvas; linking answers with a `redirect`, not a slot, because the shape of
the screen changes with it. `nullOnDelete`: deleting a drawing must never take
the text explaining it.

A caderno's documentation is a tree of `DocumentationPage`s up to
`DocumentationPage::MAX_DEPTH` levels deep (5 today, which is the depth the
imported GitBook corpus actually uses), via a self-referencing `parent_id`. The
cap is that constant and nothing else, so changing the depth is one edit there
plus one literal indent step per level in the three views that draw the tree —
and `parent_id` is deliberately absent from `$fillable`, like `notebook_id`: the
tree is written through `parent()->associate()`, never mass-assigned.

The trap is `position`: it orders a page among its **siblings**, so
`$notebook->pages()` — a flat `orderBy('position')` over every page at every
depth — is **not reading order**, and `pages()->first()` is not the caderno's
first page. Anything that shows the tree to a human walks
`DocumentationPageService::tree()` (one query, recursion in memory, each row
carrying its `depth` and which gestures it can perform); anything that opens a
caderno uses `firstPage()`. What only asks "is there content in here?"
(coverage, the flowSpec picker, slug uniqueness) can keep using the flat
relation, because depth doesn't change that answer.

Three more rules that are easy to half-implement:

- **A nesting is judged by the SUBTREE being moved, not by the page.** Sliding a
  page one level down drags its own subpages with it, so what has to fit under
  the cap is `parent depth + subtree height` (`canBeNestedUnder()`). A page with
  subpages may be nested; a page with grandchildren may not.
- **Moving a page to another caderno carries its whole subtree**
  (`moveToNotebook()`), depth-first — slugs re-checked against the destination,
  parents before children. Moving one level would leave deeper pages filed under
  a caderno they were never in. A page moved *without* its parent lands as a
  root there instead.
- **Deleting goes through the models, recursively** — `children()->get()`, never
  the `children` property, since a caderno delete hydrates its pages in bulk
  and strict mode then turns a lazy load into a 500 (§ Strict mode). The FK's
  `cascadeOnDelete` is the safety net; the model hook is what lets Spatie clean
  each page's embedded media.
- **A caderno is deleted from the CATALOG card, by an admin.** `notebooks.destroy`
  existed with no caller at all — the route appeared in no view, so the only way
  to remove one was the database. The trash beside the pencil is that caller, and
  the two answer to different rules on the same card: `update` (editor) opens the
  rename panel, `delete` (admin) removes the caderno, so the trash is a missing
  affordance for an editor rather than a button that refuses. It answers with the
  catalog SLOT and not the `redirect` it used to send — from the catalog, a
  redirect to the catalog is a full reload of the page you are standing on that
  throws away the filters the URL still shows. The confirm states the two
  consequences that are COUNTED rather than guessed: how many pages go with it,
  and whether a public link somebody already holds stops working.

The rail (`x-documentation.pages-nav`) renders that walk as ONE flat `@foreach`
with an indent class per depth — deliberately not a recursive partial, so every
row keeps a unique `$loop->index` for its hidden forms — and the indent steps
are literal classes, because Tailwind only ships what it can see in the source
(`ml-{{ $n }}` compiles to nothing).

### Public documentation search — the corpus is an INDEX, not a query

`/public-docs/{token}/search` backs the search panel on the magic-link
documentation (`docs-search.js` + `x-documentation.search-panel`). It is the
one place the documentation is treated as a queryable dataset rather than as
pages. The token resolves a `Notebook` — what gets shared is ONE caderno,
whatever it happens to be linked to, so linking a notebook to a solution
publishes nothing.

**It is a ⌘K palette (`<dialog>`), triggered from the topbar.** It was a
palette, then an inline panel, and is a palette again — and the round trip is
not indecision, it is the controls changing meaning. The inline version existed
because its facets ("results that CONTAIN a table") were the feature and had to
be visible. The palette's controls answer a different question:

**`SCOPES` (`prose` / `table` / `code`) say WHERE a query looks, not what it
narrows to.** Every entry's text is bucketed into exactly those three at index
time, so a scope costs nothing at query time beyond concatenating the buckets
that are on. That is what makes the corpus interrogable: a column name lives in
tables, an env var in code, a policy in prose, and searching all three at once
buries whichever you meant. The switches live in the palette beside the field —
visible the whole time a query is — which is why the old objection doesn't apply
to them. The content-tag facet row was removed with the move: two rows of
table/code vocabulary asking subtly different questions was the confusing part.

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

### A protected value leaves the server once, one value per request

`{% secret %}…{% endsecret %}` hides a value inside a documentation page — an
`Authorization` header, an env var, a service password. The reader sees a
**lock**; the plaintext is handed out by
`App\Actions\Documentation\RevealPageSecret` and by nothing else, to an admin
or to whoever types the caderno's secret code (`notebooks.secret_code`, six
characters, shown only on the admin's share panel and rotatable there).

**It is the sixth construct of the dialect and the only INLINE one** — a
protected value is a token in a sentence, a table cell or a line of a code
sample, never a block. So it is handled in `App\Support\Documentation\SecretText`
plus `inlineToMd()`/`inlineToHtml()` in `docs-markdown.js`, not in
`serializeBlock()`/`parseLines()`, and the editor writes it with the app's one
INLINE Editor.js tool (`docs-tools/secret.js`, a `<span class="ak-secret-mark">`).

**A value is addressed by its ORDINAL** — 1 for the first `{% secret %}` in the
page's text, in document order. It lives inline in the Markdown, so there is no
row to give it an id, and three surfaces have to agree on the number: the
renderer that paints the locks, the reveal endpoint that re-parses the page, and
the editor, which is shown `[[SECRET-n]]` markers (the same marker vocabulary as
`LiteralVault`, one step further: that keeps a literal from the MODEL, this keeps
it from the PERSON). Numbering by position costs one thing, accepted: a reader
holding a page while somebody adds a value above it reveals the wrong one of the
two.

Six things that are easy to undo, and all of them are the same rule seen from a
different screen:

- **Every audience gets masked content, admins included.**
  `EditsDocumentation::documentationView()` masks unconditionally and
  `persistDocumentation()` restores unconditionally. The strict version is what
  makes the invariant auditable — the plaintext has exactly one door — and it is
  what stops an admin's editor putting a page's worth of credentials on screen,
  into the assistant chat's stored `existing_content`, and into "Copiar
  Markdown". The restore has to stay unconditional for a second reason: the
  assistant is ALWAYS shown markers, so an admin who applies a draft is saving
  markers too.
- **The renderer has no "show them anyway" flag.** It had one for about an hour;
  the only screen that could have passed it true does not exist, because
  everyone who may read the values can also edit the page and therefore gets the
  editor, never the read-only render.
- **The four other places a page's text is handed to somebody are masked too**,
  and each was a live leak while it wasn't: the read-only "Copiar Markdown"
  textarea, the public one (`PublicDocumentationController::render()`), the
  documentation assistant's prompt (`DocumentationChatService`) and the
  **flowSpec** assistant's (`FlowspecPromptBuilder::documentationSection()`).
  The search index needs nothing — it indexes the RENDERED html, so it indexes
  locks.
- **The throttle is the protection, not the code's length.** Six alphanumerics
  is ~36 bits; five attempts per reader per caderno per twelve hours is what
  makes an online search of that space pointless. It lives in the action rather
  than as `throttle:5,720` on the route because a middleware would count an
  admin's successful reveals and could not CLEAR the counter — a correct code
  buys back the failures before it. `docs-secret.js` mirrors the same window in
  `localStorage`, which is a courtesy (an immediate, legible lockout), never the
  limit.
- **A code fence holding a lock is left UNPAINTED.** `docs-highlight.js` rebuilds
  a block from its `textContent`, so highlighting would turn the lock's
  `<button>` back into the words "valor protegido" — no click target and no
  explanation. `docs-code.js` skips those blocks; monochrome code is the cheaper
  half to lose. (Getting a lock into a fence at all is why the renderer
  substitutes a `\x1F`-delimited TOKEN before parsing and paints markup only
  afterwards: `renderLines()` consumes a fence verbatim and commonmark escapes
  everything in it.)
- **The reveal popover is appended to `document.body`.** In the editor the lock
  is a marker inside Editor.js, which decides a block changed by watching its
  DOM — a field injected beside it reads as an edit and autosave writes the page
  seconds later (the trap `docs-tools/diagram.js` needs `data-mutation-free`
  for).
- **A protected value lives inside `<code>` more often than not, and that is
  where the round trip broke.** `nodeToMd`'s `CODE` branch serialized with
  `textContent`, which flattened `<code><span class="ak-secret-mark">` into
  `` `[[SECRET-n]]` `` — the construct gone, the marker bare — and a plain
  restore then wrote the real value into the page in the clear, with the editor
  showing a lock chip right up until the save. Both nestings now serialize to
  ONE shape, backticks outside and the construct inside (the only order the
  renderer paints as a lock inside `<code>`), and `codeToHtml()` turns it back
  into a chip on the way in so it is clickable rather than raw text.
- **`restore()` RE-WRAPS a marker that lost its construct** rather than
  resolving it into naked plaintext. That is what makes the protection the
  server's and not the client's: no serializer bug of the shape above can
  unprotect a value again, whatever the client posts. Nobody types
  `[[SECRET-2]]` by hand, so a bare marker can only mean "the protected value
  that was here"; deleting the marker is still how a value is unprotected, and
  that stays unambiguous.

Sharing and the code are `NotebookPolicy::administer` (admin), NOT `update`:
both reach beyond the page an editor is writing, and an editor who could read
the code off the share panel would be able to unlock exactly what this exists to
keep from them.

### The assistant reads other pages; it rewrites exactly one

A Documentation Assistant turn can be handed OTHER documentation pages as
reference (`documentation_chat_messages.context_page_ids`, resolved by
`App\Services\Documentation\ContextPageResolver`). It exists beside the
caderno's uploaded context documents rather than inside them because the two
answer different questions: a document is material somebody brought from
outside and belongs to the caderno; a page is documentation this app already
holds, is always text, and is regularly in ANOTHER caderno — the page most worth
showing while documenting an integration is the one describing the system on the
other end of it. That is why the picker (`notebooks.context-pages`) spans every
caderno while the LINK picker does not: reading a page once is not the same
promise as addressing it forever.

**Both live behind ONE [+] in the composer**, which is the same box
`x-flowspec.composer` and `x-submissions.composer` are — a rounded frame holding this turn's context pills, the
textarea and a toolbar. They arrived as two labelled sections stacked above the
textarea, each with a `+` of its own, and on a docked panel 320px wide those two
rows pushed the message box to the floor. The menu has exactly two items because
there are exactly two doors; a long paste is the third and needs no item, since
it becomes a document on its own. Two details are load-bearing: the pills sit
inside the BOX but outside `#docs-chat-message-form` (each document pill carries
its own `<form>` for the remove button, and a nested form is dropped by the
parser — § Blade), and TONE in that row means which KIND of context a pill is
(neutral for material somebody brought, accent for documentation already in the
inventory), never whether it is checked. A document pill used to turn accent when
checked, which was unambiguous while it had a section to itself and became a
second pill kind the moment the rows merged; withheld is said by the checkbox
plus a dim.

All three composers are the same box and carry the same `[+]` — a round ghost
button, never a paper-clip, since it is one gesture and should not be three
buttons. What the box does NOT unify is the send button's label: flowSpec's
new-chat screen says "Gerar flowSpec", so that button stays label-only while the
two chat ones carry a paper-airplane. Two pieces of copy PICTURE the `[+]` in
prose (`x-submissions.sources`' empty state and the flowSpec index hero) and
have to move with it, or they point at a button that no longer exists.

The CATI composer's menu taught one rule the other two did not have to learn:
**an item of that menu has to close it itself.** `toggle.js` closes a popover on
an OUTSIDE click, and neither item's click is one — so attaching a link left the
menu standing over the chip it had just created, which was the only feedback the
attach had worked. The file item appeared to work and did so by accident: it
clicks a hidden input that sits in the form and not in the menu, so that
synthetic click bubbled out and tripped the outside-click listener. One move of
that input and the menu would have stayed open behind a MODAL file dialog, whose
own dismissing click never reaches `document` at all. All three composers close
it explicitly now (`closeAttachMenu()`), and CATI's link item closes it only on
SUCCESS: a refused url has to keep the field, and the menu holding it, on screen
to be corrected.

Five rules, and four of them are the module's existing rules seen from a new
screen:

- **Masked.** `SecretText::mask()` — this is the FIFTH surface that hands a
  page's text to somebody, and the only one where the page being read is not the
  page being edited, so a value an editor may not see in caderno A must not
  become quotable into caderno B.
- **Media blocks are STRIPPED, not frozen** (`BlockVault::strip()`, which
  reuses the same PATTERNS). A `[[BLOCK-n]]` marker is an instruction to KEEP a
  block, and these blocks belong to a different page; handing over the raw
  markup is worse still, since the model can copy a `/files/{id}` it is shown —
  and that would half-work, rendering inside the app and breaking on the magic
  link (`PublicDocumentationController::file()` scopes media to the caderno's
  own pages). What it sees is `[imagem]`, deliberately not a `[[…]]` marker, so
  no restore anywhere can resolve it.
- **The page being written is never its own reference.** Its text is already in
  the prompt as "CONTEÚDO ATUAL DA PÁGINA", and a second copy under another
  heading is how a model loses track of which of the two the draft replaces.
  The section heading says out loud what these pages are NOT, for the same
  reason.
- **Capped and budgeted separately** (`max_context_pages`,
  `page_budget_chars`), because an uploaded document and a page compete for the
  same prompt and one runaway page must not push out the PDF somebody attached.
  What does not fit is FLAGGED (`meta.omitted_pages`), never dropped in silence:
  somebody picked it by hand.
- **The system prompt now names the `page:` construct** in the allowed inline
  syntax, and that is not a courtesy — a reply rewrites the WHOLE page, so a
  model told only about `[texto](https://url)` would "fix" an internal link into
  a URL and quietly break it. It is also told never to INVENT a slug or an
  anchor, which is the honest half of the rule: a slug the caderno lacks renders
  as text with no link at all.

### The assistant is never asked to obey a rule it cannot keep

Two vaults sit in front of the Documentation Assistant, for two different
reasons, and the second one exists because of a rule that read as sensible and
deleted people's work.

`App\Support\Documentation\BlockVault` freezes the blocks the model may
neither write nor lose — `<figure>`/`<img>`, `{% file %}`, `{% embed %}`,
`{% diagram %}` — as `[[BLOCK-n]]` markers. The system prompt used to ban that
syntax outright ("não use imagens, `<figure>`, `<img>`, `{% file %}` nem
`{% embed %}`") while also demanding the COMPLETE page back in the draft. On any
page with an image those two rules contradict each other, and the model resolved
it the only way it could: by deleting the figure that was already there, while
answering a request about something else. Reported from the app 2026-08-31.

The ban's intent was right and its wording was not. The model cannot author one
of these blocks — a `/files/{id}` needs a media id only the upload knows, a
`{% diagram %}` a slug from the catalog — but that is an argument for never
showing it the syntax, not for telling it to leave the syntax out of a document
that already contains it.

Four things to keep:

- **PATTERNS order is load-bearing.** A `<figure>` contains an `<img>`, so the
  figure is captured first, and `capture()` walks a copy in which every
  captured block is already a marker — that is what stops the image inside a
  figure being frozen on its own.
- **A dropped block is COUNTED, never re-inserted.** A marker the model deleted
  has no position left to restore it to, and guessing one would rewrite
  somebody's page. `droppedNotice()` appends a PT-BR warning to the
  conversational half instead, and `meta.blocks` audits the turn — removing an
  image is legitimate when it was asked for, so the notice states what is
  missing and leaves the judgement to whoever presses "Aplicar".
- **The audit runs on the RAW DRAFT, before any restore.** A marker is only a
  marker until then; and it must be the draft rather than the whole reply,
  because a model that explains itself ("removi o [[BLOCK-2]]") would otherwise
  be counted as having kept it — which is precisely the turn this guard is for.
- **Three vocabularies, deliberately separate**: `[[LIT-n]]` (LiteralVault,
  opaque literals), `[[SECRET-n]]` (SecretText, protected values) and
  `[[BLOCK-n]]` (here). A shared prefix would let one restore resolve another's
  markers.

### The Documentation Assistant never sees an opaque literal

A reply from the Especialista em Documentação rewrites the WHOLE page (the
4-backtick draft block), so every literal on it has to survive being copied
character by character by a language model — and long high-entropy strings are
exactly what that copying gets wrong, silently. A 212-character SAP CPI
`Authorization` header came back with its tail rewritten (`…VU2s9` → `…VU2n=`);
asked to fix it, the assistant produced a third variant and stated it had
restored the original. Nothing in the pipeline could tell: to every layer
below, one base64 blob looks exactly like another.

So the model is never given the chance. `App\Support\Documentation\LiteralVault`
replaces each opaque literal with a marker (`[[LIT-1]]`) in everything the
prompt shows — current content, this turn's message, history, inlined context
documents — and puts the real bytes back in the reply, in the conversational
half as well as in the draft. The model can still MOVE a value (that is
copying a nine-character marker) and can still be told which is which (the
legend names each marker's kind, length and first 8 characters — the same
disclosure `SensitiveTextScanner` already makes), but it cannot retype one.

Four things that are easy to undo:

- **The thresholds are measured, not guessed.** A literal is a run of
  `[A-Za-z0-9+/=_.~-]` that is a JWT, 32+ hex characters, or 40+ characters
  with Shannon entropy ≥ 4.5 bits/char AND a longest single-class run ≤ 8.
  Measured 2026-08-30 over the whole dev corpus (207 pages): those rules flag 9
  strings, all genuinely opaque, and none of the ~570 long identifiers the same
  corpus contains — `additionalData_payload_transaction_authorizationCode`
  (H=3.8), `S4hana/depara_fornecedor_QAS500/…` (H=4.1), a table's `-----` rule
  (H=0). The run cap is what does most of the work: a word IS a run of one
  class, so identifiers sit at 8–13 and random tokens at 3–6. Loosen either and
  field names start disappearing behind markers.
- **Mask before the prompt, restore before `extractDraft()`.** One restore over
  the raw reply text covers both halves; the markers contain no backticks, so
  the fence regex is unaffected.
- **The repair pass only fires on a UNIQUE prefix owner.** The model can still
  retype a value it read from a native attachment (a PDF is handed over as-is
  and never passes through `mask()`), so a candidate sharing a vaulted
  literal's first 24 characters at ≥ 90% similarity is replaced by the vaulted
  one. Two tokens for the same service in different environments are base64 of
  nearly the same plaintext and share a long prefix — "closest match" would
  swap PRD for QAS, which is why an ambiguous prefix is left alone rather than
  guessed.
- **Test fixtures are synthetic.** Same shape as the header that exposed this
  (`sb-<uuid>!b<n>|<subaccount>:<uuid>$<secret>`, base64), never a real
  credential — a test suite is not a place to keep one.

`meta.literals` (`frozen`/`repaired`/`unresolved`) audits each turn;
`unresolved` counts markers the model invented, which is the shape of a
prompt regression.

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
`/people`, `/notebooks`, `/documentation`, `/map`, `/flowspec`) even though every
label the user reads is PT-BR — `/notebooks` is where a **caderno** lives. Keep
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
  refuses `pages`/`share`/`context`/`chat`/`solutions`/`panel` for a page, and
  `NotebookController::RESERVED_SLUGS` refuses `panel` for a caderno (there is a
  real `notebooks/panel` route). That list was PT-BR and stale for months —
  reserving five words no route used while leaving the five that mattered free
  to collide — so check it against `route:list` when adding a segment.

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

## Security

### Access is an attribute of a PERSON, and an account can still have none

`people.user_id` (nullable, unique) links a `Person` to the account they log in
with. Both directions stay optional, and that is the whole shape of this:

- **Most people never log in.** They are vendor contacts — 106 of the 108 rows
  in dev have no email at all — so a person without an account is the ordinary
  state, said plainly on the card rather than hidden.
- **An account without a Person is normal too**, which is why the roster at
  `/people/accounts` still exists after access management moved onto each
  person's page. `admin@leomadeiras.com.br` comes from `DatabaseSeeder` and never
  will have a catalog row; a screen listing only "people who have accounts"
  would leave the one account that cannot be locked out with no screen at all.
  It is also the only place an ORPHAN account's ROLE can be changed.
- **But an orphan is no longer MANUFACTURED.** `UserController::store()` — the
  invite — used to create a `User` and never a `Person`, which made it the app's
  orphan factory and left "vincular uma conta que já existe" a routine step
  rather than a repair. It goes through `GrantPersonAccess::invite()` now: the
  account AND the catalog row, linked, reusing the person already filed under
  that e-mail (`Person::withEmail()`, folded EQUALITY) instead of duplicating
  them. What is listed without a person is the seeder's admin and rows older than
  2026-09-01.

The "Usuários" modal this replaced was about an email rather than about a human,
because the two tables had no relation: the app could list who was able to log in
and could not say who any of them were. `UserController` kept the two endpoints
that really are about the account (`store` = invite somebody NOT in the catalog,
`update` = the role, shared by both screens); its screen is gone.

**The two tables are NOT merged, and an account is not a small person.** Asked
directly (2026-09-01: "shouldn't it be one unique entity?"), the answer is no,
for two reasons that are specific to this app rather than to Laravel. First,
**105 of 108 catalog rows have no e-mail at all** — merging puts vendor contacts
into the authentication table and makes `email`/`password` nullable so 97% of
rows can hold neither, while "delete a contact" starts meaning "delete an
identity that authored submissions" (nine FK columns point at `users`). Second,
**the authority split falls exactly on the table boundary**: `people` is writable
by an EDITOR, an account only by an admin, which is the whole point of the rule
below — one merged row would be written by two authority levels, with `role`,
`email` and `password` guarded by field lists instead of by a policy.

What WAS wrong was the presentation, and it is fixed at the source rather than
apologised for: an account is rendered as a CREDENTIAL everywhere it is offered
— the orphan picker's options are `e-mail · perfil` and the roster's rows lead
with the e-mail — because `users.name` is a human name, so leading with it made
the gesture read as linking a person to another person. `users.name` survives for
the one thing it is: how the app greets whoever is signed in.

**`UserPolicy::manage`, never `PersonPolicy::update`.** This is the trap the
whole feature turns on: an EDITOR may rewrite a person's job title, company and
system links, and must not be able to hand out an account — least of all an admin
one. The two live on the same page and answer to different rules, which is why
`GrantPersonAccessRequest`/`LinkPersonAccountRequest` exist instead of reusing the
person's own authorization. `user_id` is deliberately absent from `Person`'s
`$fillable` for the same reason: granting access must not be reachable by posting
a field to the edit panel an editor CAN reach.

**The access link leads to the password screen and never to a session.** The
obvious implementation authenticates the holder and drops them inside the app,
and that is exactly what `AccessLinkController` must not do — a URL forwarded in
a Teams thread would then BE the account. Its whole privilege is "you may set
this account's password"; the person then logs in like anybody else, one screen
further, and ends up with a credential of their own instead of a link they have
to keep.

Four things that make the link's generosity safe:

- **It is spent the moment the password is set.** `ClearAccessTokenAfterPasswordReset`
  listens to `PasswordReset` (auto-discovered, and already fired by
  `ResetPasswordController`), so the real lifetime is "until it works, and at
  most `User::ACCESS_TOKEN_DAYS`". Left alive it would be a seven-day
  password-reset link for a live account. The ordinary "esqueci minha senha"
  flow fires the same event, so a person who resets by email also invalidates a
  link they were sent — neither path has to remember to.
- **Each open mints a FRESH Laravel reset token.** That is what lets the access
  link be reusable for days while the thing it hands over stays short-lived
  (`config('auth.passwords.users.expire')`, 60 minutes).
- **Generating a new link replaces the old one** — `unique` on the column would
  refuse a duplicate anyway, and it is the only way to kill a link that went to
  the wrong person.
- **A dead link answers with ONE message** for "never existed", "already used"
  and "expired". Telling them apart tells a stranger holding a dead URL whether
  the account behind it is real.

**Revoking answers from BOTH screens, and the roster is the one that matters.**
`GrantPersonAccess::revoke(Person)` delegates to `revokeAccount(User)` so the two
behave alike — and the second exists because an account does not need a Person.
Revoking lived only on a person's Acesso card at first, which left an orphan with
its ROLE changeable on the roster and no way to be switched off anywhere at all.
The refusal is the same one the role carries (nobody revokes their own account),
and it is what keeps at least one panel-holder able to log in: revoking needs an
admin asking about somebody else, so two exist and one always survives — no
"last admin" guard, for the same reason it would be dead code there.

**Revoking soft-deletes the account and unlinks it.** That stops the person
logging in (Laravel's user provider applies the default scope, so an existing
session stops resolving) while their submissions and chats keep pointing at a row
that exists. Granting again RESTORES the same row rather than creating a second
account beside it — `GrantPersonAccess::grant()` looks with `withTrashed()`,
which is also what keeps the unique index on `email` from refusing the insert.
Erasing an account for real is still database-only.

**UNLINKING is not revoking, and the card offers both.** `link()` is a statement
about identity — "the account that logs in as this e-mail is this catalog row" —
so its inverse has to be a statement too: `unlink(Person)` disassociates and
stops, leaving the account's role, password and access link exactly as they were,
back on the roster as an orphan. For a while the only apparent opposite of
"Vincular uma conta que já existe" was "Remover acesso", which soft-deletes the
account, so undoing a mistaken link switched off the account it named: linking
`admin@leomadeiras.com.br` to a person and pressing the button that read as the
reverse locked the seeded admin out of the app, with the default password no
longer accepted (the user provider's default scope hides a trashed row).
Reported from the app 2026-09-01.

Two things that go with it:

- **Both confirms name the ACCOUNT, not just the person.** Which e-mail is about
  to stop working was exactly what the screen did not say, and it is the one fact
  that separates the two gestures at the moment of pressing either.
- **`people.access.unlink` is `/access/{user}/unlink`, not a DELETE on
  `/access/{user}/link`.** That pair is already the magic LINK's
  (`refreshLink`/`destroyLink`) — the two senses of "link" collide in this
  feature, and a route name is the wrong place to be clever about it. It answers
  to `UserPolicy::manage` like every other verb here, and goes through
  `accountOf()`, so a person/account pair that is not actually linked 404s.


### Three roles, and two predicates instead of thirteen comparisons

`App\Enums\UserRole`: `Viewer` (Visualizador) reads, `Writer` (Editor) writes
CONTENT, `Admin` (Administrador) does everything. Every policy decides through
one of three predicates — `canWrite()` (admin or editor), `canDelete()` (admin)
and `isAdmin()` (admin) — and never by comparing the case. That is the whole
point of them: the same rule used to be written `$user->role === UserRole::Admin`
in thirteen files, so adding a tier meant editing all thirteen and hoping.

Where the line falls, and why:

- **An editor creates and edits**: solutions, people, companies, cadernos and
  their pages, diagrams, the flowSpec corpus.
- **DELETING is the admin's**, everywhere except a page (a page delete is part
  of writing a tree, and goes through `update` on its caderno). A caderno delete
  takes its whole page tree; a diagram delete leaves prose citing it. Move
  `canDelete()` if that call should change — it is one seam.
- **What is not content stays with the admin**: inviting accounts
  (`UserPolicy::manage`), the attribute vocabulary (`AttributeOptionPolicy`,
  deliberately NARROWER than `SolutionPolicy` — a category invented while
  filling one form is a category every other form then offers), publishing a
  caderno and its secret code (`NotebookPolicy::administer`).
- **`SubmissionPolicy` is untouched** and stays owner-based (admin OR the person
  who created it): a CATI submission is authored, not curated.

**A role is changed on the Usuários panel** (`PATCH users/{user}`, the badge is
an `x-ui.inline-edit` select). Before that it could only be chosen at INVITE
time, which left promoting a viewer to editor — and taking the admin off
somebody who left — as an `UPDATE` against the production database: the one
administrative act in the app with no screen at all.

One refusal guards it, and the important part is the guard that is NOT there:

- **Nobody changes their own role.** A dropdown that can take away the panel you
  are standing in is a foot-gun with no upside; another admin can always do it.
- **There is deliberately no "last admin" guard**, because it would be dead
  code. Removing an admin's role requires an admin asking (the policy) about
  somebody else (the rule above), which means two admins exist — so one always
  survives. Nothing else in the app writes `role`, and an invite only ever ADDS
  an admin. The reachable half of "who protects the last admin" is that the last
  one cannot be demoted because the only account allowed to try is their own.

`UserList` withholds the select on your own row for the same reason, so the
refusal is a missing affordance rather than an error you discover by pressing it
— the request stays the authority. What is still database-only is DELETING an
account: that means sessions, plus the submissions and chats it owns.

Two things that broke when the role landed and would break again:

- **`InviteUserRequest` listed its roles by hand** (`Rule::in([Viewer, Admin])`,
  a leftover from the removed `agent` case), so a new case is refused by
  validation while every policy already honours it. It is `Rule::enum` now.
- **Anything gated on `update` that is really about ADMINISTERING** silently
  widened the day `update` stopped meaning "admin" — the share dropdown was
  exactly that, and it now carries the secret code as well.


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

- Create service-specific macro clients for external APIs. There is exactly
  **one** external HTTP service in this app — GitBook — and `Http::gitbook()`
  (registered in `AppServiceProvider::boot()`) is the only macro, so it is also
  the only precedent to copy:

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
  documentation and CATI chats) goes through the `laravel/ai` package and
  `config/ai.php`, never through `Http::`.

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

### Two directives written adjacently — `@endif@endforeach` — do not compile

Blade matches a directive with `\B@`, so an `@` preceded by a **word
character** is not seen as one. Write the closing directives of a nested
loop-plus-conditional back to back — which is exactly what you must do when the
output may contain no whitespace between the pieces (highlighted search
segments, inline badges) — and the second one is never compiled: it lands in
the rendered PHP verbatim, the loop is never closed, and the view dies with
`syntax error, unexpected end of file` naming a file with nothing visibly wrong
in it.

```blade
{{-- ❌ `@endforeach` reaches the output as literal text --}}
@foreach ($segments as $s)@if ($s['match'])<mark>{{ $s['text'] }}</mark>@else{{ $s['text'] }}@endif@endforeach
```

There is no separator that fixes it: anything that renders (a space, a newline)
puts real whitespace inside the sentence, and a Blade **comment** between them
does not work either — comments are stripped BEFORE statements are compiled, so
the two directives are adjacent again by the time it matters. (Note the
ordering, because it is the opposite of the component-tag rule below:
`ComponentTagCompiler` runs BEFORE comments are stripped, which is why a
`<x-...>` tag inside a comment DOES compile. Statements run after. Neither
pass knows what a comment is, they just disagree about when.)

**Build the string in PHP instead** — a View Component with `e()` per piece,
echoed through a one-line view. `App\View\Components\Documentation\SearchHighlight`
is the reference implementation (and `x-ui.highlight` is the older, single-term
version of the same idea). Hit on 2026-08-26 building the documentation search
palette.

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
  `.ak-viz-md` (inline `<style>` in `components/chain/viz.blade.php`, a node's
  comment preview in the F3 canvas), `.html-content` (`app.css`, a whole
  GitBook documentation PAGE — `documentation/partials/_reader.blade.php`
  renders it and `docs-toc.js` reads it; an older note here claimed it was
  dead, it isn't), and `.ak-rich-text`
  (`resources/css/components/rich-text.css`, the short free-text fields — see
  "Two Markdown renderers" below). Keep them separate: `.html-content` types a
  document, `.ak-rich-text` deliberately sets no size and no color so the text
  keeps reading as part of the card it sits in.
- The **code-block theme** — the `--mk-*` palette scoped to `.html-content` in
  `app.css`, plus the `.hljs-*` token rules under it. A syntax theme is a
  CLOSED palette (a family of hues that has to stay distinguishable from
  itself), not a composition of brand tones, and the colored spans are emitted
  by highlight.js, so there is no element to hang a utility on. See "Code
  blocks" below.
- Global browser-chrome overrides that have no per-element target, e.g. the
  scrollbar styling in `resources/css/components/scrollbar.css`.
- `resources/views/components/ecosystem-map.blade.php` (the DOM+SVG ecosystem
  map, radial hub-and-spoke layout) and
  `resources/views/components/chain/viz.blade.php` (the F3 diagram canvas,
  shared by a `Diagram` and a submission's drawings) share the same scoped
  `--viz-*` token set and `.ak-viz-node`/`.ak-viz-node-avatar` classes, so both
  render nodes identically — a legitimate exception since the content (JS-built
  graph nodes/edges) never passes through Blade.

Before adding a new custom class or `<style>` block, check whether the same
result is reachable with `@class([...])`, arbitrary-value utilities (`w-[172px]`),
or an inline `style="height: {{ $dynamic }}"` for genuinely runtime-computed values.
If a past reviewer left dead custom CSS behind (unused decorative classes,
leftover from a copied reference bundle), delete it rather than leaving it —
`grep` the class name across `resources/views` first to confirm it's unused.

### Two Markdown renderers, on purpose

| | `App\Support\GitbookRenderer` | `App\Support\MarkdownText` |
|---|---|---|
| for | documentation pages, authored in the Editor.js block editor | the short free-text fields: a person's/company's `notes`, a solution's `description` and `support_operation_note` |
| speaks | Markdown **+ GitBook notation** (`{% hint %}`, `{% tabs %}`, `{% file %}`) plus one of our own, `{% diagram slug="…" %}` | plain Markdown (GFM), nothing else |
| raw HTML in the source | `html_input=allow` — it's how images arrive as `<figure><img src="/files/{id}">` | `html_input=strip`, plus `allow_unsafe_links=false`: a `<script>` in someone's notes must never run for the next reader |
| single newline | a normal Markdown soft break | rendered as `<br>` (`renderer.soft_break`), because these fields were plain textareas read back with `whitespace-pre-line` and every note already in the database relies on it |
| read side | `.html-content` + `GitbookRenderer` | `x-ui.markdown` + `.ak-rich-text` |
| write side | Editor.js (`docs-editor.js`) | still a plain `<textarea>` — `x-ui.inline-edit type="textarea"`, which prints its own "Aceita Markdown" hint; the panel forms say the same via `x-forms.field`'s `hint` |

Don't merge the two without deciding which of those `html_input` contracts
wins — and don't reach for the Editor.js editor for a small field: it's a
page-level singleton (one `[data-ak-docs-save]`, module-level `dirty`/`locked`
state) with its own save endpoint and a media collection behind
`App\Contracts\Documentable`.

### The reading screen's three regions

`documentation/edit.blade.php` is a flex row of up to four columns: the pages
rail, the reader, the assistant when it is docked, and a right rail. What goes
where follows one rule — **the top bar is for acting on the page, the right
rail is for facts about it.**

- The top bar's right end is the two live indicators, then **Salvar**, then
  every icon button after it. Salvar is the only thing there anyone presses on
  purpose; when a labelled pill ("Abrir especialista") sat between the icons and
  Salvar, the primary action was third in a row of six. "Abrir especialista" is
  an icon now — the panel it opens announces itself in full the moment it does.
- The right rail opens with **"Esse caderno contempla o(s) sistema(s): …"**
  (`x-notebooks.documented-systems`) above the page's own headings. That used
  to be a squares icon in the top bar, which stated nothing: you had to open a
  dropdown to learn something the screen could simply say. Clicking the sentence
  opens the same popover, which moved there whole.
**One stacking order governs the whole screen, and every number in it is
load-bearing**: `.codex-editor` (1, Editor.js's own) < right rail (`@3xl:z-10`)
< top bar (`z-30`). `position: sticky` ALWAYS opens a stacking context, so a
popover's `z-20` only ranks it against its siblings *inside* the rail — the
RAIL is what has to be ranked. Both halves were learned the hard way: at
`z-index: auto` the rail sat under the editor, which swallowed every click
meant for "Salvar vínculos"; at `z-20` it tied with the top bar's share
popover, and a tie in the same stacking context is broken by DOM order, so the
rail (later) painted its text straight through it. Put chrome above content by
ranking the REGION, not the popover: anything the top bar opens is above the
reader and the rail without an argument of its own.

A table is the one block that can break out of that layout: its MIN-content
width can exceed the width it was given, so `width: 100%` does not bind it and
a wide one painted straight over the rail. `GitbookRenderer` wraps every table
in `<div class="ak-table-scroll" tabindex="0">` (a `HtmlDecorator` over
commonmark's own `TableRenderer`, registered at a higher priority — decorate,
don't reimplement), and the frame moves to that div: a rounded border painted on
the table itself scrolls away with it. A table pasted as raw HTML never reaches
the Table node and so is never wrapped, which is why `.html-content table` still
carries the whole frame on its own.

### A diagram citation is PREVIEWED in the editor, not described

The `diagram` block stores a slug and nothing else — the name and the picture
are resolved at render time, so a renamed or redrawn diagram updates every page
citing it without anybody editing prose. That is also why the editor has to
resolve them too: it used to print the raw slug, so an author had no way to tell
whether they had cited the right drawing, and a page reopened later read as
`zfl-bloq-desbloq-cliente`.

`docs-tools/diagram.js` now draws the same card `GitbookRenderer::renderDiagram()`
will, with the same three states (picture / "sem imagem ainda" / "removido"),
from the catalog it already fetches — `diagrams.catalog` carries `pictureUrl`
(null when the canvas has never been saved with one) and `url` per entry, both
built with `route()` server-side, which is what keeps the tool free of a path
of its own. The media is eager-loaded there: `picture()` is a `getFirstMedia()`
per row on a payload meant to be one query.

**A tool that paints itself asynchronously must be `data-mutation-free`.**
Editor.js decides a block changed by watching its DOM, so a preview that
arrives when a fetch resolves reads as an edit: it marks the page dirty and
autosave writes it, seconds after someone merely opened it. The wrapper carries
`data-mutation-free="true"` (Editor.js drops any mutation whose nodes sit inside
one) and the tool dispatches the one real change itself, via
`options.block.dispatchChange()` when a diagram is picked — the same shape as
`docs-tools/code.js`'s language field. Get one half without the other and you
either autosave on load or lose the citation the author just made.

One more distinction the block has to keep: an EMPTY catalog and an
UNREACHABLE one look identical from the lookup table, and they must not read
the same. "Diagrama removido" is a claim about the author's citation; a failed
fetch is a fact about the request, and reporting the first for the second tells
someone their drawing was deleted because a request timed out.

### Code blocks — the fence's LANGUAGE is data, and it is easy to lose

A documentation code block is light (`--mk-*`, a Monokai-light palette) and
syntax-highlighted, and the whole chain hangs off one token: the `xml` in
```` ```xml ````. `GitbookRenderer` hands it to commonmark, which emits
`<code class="language-xml">`; `docs-code.js` wraps each `<pre>` in a panel and
hands it to `docs-highlight.js`, which picks the grammar and returns the name
the panel header shows.

Three things about that chain:

- **highlight.js is loaded on demand and only where there is code.**
  `docs-code.js` reaches it through a dynamic `import()`, so it is a chunk of
  its own and a page with no `<pre>` never downloads it. Highlighting is
  decoration: if that import fails, the panel, its label and "Copiar" are
  already on screen and stay working.
- **An UNLABELED fence is auto-detected, from three grammars and above a
  measured threshold** (`AUTO_SUBSET` / `AUTO_MIN_RELEVANCE`). Both numbers came
  from running every unlabeled block in the dev corpus through
  `highlightAuto` — `bash` and `php` claimed a bare URL at relevance 22 and 14,
  which is why the subset is small. Widen either without measuring and plain
  output starts arriving in four colors.
- **The editor used to DESTROY the token on every save.** `docs-markdown.js`
  matched the fence's language and dropped it, and always serialized a bare
  ```` ``` ````, so opening a page and pressing nothing but Salvar rewrote
  every ```` ```xml ```` as ```` ``` ````. It is carried now (`normalizeLanguage()`,
  one helper both ends share) and `@editorjs/code` is subclassed
  (`docs-tools/code.js`) to hold it in the block's data and offer a field for
  it — upstream's data is `{code}` and nothing else, so a plain extra key would
  not survive its `save()`. Any new tool whose Markdown form carries an
  attribute needs the same treatment: parse it, hold it in block data, write it
  back.

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

Only 6 models use `HasMedia`/`InteractsWithMedia`, each with its own single collection and its own purpose — there is no generic shared collection/conversion pair to reuse:

- `User` — `avatar` (single-file), with one registered conversion, `thumb` (120×120, `nonQueued()` since the source is tiny). `User::avatarUrl()` falls back to `ui-avatars.com` (an external, third-party image, requested client-side from the `<img src>`) when no avatar was uploaded — a deliberate, low-risk default, not an oversight.
- `Notebook` — `context_documents` (`Notebook::CONTEXT_COLLECTION`), the "Assiste IA" context documents (PDF/image/text), served by `NotebookContextDocumentController` — never through `MediaController`/`files.show`. It lived on `Solution` until cadernos became the container: the chat is about a page, a page always has a notebook, and may have no solution at all.
- `DocumentationPage` — the `docs` collection (`Documentable::DOCS_COLLECTION = 'docs'`): images/files embedded in Markdown documentation, referenced as `/files/{id}` and served by `MediaController`/`files.show` (authenticated) or `PublicDocumentationController::file()` (magic-link, token-scoped, checked against the notebook's own pages). It is the only `Documentable`; `Diagram` and `SubmissionDiagram` also register a collection named `docs`, but for a different reason — an image PASTED onto the canvas has to be servable at `/files/{id}`, and `MediaController::show()` authorizes by collection name alone, so nothing outside that name can be served at all.
- `Diagram` — `docs` (pasted image nodes, above) plus `diagram` (`Diagram::DIAGRAM_COLLECTION`, `singleFile()`): the canvas rendered to a PNG by the client on every layout save, so the CATI deck can show an architecture without a browser in the loop. Derived, never an input.
- `Submission` — `submission_sources` (`Submission::SOURCES_COLLECTION`), the gathered material behind a CATI submission, served by `SubmissionSourceController::show()`.
- `FlowspecChat` — `flowspec_attachments` (`FlowspecChat::ATTACHMENTS_COLLECTION`), files a person attached as context to an Especialista em Integrações conversation. Never served back to a browser at all: these are read for text or handed to the model as native attachments, and are deleted with the attachment row.

No model has more than one conversion, and nothing uses a `->image()` accessor. **`Solution` holds no media at all any more, and `Solution`/`Company` logos are NOT MediaLibrary** — `logo_path` is a plain string column, uploaded via `$request->file('logo')->store('{solution,company}-logos', 'public')` directly in `SolutionController`/`CompanyController`, a deliberately simpler mechanism since a logo needs no conversions/metadata.

Avatar/logo uploads (the six Person/Solution/Company Store+Update requests) all share `['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']`, and `avatar-upload.js` mirrors that list client-side so a doomed file never gets an encouraging preview — **keep the two in step**. `accept="image/*"` on the input is only a picker hint and enforces nothing. SVG is intentionally absent: Laravel 13's bare `image` rule rejects it unless written `image:allow_svg` (so it never actually worked, even while `mimes:` still listed it), and an SVG served from the public disk executes its own scripts when opened directly by URL. Documentation media is a different rule (`file`, not `image`) and **does** accept SVG.

The last two are read by the shared `App\Support\Context` extractors
(`SourceTextExtractor` + `SensitiveTextScanner`), which partition an upload into
text to inline vs. a PDF/image the model reads natively — the one piece of this
that two feature areas genuinely share. `App\Support\Context\NativeAttachmentType`
is the single place that decides which of the two a given file is.

Never register a new collection/conversion without checking the 6 above first — and note `MediaController::show()`'s guard should compare against `Documentable::DOCS_COLLECTION`, not a hardcoded `'docs'` literal (the two happen to match; don't let them drift apart silently).

### SSRF surface — documentation editor's "paste image URL"

`EditsDocumentation::storeDocumentationMedia()` (used by `NotebookPageController`) has two upload paths: a multipart `file`, or a `url` the SERVER downloads via Spatie's `addMediaFromUrl()` (Editor.js's Image plugin "paste a URL" flow). `UploadDocumentationMediaRequest` only validates `starts_with:http://,https://` — same as Spatie's own internal check — with **no private/loopback/link-local guard**, so without `App\Rules\PublicUrl` (validates the resolved IP via `FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE`) an admin could make the server fetch an internal-only URL (cloud metadata endpoint, internal admin panel, etc.) — exploitability is WRITE-scoped (`update` on a page's caderno reaches this), which since the Writer role landed means an **editor** and not only an admin: the same gate widened, and the guard is what did not move with it. Still real, and now reachable by more accounts than the note originally claimed. The guard resolves DNS at validation time, so it does **not** close a DNS-rebinding race (attacker's DNS answers public at validation, private moments later at fetch time) — accepted as a documented residual risk, not something this rule claims to solve.

## Style

- Use Laravel helpers over facades where shorter: `auth()`, `request()`, `now()`, `rescue()`
- Follow Laravel naming conventions: `StoreProposalRequest`, `ProposalPolicy`, `AnalysisSeeder`
- Prefer readable, expressive syntax over clever one-liners

---

## JavaScript — use modules before creating new ones

Before writing any new JS behavior, check if an existing module in `resources/js/modules/` already handles it. The project has modules for: toggle, tabs, side panel, AJAX form submission, filters, search, chips (multi-select with autocomplete), and more. (Modules inherited from akop-pro with zero consumers — mask, standalone autocomplete, copy-content, url-location, event-helpers, string-helpers, search-in-container, switch-button, radio-group — were removed on 2026-07-16; they weren't even part of `app.js`'s bundle. `file-upload.js` was removed on 2026-07-27 for the same reason, just discovered later — it WAS registered in `app.js`'s `globalModules`, but no Blade view ever rendered its `data-ak-file-upload` hook; actual image/logo upload UI goes through `avatar-upload.js`/`<x-forms.image-upload>` instead. `solution-attributes.js` went on 2026-08-15, for a different reason: its one consumer — the Solution header's 8 attribute badges — moved to `inline-edit.js`, which left it with no `data-ak-solution-attribute` hook anywhere in the app.)

The canvas modules were RENAMED, not removed, on 2026-08-26: `integration-viz.js` → `chain-viz.js` and `integration-select.js` → `chain-select.js`, with their hooks (`data-integration-viz` → `data-ak-chain-viz`, `data-ak-integration-select` → `data-ak-chain-select`, `data-integration-graph` → `data-ak-chain-graph`) and their event (`ak:integration-selected` → `ak:diagram-selected`). They never were integration-specific — the canvas draws any `ChainCanvas` — and `data-integration-viz`/`data-integration-graph` were also two of the last hooks violating the `data-ak-*` convention.

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
`['solutions.index', 'solutions.show']`, so it stays lit on a solution's detail
page too). A page that got its own top-level section — `/diagrams` did — should
LEAVE the borrowed patterns behind: "Soluções" listing `diagrams.*` would light
two rail items at once.

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

{{-- Docked: an in-flow right COLUMN of the named host instead of a floating
     overlay. The host must be a flex row; the panel is moved into it as the
     last child and the page's own content shrinks beside it. --}}
<button data-ak-panel-open data-ak-panel-url="{{ route('my.route.panel') }}"
        data-ak-panel-dock="my-flex-row-id" data-ak-panel-size="large">
    Abrir na coluna
</button>

{{-- Close from inside the injected content --}}
<button data-ak-panel-close>Fechar</button>
```

The overlay (when visible) also closes the panel when clicked.

### Docked mode — same shell, in flow

`data-ak-panel-dock` is what the documentation reader's "Abrir especialista"
uses: talking to the assistant ABOUT a page while the page disappears behind
the panel was the problem it solves. `side-panel.js` moves `#side-panel` into
the host element, swaps the floating class list for an in-flow one, and adds a
drag handle on its left edge (also keyboard-resizable — it's a
`role="separator"`); the width is remembered in `localStorage`. Closing
collapses the column and restores the floating shell exactly as
`layout.blade.php` authored it, so nothing else has to know which mode was
used.

Three things follow from it being one shell rather than two:

- **`data-ak-panel-size` still matters on a docked trigger.** Below 1024px the
  dock is refused and the panel opens floating, which is the behavior that
  screen already had — a column with a 320px floor and a phone don't mix.
- **The host must be a flex row that can give up width** — the panel arrives as
  a `shrink-0` sibling, so something beside it needs `flex-1 min-w-0`.
- **The injected content wrapper is `flex-1 min-h-0`, not `h-full`.** A docked
  shell's height comes from stretching inside the host row, so there is no
  definite height for a percentage to resolve against.

A page whose layout reacts to the panel should measure the CONTAINER, not the
viewport: `documentation/edit.blade.php` marks its scroll area `@container` and
drops the "Nesta página" navigator at `@3xl`, because the viewport stopped
predicting how much room the reading column has the moment a panel could sit
beside it.

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
