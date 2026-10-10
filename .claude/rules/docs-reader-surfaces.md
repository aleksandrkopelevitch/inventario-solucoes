---
paths:
  - "app/Services/Documentation/DocumentationReader.php"
  - "app/Support/Documentation/ReaderUrls.php"
  - "app/Http/Controllers/PublicDocumentationController.php"
  - "app/Http/Controllers/KnowledgeBaseController.php"
  - "app/Http/Controllers/NotebookPublicationController.php"
  - "app/View/Components/Notebooks/Index.php"
  - "app/Policies/NotebookPolicy.php"
  - "resources/views/docs/**"
  - "resources/views/public/**"
---

### Two read-only surfaces over the same pages, and only the URLs differ

A caderno is READ in three places and the first one is not like the other two:
the page EDITOR (`notebooks/{notebook}/{page}`), the magic link
(`public-docs/{token}/…`) and the internal knowledge base (`docs/{notebook}/…`).
The last two are the same screen — same shell, same rail, same ⌘K palette, same
locks, same child-page cards — and they are the same screen in the CODE, not
merely in appearance:

- `App\Services\Documentation\DocumentationReader` builds the whole payload.
- `App\Support\Documentation\ReaderUrls` is the only thing either controller
  hands it that differs: where a page, a media file, a diagram picture, the
  search endpoint and a lock's reveal endpoint live for THIS audience.
- `x-documentation.reader-body` is the body both views render.

That is deliberate and it is the requirement: the second copy is how "o mesmo
layout" stops being true, on the day somebody fixes a bug on one of them. What
the two surfaces genuinely do not share is one affordance — the caderno
SWITCHER in the top bar — and the layout takes it as an optional prop
(`:notebooks`), null on the magic link because a token grants exactly one
caderno and there is nothing to switch to.

**SEMI-public is the whole design of `/docs`.** The magic link carries no
identity at all, which is what makes it right for a vendor and wrong for
"everybody here"; `/docs` requires an account and accepts ANY account,
including one Entra SSO provisioned on its first visit (see `.claude/rules/roles-and-policies.md`). Publication is
`notebooks.published_at` — a timestamp rather than a boolean, so the admin
screen can answer "what is published, and since when", and `scopePublished()`
is the one reading of it.

Five rules that are easy to half-implement:

- **Every endpoint re-asks whether the caderno is published**, including the
  three reached by ID rather than by browsing — `file`, `diagramPicture`,
  `search`. Those are the ones where the question is not "what should I list"
  but "may this be served at all".
- **It is NOT a policy.** `NotebookPolicy` answers about the caderno as an
  object of EDITING and says `true` to every account, including for the two
  hundred cadernos nobody published. `RevealPageSecretRequest` on `/docs`
  authorizes by publication for the same reason.
- **An unpublished caderno 404s for an ADMIN too.** `/docs` exists so that "what
  has been published" is answerable by looking at it, and a surface that shows
  more to whoever decides what is on it cannot answer that. Previewing before
  publishing is what the editor is for.
- **Media and diagram pictures need routes of their own**, and this is the
  reason `/docs` differs from the magic link rather than reusing `files.show`:
  `MediaController::show()` authorizes by COLLECTION NAME alone, so it would
  serve any documentation media in the app, published or not, and `/docs`
  serves what was published and nothing else.
- **`linkDiagrams: false` on `/docs` as well.** The link COULD be rendered
  conditionally — a reader who may reach `/diagrams/{slug}` exists — and
  deliberately is not: the knowledge base would then be a screen whose content
  changes with the viewer's tier, which is the property the search index gave up
  `linkDiagrams` to avoid. The picture and the name are the documentation; the
  link is an editing affordance.

**Publishing is `NotebookPolicy::administer` (admin), and it is flipped from two
screens.** The cadernos catalog (`/notebooks`) carries an "Em /docs" column —
a switch for an admin (`administerAny`, asked once per render), the state for
everybody else — and its status filter answers "what is published today"; the
switch on each caderno's own share panel is the other caller, because that is
where an admin already is when the question occurs to them. There is no
`/docs/settings` screen any more: it listed the same cadernos a second time,
with the same search, to flip this one column, and was folded into the catalog
(2026-10-10). One column, one endpoint
(`notebooks.publication`), and the response carries BOTH slots — forgetting one
leaves the other screen showing a switch in the wrong position. `published_at`
is deliberately outside `$fillable`, like `parent_id` on a page: the rename
panel is `update` (editor) while publishing is `administer` (admin), and a
fillable column is one posted field away from collapsing the two.

The share panel states both audiences side by side on purpose. They are
constantly mistaken for each other, and an admin reaching for "compartilhar"
almost always means the internal one.
