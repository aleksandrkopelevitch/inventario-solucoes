---
paths:
  - "app/Models/Notebook.php"
  - "app/Models/DocumentationPage.php"
  - "app/Services/DocumentationPageService.php"
  - "app/Support/Documentation/PageLinks.php"
  - "app/Support/Documentation/DiagramCitation.php"
  - "app/Support/GitbookRenderer.php"
  - "app/Http/Controllers/NotebookController.php"
  - "app/Http/Controllers/NotebookPageController.php"
  - "app/Http/Controllers/Concerns/EditsDocumentation.php"
  - "app/Http/Controllers/Concerns/BuildsPagesNav.php"
  - "app/Http/Requests/*DocumentationPage*.php"
  - "app/Policies/DocumentationPagePolicy.php"
  - "resources/views/notebooks/**"
  - "resources/views/documentation/**"
  - "resources/views/components/documentation/**"
---

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
percentage. The second one is gone; what it was really for (text beside a
drawing) is a page CITING one with `{% diagram %}`, above.

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
  and strict mode then turns a lazy load into a 500 (see `.claude/rules/eloquent-strict-and-search.md`). The FK's
  `cascadeOnDelete` is the safety net; the model hook is what lets Spatie clean
  each page's embedded media.
- **A caderno is deleted from the CATALOG card, by an admin.** `notebooks.destroy`
  had no caller at all, so the only way to remove one was the database. The trash
  beside the pencil is that caller, and the two answer to different rules on the
  same card: `update` (editor) opens the rename panel, `delete` (admin) removes
  the caderno. It answers with the catalog SLOT, not a `redirect` — from the
  catalog, a redirect to the catalog is a full reload that throws away the
  filters the URL still shows. The confirm states the two consequences that are
  COUNTED rather than guessed: how many pages go with it, and whether a public
  link somebody already holds stops working.

The rail (`x-documentation.pages-nav`) renders that walk as ONE flat `@foreach`
with an indent class per depth — deliberately not a recursive partial, so every
row keeps a unique `$loop->index` for its hidden forms — and the indent steps
are literal classes, because Tailwind only ships what it can see in the source
(`ml-{{ $n }}` compiles to nothing).
