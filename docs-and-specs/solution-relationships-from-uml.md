# Solution relationships from the documentation — and the end of the drawing canvas

Status: **all five phases done** · revision 2 · 2026-11-27 · history in §13, implementation notes in §14

The graphical canvas is removed, both of its owners:

- **`/diagrams`**: the `Diagram` model, `{% diagram slug %}` citations and
  "Desenhar esta página".
- **The CATI AS IS / TO BE canvas**: `SubmissionDiagram`'s chain.

The UML diagrams written into pages (`{% uml %}`, shipped 2026-11-24,
`.claude/rules/doc-uml.md`) take its place as the way architecture is drawn.

`/diagrams` also did a second job, and that one must not be lost: **it was the
only source of solution↔solution correlation**. It fed the solution page's
"Diagramas" box, the `/map` arrows, `get_solution.diagrams` on the MCP and a
few CATI checks. From now on a relationship comes from two places:

1. **Documented**: derived from the UML diagrams in caderno pages whose
   participants or elements are bound to a catalog solution. When a call passes
   through an integration platform (Digibee, SAP CPI), the end-to-end pair is
   inferred too: "SAP S/4 → Freshdesk, via SAP CPI".
2. **Manual**: an interconnection an Editor adds or corrects inline on the
   solution page, or through the MCP.

On the solution page (`/solutions/{slug}`, e.g. `accessone-iam`), the
"Diagramas" column becomes **"Relacionamentos"**:

```
[logo] AccessOne (IAM)  ⟷  [logo] Active Directory / Entra ID                  ✎
[logo] AccessOne (IAM)  ──▶ [logo] SAP S/4                    manual            ✎ ✕
[logo] AccessOne (IAM)  ◀── [logo] Digibee (iPaaS)                              ✎
[logo] AccessOne (IAM)  ──▶ [logo] Freshdesk   via [logo] Digibee (iPaaS)       ✎
+ Adicionar interconexão
```

---

## 1. Where we start

| piece | today |
|---|---|
| `Diagram` | a topology drawing owned by a caderno (`notebook_id`). It has a `chain` JSON (`nodes[{solution_id,label,kind}]`, `edges[{from,to,arrow,protocol}]`), a `diagram_solution` pivot and a PNG snapshot. **11 rows in production, all "Planejada"** (2026-11-26) |
| correlation | `DiagramGraphService::build()` turns each chain edge whose two ends are solutions into a map edge, and `dedupePairs()` folds those into one arrow per pair, `bidirectional` when both directions appear. It drops `'<-'` on the floor, so that arrow is drawn backwards (§4.5) |
| solution page | `x-solutions.diagrams` (slot `solution-diagram-titles-slot`), the left half of the documentation card beside `x-solutions.notebooks`, gated by `viewAny` on `Notebook` |
| UML in pages | `{% uml type title %}` + JSON. `UmlDiagram::parse()` is the one validator for the reader, the MCP writes and the editor's `notebooks.pages.uml` check. Participants and elements carry an `id` and a free `label`/`name`, and **nothing ties one to a catalog solution** |
| CATI | `SubmissionDiagram` has four kinds. C1/C2 are picture uploads; AS IS / TO BE are drawn on the same canvas as `Diagram` (`App\Contracts\ChainCanvas`, `EditsChain`, `chain-viz.js`). `ApprovedTopology` copies an approved TO BE into a catalog `Diagram` |
| platforms | the catalog already has the category `ipaas` ("iPaaS"). Today it holds exactly `digibee-ipaas` (Digibee) and `sap-cpi-integracao` (SAP CPI) |
| real UML today | the EndoFlow documentation (written through the MCP on 2026-11-25) names internal components as participants, not catalog systems. A name is therefore not a binding |

## 2. Decisions

1. **A documented binding is explicit: `"solution": "<slug>"`** on a sequence
   participant, a grid element or a grid group (§3). A label that happens to
   equal a solution's name binds nothing. EndoFlow's "API" or "Worker" must
   never become a relationship because something in the catalog shares the
   name.
2. **Documented relationships are derived; manual ones are records; a manual
   record WINS for its pair.**
   - Editing any row on the solution page writes the manual record for that
     pair. That includes a documented or inferred row, which the manual record
     then overrides.
   - Removing a manual record that overrode something brings the documented
     answer back.
   - A purely documented relationship cannot be deleted from the solution page.
     It is removed by editing the diagram that states it, and the row says
     where that is.
3. **Direction is the diagram's direction** (§4). A call drawn from A to B says
   A calls B. Calls drawn both ways make the pair `bidirectional`, and a plain
   line (`association`) gives an **undirected** relationship ("sem sentido
   definido").
4. **A platform in the middle produces a "via" relationship as well as the
   direct ones** (§4.4). A solution is a platform when its category is `ipaas`.
   Inference happens only INSIDE one diagram, never across pages. Digibee
   connects dozens of systems, and joining two unrelated pages through it would
   invent integrations.
5. **Caderno↔solution links are NOT relationships.** A caderno about Digibee
   documents a dozen systems, and "documented together" is not "talk to each
   other".
6. **Documented links are a stored index, rebuilt per page on save** (§5).
   Platform inference runs at READ time over that index, so re-categorising a
   solution as iPaaS takes effect at once, without re-syncing any page.
7. **The whole canvas goes, CATI included.**
   - AS IS / TO BE become picture uploads like C1/C2.
   - An existing drawing keeps its last PNG as that upload (§8.2).
   - `ApprovedTopology` goes with it.
   - The CATI rules themselves are being redefined separately, so this spec
     changes them only as far as removing the canvas forces.
8. **The 11 existing drawings are converted into `{% uml %}` blocks, bound to
   their solutions, in the documentation they belong to** (§9).
9. **The MCP manages relationships too.** `get_solution` reads them, and two
   write tools create, correct and remove manual ones. Those calls are logged
   and revertible like every other MCP write.

## 3. The binding — `solution` in the UML JSON

### 3.1 Syntax

```json
{% uml type="sequence" title="Login no portal" %}
{
  "participants": [
    {"id": "user", "label": "Cliente", "kind": "actor"},
    {"id": "vtex", "label": "VTEX", "kind": "boundary", "solution": "leo-e-commerce-vtex"},
    {"id": "iam",  "label": "AccessOne", "solution": "accessone-iam"}
  ],
  "messages": [
    {"from": "user", "to": "vtex", "label": "login"},
    {"from": "vtex", "to": "iam", "label": "POST /token"},
    {"from": "iam", "to": "vtex", "label": "JWT", "kind": "return"}
  ]
}
{% enduml %}
```

```json
{% uml type="component" title="Integração de clientes" %}
{
  "nodes": [
    {"id": "sap",  "kind": "component", "name": "SAP S/4", "solution": "sap-s4", "at": [0, 0]},
    {"id": "iflow", "kind": "component", "name": "iFlow clientes", "at": [1, 0]},
    {"id": "fd",   "kind": "component", "name": "Freshdesk", "solution": "freshdesk", "at": [2, 0]}
  ],
  "groups": [
    {"kind": "boundary", "label": "SAP CPI", "solution": "sap-cpi-integracao", "nodes": ["iflow"]}
  ],
  "edges": [
    {"from": "sap", "to": "iflow", "kind": "directed", "label": "IDoc"},
    {"from": "iflow", "to": "fd", "kind": "directed", "label": "REST"}
  ]
}
{% enduml %}
```

The second diagram yields SAP S/4 → SAP CPI, SAP CPI → Freshdesk, and,
inferred, SAP S/4 → Freshdesk via SAP CPI.

### 3.2 Where it is accepted

- **Every sequence participant**, whatever its `kind`.
- **Grid elements of a kind that can BE a system**: `component`, `node`,
  `database`, `artifact`, `actor`, `object`, `package`, `class`, `abstract` and
  `interface`. This list is one constant, `UmlDiagram::BINDABLE_KINDS`.
  - On any other kind (`note`, `text`, `divider`, `initial`, `final`, `fork`,
    `decision`, `action`, `state`, `usecase`, `port`, `nary`, `lollipop`) the
    key is a problem: "O elemento \"x\" é um(a) {kind}: só elementos que podem
    ser um sistema aceitam \"solution\"."
- **Grid groups** (`package`, `boundary`, `node`, `frame`).
  - A member with no binding of its own **inherits the innermost bound
    group's**. That is how an iFlow drawn inside an "SAP CPI" boundary counts
    as SAP CPI without each box being bound.
  - A member's own binding wins.

### 3.3 Two validators, on purpose

- **`UmlDiagram::parse()` stays free of the database.** It checks the SHAPE (a
  string, `AsciiSlug`, at most 255 characters) and the kind rule above, and
  carries `solution` into the normalized diagram. The reader and the editor
  preview still never wait on a query to draw.
- **`App\Support\Documentation\UmlSolutionBindings`** takes a whole page body.
  - It scans the `{% uml %}` blocks with the same line scan
    `GitbookRenderer::problems()` uses (`GitbookRenderer.php:1290`), extracted
    into one shared helper rather than copied.
  - It parses each block and resolves every slug in ONE query.
  - It returns the links (§4) and the slugs it could not resolve.

An unknown slug is handled differently at each door:

| door | unknown slug |
|---|---|
| reader (`/docs`, the app, the magic link) | drawn normally, with **no link derived and no error box**. A deleted solution must not break a page |
| the editor's check (`notebooks.pages.uml`) | listed with the other problems under the block: "A solução \"x\" não existe no catálogo." It also gives a HINT, not a problem, when an unbound participant's label folds equal to a solution's name: "\"VTEX\" parece ser a solução Leo e-Commerce VTEX — vincule com \"solution\": \"leo-e-commerce-vtex\"." |
| MCP `create_page` / `update_page` | refuses the write through `GitbookRenderer::problems()`, like every other UML problem. Only blocks that CHANGED are checked (`problems($new, $previous)`), so a model is never refused over a dangling binding in a block it did not touch |

### 3.4 A slug is the address, so a renamed slug is rewritten

A solution's slug CAN change (`SolutionRules` accepts `slug` on update). The
index survives a rename on its own, because its rows hold `solution_id`. The
TEXT does not: the next save would resolve the old slug to nothing and drop the
link.

So when `SaveSolution` changes `slug`, it calls
`RewriteSolutionBindings($old, $new)`:

- **Which pages:** those whose `documentation` contains `"<old>"`, found with an
  exact, case-sensitive `like`. A slug is lowercase ASCII, and this is a match,
  not a search, so `whereFolded()` does not apply.
- **What it rewrites:** inside `{% uml %}` blocks only, every
  `"solution"\s*:\s*"<old>"`, BY REGEX.
- **What it never does:** decode and re-encode the JSON. The author's JSON is
  stored verbatim (doc-uml.md), and a re-encode would reformat every diagram it
  touched.
- **How it saves:** through the model, so the index re-syncs (§5) and
  authorship records who renamed it.

## 4. From diagram to relationship

### 4.1 Sequence

A message counts when its two ends are bound to two DIFFERENT solutions. That
includes messages inside fragment operands (`alt`/`else`, `loop`, `par`…).

| `kind` | relationship |
|---|---|
| `sync`, `async`, `create`, `destroy` | `from` calls `to` |
| `return` | none. A return is the answer to a call, not a call; counting it would turn every request/response pair into "bidirectional" |
| a `gate` message (one end on the frame) | none: only one end is a participant |

### 4.2 Structural (grid) diagrams

An edge counts when its two elements resolve to two DIFFERENT solutions, each
through its own binding or one inherited from a group (§3.2).

| edge `kind` | relationship |
|---|---|
| `directed`, `dependency`, `flow` | `from` calls `to` |
| `association` | **undirected**: a line with no arrowhead claims a link but no direction |
| `inheritance`, `realization`, `composition`, `aggregation`, `include`, `extend`, `note`, `callout` | none. They state structure, not communication |

### 4.3 Folding into a pair

For a pair {A, B}, over every link from every page:

- calls both ways → **bidirectional** (`⟷`)
- calls one way only → **unidirectional**, oriented (`──▶` / `◀──`)
- only undirected links → **undirected** (`───`)
- an undirected link alongside directed ones adds nothing to the direction, but
  still counts as evidence (§6.2)

Each pair also carries its **evidence**: the distinct (page, diagram)
occurrences, and up to 5 distinct non-empty labels (message and edge labels,
such as `POST /token` or `IDoc`).

### 4.4 Through a platform — "via"

A **platform** is a solution with `category = 'ipaas'`
(`SolutionRelationshipService::PLATFORM_CATEGORY`). Today that is Digibee and
SAP CPI. Calling a new middleware a platform takes one change in the existing
category, and the inference follows at once (decision 6).

Inference reads only the DIRECTED links of ONE diagram (same page, same
`diagram_position`). X and Y must be non-platforms, and X ≠ Y. In both cases
the middle of the chain may be one platform or two (X → P1 → P2 → Y, as when
SAP CPI hands a message to Digibee), but no more.

- **Sequence:** a call X → P at position *i*, followed by a call P → Y at
  *j > i*, gives **X → Y via P**. This holds only if no other non-platform
  participant called P between *i* and *j*: P is still serving X's call. The
  `ordinal` column (§5) is what makes this rule possible.
- **Structural:** every X → P with every P → Y in the same diagram, **but only
  when that is unambiguous**. Among the non-platform elements, either P is
  called by exactly one of them (fan-out: X → P → {Y1, Y2}), or P calls exactly
  one of them (fan-in: {X1, X2} → P → Y).
  - A hub with several on both sides (A, B → Digibee → C, D) infers nothing,
    because a picture of a hub does not say whether A reaches C or D.
  - The author who wants those pairs states them: split the diagram per flow,
    or use a sequence diagram.

Via pairs fold like the others (§4.3): SAP → Freshdesk via CPI plus Freshdesk →
SAP via CPI make a bidirectional pair. A via pair is NEVER drawn on `/map`
(§7). There it would only repeat the two arrows that already meet at the
platform.

On the solution page a via row shows the platform beside the counterpart
("via [logo] SAP CPI"). The direct rows to the platform stay listed too, as the
person asked: SAP's page shows "SAP S/4 ⟷ SAP CPI" and "SAP S/4 ──▶ Freshdesk
via SAP CPI".

### 4.5 What is NOT carried over from `DiagramGraphService`

Its edge code is not ported, because `build()` never handled the `'<-'` arrow.
An edge drawn `to ← from` reached `/map` backwards. `SyncDiagramFromChain`,
`TopologyDiff` and `ChainLabeler` all handle that arrow, and
`DiagramGraphServiceTest` has no case for it. A UML edge's direction is always
`from → to`, so the new derivation has no reversed-arrow encoding to get wrong.
The pair folding (`dedupePairs()`) is the only part whose shape is reused.

## 5. Storage

### 5.1 Documented — `uml_solution_links`

One row per directed or undirected link occurrence. These rows are the
evidence, not the pair: pairs, and via pairs, are computed on read in one
grouped pass in PHP.

| column | |
|---|---|
| `id` | |
| `documentation_page_id` | `foreignId()->constrained()->cascadeOnDelete()` |
| `from_solution_id` | `foreignId()->constrained('solutions')->cascadeOnDelete()` |
| `to_solution_id` | `foreignId()->constrained('solutions')->cascadeOnDelete()` |
| `directed` | boolean. For an undirected row, `from_solution_id < to_solution_id`, so a pair has one spelling |
| `diagram_position` | unsigned small int: the block's order in the page, counted from 1. It addresses the figure (§6.2) and scopes via inference (§4.4) |
| `ordinal` | unsigned small int: the link's order inside its diagram. For a sequence, that is the messages walked depth-first, fragments included; for a grid, the edge order. Via inference needs it |
| `diagram_type` | string(20), a `UmlDiagram::TYPES` key |
| `diagram_title` | string(120), nullable |
| `label` | string(80), nullable |

- **Indexes:** explicit indexes on `from_solution_id`, `to_solution_id` and
  `documentation_page_id`, since Postgres never indexes an FK by itself
  (`bde0d12`).
- **Model:** `App\Models\UmlSolutionLink` (complete `$fillable`; relations
  `page()`, `from()`, `to()`, with return types).

**Sync** — `App\Actions\Documentation\SyncPageSolutionLinks::handle(DocumentationPage $page)`:

- **What it does:** deletes the page's rows and inserts what
  `UmlSolutionBindings` derives.
- **When it runs:** `DocumentationPage::booted()` calls it on `saved`, when
  `wasRecentlyCreated || wasChanged('documentation')`.
- **Why synchronously, in the save's transaction:** the cost is a line scan, a
  JSON parse per block and one `whereIn` on `solutions.slug`. A queued job would
  let the solution page contradict the page that was just saved.
- **Who passes through it:** every writer, with no exception — the editor
  (`SavePageContent`), the MCP, the GitBook import, `PromoteApprovedSubmission`,
  the rewrite of §3.4 and the conversion of §9.
- **What needs no hook:** deleting a page, a caderno or a solution cascades. A
  page moving between cadernos changes nothing, since the rows are keyed by
  page.

**A solution created after the page that names it.** The binding derived
nothing when the page was saved. The create branch of `SaveSolution` therefore
re-syncs every page whose body contains `"<slug>"`, matched with the same exact
`like` as §3.4. In practice that is zero pages, rarely one.

**Backfill.** `php artisan documentation:solution-links` rebuilds every page's
rows (`lazyById`).

- It runs once on the phase 1 deploy (`sudo -u www-data`).
- It is idempotent, so it is also the repair tool.
- It is not scheduled, because nothing goes stale between saves.

### 5.2 Manual — `solution_relationships`

| column | |
|---|---|
| `id` | |
| `solution_a_id` | `foreignId()->constrained('solutions')->cascadeOnDelete()`, always the LOWER id of the pair |
| `solution_b_id` | same, the higher id |
| `direction` | string: `a_to_b`, `b_to_a` or `both`. `App\Enums\ManualRelationshipDirection` |
| `created_by_id`, `updated_by_id` | `foreignId()->nullable()->constrained('users')->nullOnDelete()`, written by `booted()` like a page's authorship, never from a payload |
| timestamps | |

- **Uniqueness:** a unique index on `(solution_a_id, solution_b_id)`, plus a
  check that a ≠ b (in the request and in the action). The pair is normalized
  rather than relying on a `LEAST/GREATEST` expression index, which SQLite
  (tests) and Postgres spell differently.
- **No undirected option:** the person asked for left, right or both.
- **Model:** `App\Models\SolutionRelationship`.
  - Relations `solutionA()`, `solutionB()`, `creator()`, `updater()`.
  - `scopeInvolving(Solution $s)`, and `directionFrom(Solution $s)`, which
    turns the normalized direction into `Outgoing | Incoming | Both` as seen
    from either side.
- **Route binding:** `Solution::resolveChildRouteBinding()` resolves
  `{relationship}` through `involving($this)`. A relationship that does not
  touch the solution in the URL is a 404, which is the `scopeBindings()`
  guarantee for a relation that lives in two columns.
- **One implementation, two doors:** `App\Actions\Catalog\SaveManualRelationship`
  and `RemoveManualRelationship`, used by the controller and the MCP tools
  alike, as the `Save*` catalog actions already are.

### 5.3 Read side — `App\Services\SolutionRelationshipService`

- **`forSolution(Solution $s): Collection<Relationship>`:** every pair that
  includes S, oriented FROM S. Rules, in order:
  1. A manual record decides the direction.
  2. Otherwise the documented direct links do.
  3. Otherwise the via inference does.
  - A pair that is both direct and via is ONE row, direct, carrying the via
    platform as extra evidence.
- **`pairs(): Collection<Relationship>`:** manual and documented direct pairs,
  for `/map` and the CATI checks. Via pairs are excluded.
- **`Relationship`** is a readonly value object
  (`App\Support\Relationships\Relationship`). It carries:
  - the counterpart and the direction (`App\Enums\RelationshipDirection`:
    `Outgoing | Incoming | Both | Undirected`, each with its arrow, `label()`
    and tooltip)
  - `source` (`documented | manual | adjusted`): adjusted means a manual record
    over a documented or via pair
  - `via` (a list of platform solutions), the labels, the evidence, and the
    manual record when there is one
- **Queries:** two in all. One over `uml_solution_links` (for `forSolution`,
  the rows of every diagram S appears in, which via inference needs) and one
  over `solution_relationships`. They eager-load
  `page:id,notebook_id,slug,title`, `page.notebook:id,slug,name`, the solutions
  as `id,name,slug,logo_path,category`, and `creator:id,name`.

## 6. The solution page — "Relacionamentos"

### 6.1 Placement and access

- **Placement:** `App\View\Components\Solutions\Relationships`, updatable slot
  `solution-relationships-slot`. It replaces `x-solutions.diagrams` as the LEFT
  column of the card in `solutions/show.blade.php:70-74`.
- **Who sees the card:** everybody who can see the solution. A manual
  relationship is a catalog fact, so the card is no longer gated by `viewAny` on
  `Notebook`. Only the EVIDENCE links into cadernos are.
- **The right column:** `x-solutions.notebooks` keeps its own gate. Without it,
  the relationships column spans the card (`lg:grid-cols-2` only when both
  columns render).
- **Who edits:** `update` on the Solution (a catalog Editor). A Viewer sees the
  rows with no pencil, no ✕ and no "Adicionar".

### 6.2 A row (PT-BR on screen)

Header: `x-heroicon-o-arrows-right-left` in the accent tile, "Relacionamentos",
and the count badge. Subtitle: "Soluções com que esta se comunica — dos
diagramas UML da documentação e das interconexões cadastradas."

Rows are sorted by the counterpart's name (folded):

```
[logo] AccessOne (IAM)  ──▶  [logo] Freshdesk ↗   via [logo] Digibee (iPaaS)     ✎
       POST /token · webhook ticket                 Onde está documentado (2) ▾
```

- **Left: THIS solution** (`x-ui.logo`, thumbnail; the component's own
  fallback when there is no logo), as the person's mock-up asked. It anchors
  the reading: in `──▶`, the left side is the one calling.
- **The arrow:** an inline SVG with one head, two heads, or none for
  undirected. The tooltip (`title`) and an `sr-only` sentence say it in words:
  - "AccessOne (IAM) chama Freshdesk"
  - "Freshdesk chama AccessOne (IAM)"
  - "Chamam uma à outra"
  - "Relacionadas, sem sentido definido"
- **Right: the counterpart.** It is the editable datum, so its name is plain
  text inside the inline editor, with an `x-ui.external-link` ↗ to its page.
  That is the inline-edit rule: the ↗ navigates, the text edits.
- **"via"**: the platform's logo and name, for a via row.
- **Source badge:**
  - nothing for a documented row
  - "manual" for a manual one, with a tooltip naming who created it and when
    (`Authorship`)
  - "ajustado manualmente" for an override, whose tooltip also gives what the
    documentation says ("Documentado: Freshdesk chama AccessOne")
- **Second line:** up to 3 labels, `font-mono text-xs text-muted`.
- **"Onde está documentado (N)":** a disclosure (`data-ak-toggle`, a standing
  exception of the second kind in AGENTS.md).
  - Each line reads "Caderno › Página — título do diagrama" and links to the
    page at `#uml-figure-{diagram_position}`. A via row lists both halves'
    diagrams.
  - The anchor exists because `GitbookRenderer::renderUml()` gives the figure
    `id="uml-figure-{n}"`. That cannot collide with a heading slug, as `uml-1`
    could, nor with `docs-uml.js`'s `ak-uml-{n}-fill` gradients.
  - Rendered only for `viewAny` on `Notebook`. A manual-only row has no
    disclosure.
- **Mobile:** the row wraps and the names truncate. The arrow never wraps away
  from the left name.

### 6.3 Editing inline

Everything uses `x-ui.inline-edit`, following `.claude/rules/inline-edit-pattern.md`
(double click or the pencil; Enter/Esc; the editor wears the value's typography;
no form until asked).

- **"+ Adicionar interconexão"** is a creator (`method="POST"`,
  `solutions.relationships.store`). It has two fields:
  - **Sistema**: a select of every other solution. A pair that already has a
    manual record is left out, because that one is edited instead. A
    documented-only pair is offered, and choosing it creates an override. A
    picker with nothing left to offer hides itself.
  - **Sentido**: a select worded from this solution: "→ {esta} chama",
    "← chama {esta}", "⟷ chamam uma à outra".
  - The left side is fixed: it is this solution, shown, not chosen. An
    interconnection listed here always involves the page's own solution, so
    "sistema 1" is the page and "sistema 2" is picked.
- **The pencil on a MANUAL row** opens the same two fields over the existing
  record (`PATCH solutions.relationships.update`). The counterpart may be
  re-pointed; the request re-checks uniqueness, ignoring the row itself.
- **The pencil on a DOCUMENTED or VIA row** opens only **Sentido**, and confirms
  with `POST solutions.relationships.store` carrying the fixed counterpart. That
  creates the override. The row then shows "ajustado manualmente".
- **The ✕** (`x-ui.row-remove`, `DELETE solutions.relationships.destroy`)
  appears only on a row with a manual record. Its confirm:
  - on a manual-only row: "Remover a interconexão entre {A} e {B}?"
  - on an override: "Voltar ao que a documentação diz ({sentido documentado})?"
  - A documented-only row has no ✕. Its disclosure is the way to the diagram
    that would have to change.
- **Never two ✕ at once.** The row is `group/row`, so `x-ui.row-remove`'s
  `group-has-[[data-ak-inline-edit-form]:not(.hidden)]/row:invisible` applies
  as on every other card.
- **Every mutation** answers with `Solutions\Relationships::slot()` and a Toast
  ("Interconexão salva." / "Interconexão removida."), and nothing else. The
  card is the only thing on the page that changes.

Server side:

- **`SolutionRelationshipController`** (thin): `store`, `update`, `destroy`.
  Each authorizes `update` on the Solution and calls the action.
- **Routes:** under `solutions/{solution}/relationships`, `->scopeBindings()`
  for `{relationship}` (§5.2). All three are named
  `solutions.relationships.*`.
- **Requests:** `StoreSolutionRelationshipRequest` and
  `UpdateSolutionRelationshipRequest`, rules in array notation:
  - `other_solution_id`: required, `exists:solutions,id`, different from the
    route's solution.
  - `direction`: required, one of `outgoing`, `incoming`, `both`, relative to
    the route's solution. The action normalizes it into `a/b` + `direction`.
  - The uniqueness of the normalized pair, with a PT-BR message: "Já existe uma
    interconexão entre {A} e {B} — edite a existente."
- **`.claude/rules/inline-edit-pattern.md`:** one more row in the "one card =
  one slot = one pair of endpoints" table.

### 6.4 Elsewhere on and around this screen

- **The delete confirm** (`show.blade.php:15`): the diagram sentence becomes
  "As interconexões cadastradas com ela são removidas; nos diagramas UML que a
  citam, o elemento continua desenhado, sem vínculo."
- **The amber "topologia aprovada" banner** (`show.blade.php:32-51`) goes with
  `ApprovedTopology` (§8.2), as does `SolutionController`'s `pendingTopologies`
  load.
- **`/solutions`:** "N diagramas desenhados" (`solutions/index.blade.php:59`)
  becomes "N relacionamentos", the rows the card would list. Same change in
  `SolutionCatalogStatsService:45`.
- **Spreadsheet and export:** the "Diagramas" column becomes a "Relacionamentos"
  count (`SolutionSpreadsheetService:53,94,166,213`), computed once for the
  whole catalog from the service, never per row.

## 7. `/map` — same picture, new source

"Por ligações" keeps every rule in `.claude/rules/ecosystem-map.md`: one arrow
per pair, straight, two heads when bidirectional.

- **Data:** `SolutionMapController` asks `SolutionRelationshipService::pairs()`,
  which gives the manual and documented direct pairs. The nodes are the
  solutions that appear in a pair.
- **What is left of the old service:** `DiagramGraphService` loses `build()`,
  `dedupePairs()` and `edge()`. The hosting half (`hostings()`, `hostingOf()`)
  stays, renamed `EcosystemMapService`.
- **Undirected pairs** are drawn with no head (`forward: false,
  backward: false`). `ecosystem-map.js` always drew forward (`189-200`).
- **The edge card:**
  - "Sentido" stays, worded as in §6.2.
  - "Protocolo" becomes "Rótulos" (the labels).
  - "Diagramas" becomes "Onde está documentado" (the page links), plus
    "Cadastrada manualmente por {nome}" when manual.
  - The status disappears: a page has no "Planejada / Ativa".
  - `data-ak-map-diagrams` (`ecosystem-map.blade.php:27`) and `edges[].diagrams`
    are renamed to `evidence`.
- **"Por hospedagem"** is untouched: it already reads only `Solution` columns.

## 8. Everything else that read the canvas

### 8.1 MCP

**Read:**

- **Removed:** `get_diagram` and `search_diagrams` (`ToolRegistry:25-26`), with
  `Presenter::diagram()` and `diagramSummary()`. A client that cached the tool
  list calls an unknown tool and gets the same answer a typo gets, which is the
  registry's existing rule. Tests count `ToolRegistry::TOOLS`, never a literal.
- **`get_solution`:** `diagrams` becomes
  `relationships: [{solution: {slug, name}, direction, source, via: [{slug, name}], labels, evidence: [{notebook, page_slug, page, diagram}], created_by}]`.
  - **Labels, not codes.** Values are PT-BR, like everything the payloads
    print. `direction` is "chama", "é chamada por", "mútua" or "sem sentido
    definido"; `source` is "documentado", "manual" or "ajustado manualmente".
  - **Evidence from an unpublished caderno** is listed only for a writing
    connection (`ReadableNotebooks`), marked `"published": false`. For every
    other caller the relationship still shows, since it is a fact about two
    catalog records, but its evidence is cut to what that caller may read. An
    unpublished page's title never leaks.
- **Descriptions and instructions:**
  - `SearchSolutions`' description and `GetSolution` (48, 94-109).
  - `GetDocumentationPage` (20-21, 69-70) stops pointing at `get_diagram`.
  - `McpServer:149`'s instructions drop "os diagramas de topologia" and say
    relationships live in `get_solution`.

**Write:** two new tools in `App\Mcp\Tools\Write`, both `SolutionWriteTool`s.
They are catalog module, implement `Writes`, and are reachable only by a person
at Editor with `mcp:write`.

| tool | arguments | does |
|---|---|---|
| `set_solution_relationship` | `solution`, `other` (slugs), `direction` ("chama", "é chamada por", "mútua", read from `solution`'s side) | creates or updates the manual record for the pair through `SaveManualRelationship`. Idempotent. Over a documented pair it creates the override, and the result says what the documentation states, so the model can tell the person it is contradicting a diagram |
| `remove_solution_relationship` | `solution`, `other` | removes the manual record through `RemoveManualRelationship`. On a documented-only pair it is refused, naming where the pair is documented: "Esta relação vem do diagrama \"X\" na página Y do caderno Z — edite a página (update_page) para mudá-la." |

- **Logging and revert:** both go to `mcp_writes` as a change of the
  solution's `relationships` relation, logged as its whole set, the same shape
  the person links use. An admin reverts them on "Alterações via MCP". The
  revert is skipped when the set changed since, which is the existing rule.
- **Removal is in scope:** "nada é apagado" holds, because a relationship is a
  link, not a record. That is the same reasoning as
  `unlink_person_from_solution`.
- **The server instructions** gain one sentence: relações entre soluções podem
  vir dos diagramas UML (vínculo `"solution"`) ou ser cadastradas com
  `set_solution_relationship`; prefira documentar no diagrama quando a página
  existir.

**Elsewhere in the MCP:**

- **`update_page`'s loss guard:** `diagram:{slug}` disappears from
  `references()` (`UpdatePage` 30, 65, 87, 200, 266-272). `uml:{title}` already
  guards the loss of a whole diagram. Dropping only a binding is an edit, not a
  loss item, and the write log keeps the previous body.
- **`get_code_documentation_guide`**
  (`resources/data/mcp/code-documentation-guide.md`) teaches the binding:
  - "When a participant or element IS a system in the catalog, set `solution`
    to its slug — find it with `search_solutions`, never invent one."
  - "An internal component of the system being documented is not a solution:
    put it inside a group bound to that system."
  - "Draw the integration platform (Digibee, SAP CPI) as the participant it is;
    the end-to-end relationship is inferred from it."
  - `DocumentationUmlTest` already parses the guide's examples. Their slugs
    must exist in the test's seeded catalog.
- **`RevertMcpWrite`** (111-114, 131): the revert-delete guard stops asking
  about `diagramsAsSource/Target`. A solution named by links is NOT protected,
  since deleting it only drops derived rows and its manual records.
- **Labels on screen:** `AccessModule::Documentation`'s label, "Documentação e
  Diagramas", becomes "Documentação". The diagram mentions go from
  `mcp/{authorize, connect, index}.blade.php`.

### 8.2 CATI

The rules are being redefined separately, so this section only does what
removing the canvas forces.

**AS IS / TO BE become uploads, like C1/C2.**

- **The data:** a data migration moves each AS IS / TO BE `SubmissionDiagram`'s
  canvas PNG (`diagram` collection) into its upload collection
  (`submission_diagram`). If an upload is already there, the PNG is deleted and
  the upload is kept: it was the person's explicit choice.
  - Media go through Spatie's `Media` model, so files move or go with their
    rows.
  - Pasted-image nodes (`docs` collection) are deleted; they only meant
    something inside the canvas.
  - Then `submission_diagrams.chain` and `viz_layout` are dropped.
- **The tab:** the Diagramas tab renders all four kinds as upload slots
  (`data-ak-cati-diagram-pick` / `-upload`, which already exist).
  `SubmissionDiagram::picture()` keeps only the upload.
- **Removed:**
  - routes `submissions.diagrams.{edit, layout.save, picture.store, chain.*}`
    (`routes/web.php:470-490`)
  - `SubmissionDiagramController`'s canvas actions
  - `Submissions\DiagramWorkspace`, `submissions/diagram.blade.php`
  - `TopologyDiff` and the AS IS × TO BE diff it fed in `Submissions\Diagrams`
- **Kept:** `upload.store`, `upload.destroy`, `picture.show` (still served by
  `PictureResponse`), and `PromoteApprovedSubmission:150-170`, which copies the
  TO BE picture into the page and now reads the upload.
- **Prompts:** `SubmissionRequirements:168-171` is satisfied by an upload. Any
  prompt or screen prose telling the person to "desenhar no canvas" is reworded
  to "enviar a imagem". Grep `canvas` / `desenh` across `app/Services/Cati` and
  the submission views during implementation.

**`ApprovedTopology` goes whole:**

- the model and its table
- `PromoteApprovedSubmission::recordApprovedTopology()` (205-223)
- `ApplyApprovedTopology`, `ApprovedTopologyController`,
  `submissions.topology.{apply,dismiss}` and both requests
- `Submissions\TopologyHandoff` and its include in the Comitê tab
- `Solution::pendingTopologies()`

**Reads that switch to relationships** (`SolutionRelationshipService::pairs()`
for the submission's solution):

- `ConformanceChecks` (58-156): `$hasDiagrams` becomes "has relationships". The
  details read "Sem relacionamentos documentados." and "Integrações
  documentadas, sem plataforma citada." The "platform" judgement can now also
  read the relationships (a pair with an iPaaS counterpart); that is left to the
  CATI redefinition.
- `DeviationRules` (147-154): "A solução já tem N relacionamento(s) (SAP S/4,
  Digibee (iPaaS)). Quais desses fluxos mudam, e há algo a descomissionar?"
- `SubmissionRequirements` (49, 121-125): the `diagrams` fact becomes
  `relationships`.
- `SubmissionContextResolver:30` and `PreReviewService:42` load the
  relationships instead of `solution.diagrams`.

**Deck — "Relacionamentos documentados":**

- `BuildDeckSpec::diagramSlides()` (210-244) is replaced by one `content`
  slide, titled "Relacionamentos documentados", carrying a `table` block (the
  shape `render_deck.py:119` and `:253` already draw).
  - Columns: Solução · Sentido · Via · Origem ("Documentado" / "Manual").
  - Its rows come from `forSolution()`, so via rows are included.
  - It is emitted only when there is at least one row.
  - It is no longer suppressed by an AS IS picture: a list of who talks to whom
    and a picture of the proposal answer different questions.
- The AS IS / TO BE slides (around 190-200) drop their link to
  `submissions.diagrams.edit`.
- `scripts/render_deck.py:165` loses its diagram mention.

### 8.3 Documentation

- **`GitbookRenderer`:**
  - `{% diagram %}` dispatch (434-436) and `renderDiagram()` (619-650) go.
  - The `linkDiagrams` parameter (73, 105-129) goes, with every caller that
    passes it (`DocumentationReader:180-204`, `DocumentationSearchService:568-572`).
  - The diagram half of `dependencies()` (209-230) goes; bump
    `PARSE_CACHE_VERSION`.
  - `ReaderUrls::diagramPicture()` and the `/diagrams/{slug}/picture` rewrite
    (`DocumentationReader:220,246`) go.
  - The removal phase is gated on no page still citing a diagram (§10).
- **The editor:** `docs-markdown.js` (parse 801-805, serialize 383-386,
  block-start regex 897) and `docs-tools/diagram.js` (registered at
  `docs-editor.js:260,352`) go.
- **The reading screen:**
  - "Desenhar esta página" and its model menu (`_actions.blade.php:48-80`) go.
  - So do "Diagramas do caderno" (`documentation/edit.blade.php:57-67`) and
    `catalogUrl` (`_reader.blade.php:49`).
  - `NotebookPageController:87-100` stops computing `diagramDraftUrl`,
    `modelUrls`, `notebookDiagramsUrl` and `notebookDiagramCount`.
- **Search:** the `diagram` tag goes (`DocumentationSearchService` 72), with
  the `ak-doc-diagram` needle (702-705) and the facet labels
  (`Documentation/SearchResults.php:36,46`). Bump the index's cache version.
- **Assiste IA:**
  - `BlockVault:74`'s `{% diagram %}` pattern and its warning (213-215) go.
  - The prompt prose (`DocumentationChatPromptBuilder:145-177`) goes. It gains
    one sentence: keep every `"solution"` binding the source has.
  - `ContextPageResolver:30` and `DocumentationChatService:92` change too.
- **Notebooks:**
  - `Notebook::diagrams()` goes, with the diagram deletion in the `deleting`
    hook (306).
  - The catalog card's delete confirm stops counting diagrams
    (`Notebooks/Index.php:84,202-205`).
  - `notebooks.diagrams.*` and `notebooks.pages.diagram*` go.
- **`/docs` and the magic link:**
  - `docs.diagram` (`routes/web.php:130`) goes.
  - So do `public.docs.diagram` (786-788) and
    `PublicDocumentationController::diagramPicture()` (162-177), and with them
    the one exact-slug exception in `eloquent-strict-and-search.md`.
- **Requests:** `SaveDocumentationRequest:12` and
  `UploadDocumentationMediaRequest:13` stop reading `route('diagram')`.
- **Reserved slugs:** `DocumentationPageService:58,60` keeps `diagrams` and
  `diagram` reserved. Releasing them buys nothing, and an old bookmark would
  start resolving to a page.
- **The `/docs` home:** `database/data/knowledge-base-home/home.md:31-35` (the
  "## Diagramas" section and `base-diagramas.svg`) goes.

### 8.4 Catalog, layout, misc

- **Sidebar:** the "Diagramas" entry (`layout.blade.php:34`) goes.
- **`ProfileController`:** the cards at 36 and 62 go.
- **`DeleteSolution`:** it stops rewriting `Diagram` AND `SubmissionDiagram`
  chains and stops sweeping `approved_topologies`. Manual relationships cascade.
  `SolutionController:184-189` stops counting topologies and counts the manual
  relationships it removes, for the confirm.
- **`AttributeOptionController:81`:** the "criticality option used by a
  Diagram" block goes. Deleting the `ipaas` category option is NOT blocked: the
  inference just stops finding platforms.
- **`QueueDocumentationWebImagesCommand:49`:** the `Diagram` morph goes.
- **`config/services.php:463-468`:** `documentation_ai.diagram_timeout` goes.
- **Showcase and empty states:** `showcase.blade.php` (117, 145) and
  `x-ui.empty-state`'s `diagrams` illustration go.
- **Status badge:** `status-badge.blade.php:2` stops accepting `DiagramStatus`,
  and `app.css:24` loses its `@source`.
- **The canvas assets:**
  - `chain-viz.js` and `resources/css/components/chain-viz.css` go.
  - So do the presentation-mode rules (`app.css:405-416`), the lazy loader
    (`app.js:39`, `lazy.js`) and the npm packages `html-to-image` and `gif.js`,
    whose only importer is the canvas.
  - `graph-canvas/*` and `d3-force` stay, for `/map`.
- **`pending-drawing.js`** goes, with its import in `ajax-post.js:3` and the
  `data-ak-ajax-pending-kind` / `-hint` rows. `data-ak-ajax-target` and
  `-pending` stay: they are general mechanisms.
- **AGENTS.md § Security:** it cites `StoreDiagramPictureRequest` as THE example
  of "no inline validation, even for a client-generated payload". The example
  is re-pointed at a surviving request, and the rule stays.
- **Stale comments only:**
  - the flowspec files (`FlowspecAttachmentController:84`,
    `FlowspecChatController:109`, `FlowspecDocumentReference:12`,
    `FlowspecDocumentType:24`, `GuardsFlowspecContext:45`)
  - `PageLinks:18,134`
  - `Tool.php:55`
- **`verify-integration-viz.mjs`** (repo root, already stale) goes.

### 8.5 Deleted outright

| kind | files |
|---|---|
| models | `Diagram`, `ApprovedTopology` |
| enums | `DiagramStatus`, `DiagramModel`, `Direction`, `SyncMode`, `Protocol`, `ChainNodeKind` |
| contracts and concerns | `ChainCanvas`, `Controllers/Concerns/EditsChain`, `AuthorizesChainOwner`, `ValidatesChainNode`, `View/Components/Concerns/ChainWorkspace` |
| controllers | `DiagramController`, `DiagramPictureController`, `NotebookDiagramController`, `NotebookPageDiagramController`, `ApprovedTopologyController` |
| requests | `UpdateDiagramMetaRequest`, `SyncDiagramSystemsRequest`, `StoreNotebookDiagramRequest`, `DrawNotebookPageRequest`, `Apply/DismissApprovedTopologyRequest`, the 13 `*Chain*` requests, `StoreDiagramPictureRequest` |
| policy | `DiagramPolicy` |
| actions | `SyncDiagramFromChain`, `SetDiagramSystems`, `WriteNotebookDiagram`, `CreateDiagramFromDraft`, `CreateDiagramFromModel`, `ApplyApprovedTopology` |
| services | `DiagramCatalogService`, `DiagramDraftService`, `DiagramDraftPromptBuilder`, `DiagramModelService`, `DiagramModelPromptBuilder`, most of `DiagramGraphService` (§7) |
| support | `ChainGraph`, `ChainLabeler`, `TopologyDiff`, `Diagrams/{ModelSpec,ModelLayout}`, `Documentation/ChainDraft`, `DiagramSlug`, `Documentation/DiagramCitation`, `Exceptions/DiagramDraftFailed`. `ModelJson` stays (`SceneDraftService`); `PictureResponse` and `HasWebImageRendition` stay |
| view components | `Diagrams/{Index,Meta,Systems,Workspace}`, `Solutions/Diagrams`, `Submissions/{TopologyHandoff,DiagramWorkspace}` |
| views | `diagrams/{index,show}`, `components/diagrams/*`, `components/chain/{viz,workspace}`, `components/solutions/diagrams`, `components/submissions/topology-handoff`, `submissions/diagram`, `notebooks/{diagrams,diagram-target}`, `components/illustrations/empty-diagrams` |
| JS / CSS | `chain-viz.js`, `chain-viz.css`, `docs-tools/diagram.js`, `pending-drawing.js` |
| MCP | `GetDiagram`, `SearchDiagrams` |
| data | `DiagramSeeder` (and `DatabaseSeeder:28`), `DiagramFactory`, `tests/Pest.php`'s `attachParticipants()` |
| routes | `diagrams.*` (562-609, 753), `notebooks.diagrams.*`, `notebooks.pages.diagram*`, `docs.diagram`, `public.docs.diagram`, `submissions.topology.*`, the canvas half of `submissions.diagrams.*` |
| rules | `page-to-diagram-draft.md`, `diagram-models.md`, `diagram-chain-canvas.md`, `chain-viz-pointer-events.md` |

`/diagrams` and `/diagrams/{slug}` simply 404 afterwards. With 11 drawings, all
"Planejada", a redirect table is not worth its code.

## 9. Converting the 11 drawings into their documentation

A one-shot command, deleted in phase 4 together with the model it reads:

```bash
sudo -u www-data php artisan diagrams:to-uml --dry-run [--diagram=slug] [--user=email] [--keep-picture]
```

**The UML it builds:**

- **Type:**
  - A drawing whose nodes are `lifeline`s (the sequence model) becomes
    `type="sequence"`. Participants keep node order; messages keep edge order.
    `->` becomes a `sync` from→to, `<-` a `sync` to→from, and `<->` two `sync`s.
  - Everything else becomes `type="component"`.
- **Node kinds:**
  - `system` and `lifeline` → `component`
  - `actor` → `actor`
  - `decision` → `decision`
  - `start` / `end` → `initial` / `final`
  - `step` → `action`
  - `image` → a `note` saying "imagem do diagrama original", since a pasted
    picture has no UML equivalent (see `--keep-picture`)
- **Bindings:** a node with a `solution_id` gets `"solution": "<slug>"`. That is
  what carries today's correlations over.
- **Edges:**
  - `->` → `directed` from→to; `<-` → `directed` to→from; `<->` → two
    `directed` edges, which draw as one line with a head at each end.
  - The label is the protocol's (`Protocol::tryFrom()->label()` or the free
    text).
- **Cells (`at`):** taken from `viz_layout`'s node positions.
  - Distinct x positions, bucketed by one block width, become ranked columns;
    y positions, ranked rows. The result is clamped to 12 × 20.
  - A collision moves to the next free cell in the row: the validator refuses
    two elements in one cell, so this must be exact.
- **Title:** the drawing's name.

**Where it lands — the documentation it belongs to,** first match wins:

1. Every page that cites it: the `{% diagram slug="x" %}` line is replaced by
   the block.
2. The page of its caderno that it was drawn FROM. Drawings made with
   "Desenhar esta página" are named "{título da página} — {modelo}"; the page
   whose title folds equal to the name before the last " — " gets the block
   appended at its end, under `## Diagrama`.
3. Otherwise, the caderno's first page (`firstPage()`), appended the same way.
   Every one of the 11 has a caderno (`notebook_id` is NOT NULL).

**How it writes:**

- Saves go through the model (`$page->update()`), so the index (§5) fills as
  they go.
- `--user` signs the saves as that account. Without it they record nobody,
  which the authorship rule reads as "autor não registrado".
- Every block goes through `UmlDiagram::parse()` before writing. An invalid one
  is NOT written: its problems are printed and its citation stays, so phase 4's
  gate (§10) catches it.

**What it reports**, per drawing:

- the target page and which rule chose it
- the number of bindings and the relationships they produce (direct and via)
- the warnings (`UmlDiagram::$warnings`, a line crossing a box)

The crossing check that refuses MCP writes is only a WARNING here: the
conversion is a starting point the person adjusts in the editor, not a model
writing blind. `--dry-run` prints all of it and writes nothing. That is the
review step before the real run.

**`--keep-picture`** copies the drawing's last PNG (`diagram` collection) into
the page's `docs` collection. It goes under the block as the single-line
`<figure><img src="/files/{id}" …><figcaption>Desenho original</figcaption></figure>`,
for drawings whose picture said more than the conversion keeps (pasted images,
free text). It is off by default.

## 10. Phases

Each phase is one branch, one commit, a `--no-ff` merge and a deploy.

Status per phase (✅ done · 🚧 in progress · ⏳ not started):

| phase | status | merged |
|---|---|---|
| 1. Bindings and documented relationships | ✅ DONE, verified 2026-11-26 (§14) | `97deeb8` |
| 2. Manual relationships | ✅ DONE, verified 2026-11-26 (§14) | see §14 |
| 3. The conversion | ✅ DONE — run in production 2026-11-27, pages reviewed (§14) | `b9beb89` |
| 4a. The removal — the catalog drawings | ✅ DONE 2026-11-27 (§14) | see §14 |
| 4b. The removal — the committee canvas | ✅ DONE 2026-11-27 (§14) | see §14 |
| 5. Documentation | ✅ DONE 2026-11-27 (§14) | see §14 |

1. ✅ **Bindings and documented relationships** (additive; nothing is removed):
   - §3: the `solution` key, `BINDABLE_KINDS`, group inheritance,
     `UmlSolutionBindings`, the editor check's problems and hints, the MCP
     refusal, the guide.
   - §3.4: the rename rewrite.
   - §5.1: the migration, the model, the sync on save and on solution create,
     `documentation:solution-links`.
   - §4.4: via inference.
   - The service, read side.
   - `#uml-figure-{n}` on the figure.
   - §6.1–6.2: "Relacionamentos" replaces "Diagramas", read-only for now. The
     index, spreadsheet and stats switch over.
   - §7: `/map` re-sourced.
   - §8.1: `get_solution.relationships`. `get_diagram` and `search_diagrams`
     stay one more phase.
   - On deploy, run `documentation:solution-links` once.
2. ✅ **Manual relationships:**
   - §5.2: the migration, the model, the two actions.
   - §6.3: inline add, edit, override and remove.
   - §8.1: the two MCP write tools, the log and the revert.
   - The source and "via" badges.
3. ✅ **The conversion:** `diagrams:to-uml` (§9). Run `--dry-run` in production,
   review its report, then run it for real. The person reviews the 11 pages,
   and `/map` should show the drawings' pairs again, now from UML.
4. **The removal:** everything in §8, CATI canvas included. Split in two
   deliveries, each with a green suite and its own deploy: ✅ **4a**, the
   catalog drawings (the `/diagrams` module, the citations, the MCP tools,
   `ApprovedTopology`, their tables); ✅ **4b**, the committee canvas (§8.2).
   - **The gate:** no page body still contains `{% diagram`. The migration
     REFUSES to run otherwise and names the pages.
   - **The media:** `media` rows with `model_type = App\Models\Diagram`, and
     the CATI ones described in §8.2, are deleted or moved through Spatie's
     `Media` model, so no file is orphaned on disk.
   - **The tables:** drop `approved_topologies`, `diagram_solution` and
     `diagrams`, and the two `submission_diagrams` columns. One migration per
     concern.
   - **The rules:** fix every `paths:` that pointed at a deleted or renamed
     file. `ecosystem-map.md` points at the new service. A glob that resolves
     to nothing never loads.
5. ✅ **Documentation:**
   - README: ~60 mentions; the main blocks are 4-28, 589-617, 783-806,
     859-925, 1017-1035, 1124-1142, 1296-1333, 1565, 1732-1795 and 1931-1993.
   - AGENTS.md: 9-10, 65-74, 513, the module list in its opening paragraph,
     and the Security example (§8.4).
   - A new rule, `.claude/rules/solution-relationships.md`, with `paths:` over
     `UmlSolutionBindings`, `SyncPageSolutionLinks`,
     `SolutionRelationshipService`, `SolutionRelationship`,
     `SolutionRelationshipController`, `Solutions/Relationships`, the two MCP
     tools and the map service.
   - Edits in `cadernos-notebooks`, `cati-submissions`, `mcp-server`,
     `slugs-and-routing`, `media-and-uploads`, `docs-search-index`,
     `documentation-assistant`, `roles-and-policies`, `css-and-docs-rendering`
     (the canvas exception), `data-ak-attribute-reference` (every `chain`,
     `viz`, `node-kinds`, `solutions/protocols` and `diagram-summary` row),
     `inline-edit-pattern` (its last section, about the canvas's top bar, and
     the new table row) and `doc-uml` (the binding).
   - Also `docs/cati-fase-*.md`.

## 11. Tests

The groups below are the cases, not file names: phase 1 put its derivation,
binding, via, sync, card and MCP cases in ONE file,
`tests/Feature/SolutionRelationshipsTest.php`, and the map's in
`EcosystemMapTest.php`.

**New:**

- **`UmlSolutionBindingTest`:**
  - parse accepts and carries `solution`
  - a non-bindable kind is refused, and so is a non-slug
  - group inheritance works, and a member's own binding wins
  - unknown slugs: refused by MCP `create_page`/`update_page` with the
    sentence; not refused for an UNCHANGED block; drawn and ignored by the
    reader; listed by the editor check, along with the name-match hint
- **`SolutionRelationshipDerivationTest`:**
  - each message kind; `return` and gates ignored; messages inside
    `alt`/`else` counted
  - each edge kind; same-solution ends ignored
  - pair folding: both ways gives bidirectional; directed beside undirected;
    labels capped at 5 and deduplicated
- **`PlatformViaTest`:**
  - sequence X → P → Y gives via
  - another caller in between breaks it
  - two platforms in a row; three do not chain
  - grid fan-in and fan-out infer; a hub infers nothing
  - two different diagrams never combine
  - recategorising a solution to or from `ipaas` changes the answer with no
    re-sync
  - via pairs are absent from `pairs()`
- **`SyncPageSolutionLinksTest`:**
  - every writer re-syncs (editor save, MCP, `PromoteApprovedSubmission`,
    import), through the model hook; a title-only save does not
  - page, caderno and solution deletes cascade
  - a solution created later picks up the page
  - the slug rename rewrites ONLY inside `{% uml %}`, leaving the rest of the
    JSON byte-for-byte
- **`ManualRelationshipTest`:**
  - store, update (re-pointing the counterpart) and destroy, each answering with
    the slot
  - pair normalization from both sides; the unique pair with its message;
    self-pair refused
  - Viewer → 403
  - `{relationship}` of another solution → 404
  - override of a documented pair, and its removal restoring the documented
    direction
  - a documented-only pair has no destroy
  - deleting a solution cascades its relationships
- **`SolutionRelationshipsCardTest`** (render):
  - the arrow per direction and the `sr-only` sentence
  - logo and no-logo
  - via and source badges
  - evidence links with `#uml-figure-n`, hidden without `viewAny` on `Notebook`
  - the creator hides when nothing is left to offer
  - the empty state with real quotes (`assertDontSee('&amp;quot;')`)
  - a Viewer sees no pencil and no ✕
- **`EcosystemMapTest`:**
  - pairs come from links and manual records
  - an undirected pair carries no head
  - via pairs are absent
  - hosting is unchanged
- **MCP:**
  - `get_solution.relationships`, including `via` and `source`
  - an unpublished caderno's evidence is hidden from a non-writing connection,
    while the relationship itself stays
  - `set_solution_relationship` is idempotent and reports an override
  - `remove_solution_relationship` is refused on a documented-only pair, naming
    the page
  - both are logged and revertible, and hidden without `mcp:write`
  - `get_diagram` is unknown
- **`DiagramsToUmlCommandTest`** (phase 3, deleted in phase 4):
  - sequence and component conversions; `<-` and `<->`; distinct cells
  - the three placement rules
  - `--dry-run` writes nothing
  - an invalid conversion leaves its citation
- **CATI:**
  - the AS IS/TO BE PNG moves to the upload slot, or yields to an existing
    upload
  - the deck's relationships slide: present with its rows, absent with none
- **`DocumentationUmlTest`:** the guide's binding examples parse against seeded
  solutions.

**Deleted in phase 4:**

- `DiagramChain{EdgeAdd, EdgeRemove, EdgeRetarget, ImageAdd, NodeAdd, NodeImage, NodeRemove, NodeUpdate, ProtocolUpdate, Restore}Test`
- `DiagramCrudTest`, `DiagramEndpointsTest`, `DiagramGraphServiceTest`,
  `DiagramLayoutSaveTest`, `DiagramManualSystemsTest`,
  `DiagramModelGeneratorTest`, `DiagramPictureTest`, `DiagramSeederTest`,
  `DiagramsModuleTest`, `DiagramVizGraphTest`, `SyncDiagramFromChainTest`,
  `WriteNotebookDiagramPicturesTest`, `DocumentationPageDiagramDraftTest`
- `CatiApprovedTopologyTest`, `CatiTopologyDiffTest`, `ChainVizMarkupTest`,
  `ChainLabelerIsolatedNodeTest`
- `CatiSubmissionDiagramTest` is cut down to the uploads

**Edited** (~30 mixed files):

- **Heavy:** `SolutionDeleteTest`, `DocumentationTest`,
  `PublicDocumentationTest`, `McpToolsTest`, `SolutionWorkspaceCardTest`.
- **Light:**
  - CATI: the deck, requirements, render and chat tests.
  - Catalog: `SolutionSpreadsheetTest`; `SolutionModelTest`
    (`scopeWithDiagramCounts` is dead code and goes); `ColorRefactorTest`.
  - Documentation: the chat, context, render-cache, rendition and requirements
    tests; `KnowledgeBaseTest`.
  - Flowspec: the attachment test (its "diagram is refused" case goes).
  - Access and crawl: the access and crawl tests (`PageCrawlSmokeTest` seeds
    `DiagramSeeder`) and `PublicImageThumbnailTest`.
  - MCP: `McpOAuthTest` and `McpDocumentationWriteToolsTest`.

## 12. Known limits

- **A hub drawn as one picture infers nothing through its platform** (§4.4).
  The fix is in the documentation (split the diagram, or draw a sequence), not
  in a smarter guess.
- **A binding in a page that was never saved after a solution was deleted stays
  in the text**, inert. The editor's check flags it the next time someone opens
  that block.
- **Manual relationships carry no label.** They are a direction between two
  systems, as asked. A label (protocol, "REST") is a small follow-up if the
  map's card needs it.

## 13. History

- **Revision 1 (2026-11-26):** first draft, with five open questions.
- **Revision 2 (2026-11-26):** the person's answers:
  1. The CATI canvas goes too; the CATI rules will be redefined separately.
  2. An `association` counts, as "sem sentido definido".
  3. Via inference through a platform: Digibee AND SAP CPI. Implemented as the
     existing `ipaas` category rather than a new flag.
  4. Convert the 11 drawings into the documentation they belong to.
  5. The deck gets a "Relacionamentos documentados" slide.

  Added in the same revision:
  - manual interconnections, inline on the solution page (add, edit, remove);
    editing a documented row overrides it
  - the MCP manages those relationships too

  Confirmed by the person the same day, and the spec approved:
  - "Sistema 1" is always the page's own solution; only the counterpart is
    picked.
  - Editing a documented row never touches the page: it writes a manual
    override, and removing the override restores the documented answer.

## 14. Implementation notes

### Phase 1 — 2026-11-26 ✅ DONE

Merged as `97deeb8`. Verified against §10's list item by item:

| item | status | where |
|---|---|---|
| §3 binding key, `BINDABLE_KINDS`, groups | ✅ | `UmlDiagram::solution()`, `BINDABLE_KINDS` |
| §3.3 slug resolution, editor problems/hints, MCP refusal | ✅ | `UmlSolutionBindings`, `NotebookPageUmlController`, `GitbookRenderer::problems()` |
| §3.4 rename rewrite, create pick-up | ✅ | `RewriteSolutionBindings`, `SyncPageSolutionLinks::forPagesNaming()`, `SaveSolution` |
| §4.1–4.3 direction and folding | ✅ | `UmlSolutionBindings::links()`, `SolutionRelationshipService::derive()` |
| §4.4 via (sequence order, grid fan-in/out, ≤ 2 platforms, one diagram) | ✅ | `viaSequence()`, `viaGrid()` |
| §5.1 table, model, sync on every writer, command | ✅ | migration `2026_11_26_191220`, `DocumentationPage::booted()`, `documentation:solution-links` |
| `#uml-figure-{n}` | ✅ | `GitbookRenderer::renderUml()` |
| §6.1–6.2 card (read-only), index strip, spreadsheet | ✅ | `Solutions\Relationships`, `SolutionCatalogStatsService`, `SolutionSpreadsheetService` |
| §7 `/map` | ✅ | `EcosystemMapService`, `ecosystem-map.js` |
| §8.1 `get_solution.relationships` | ✅ | `Presenter::relationship()` |
| guide | ✅ | `code-documentation-guide.md` § "Vínculo com o catálogo" |

The verification also checked two things no item names:

- **No writer bypasses the page model.** There is no `DB::table` and no
  builder-level `update()` of `documentation` in `app/`, so the hook sees every
  write. Tests cover the editor save (`notebooks.pages.update`) and the MCP
  `update_page`.
- **The screen at both widths.** The card was screenshotted in headless Chrome,
  desktop and 390 px. On a phone the row's own solution is its logo alone (the
  name is `max-sm:hidden`), because the counterpart's name was being truncated
  to "Active …".

Still on the person: run `documentation:solution-links` once after the deploy.

Shipped as specified, with these differences. Each is the code's answer, and
the rule `.claude/rules/solution-relationships.md` describes the result.

- **Link rows are not deduplicated.** Via inference needs every call in its
  order: two identical `P → Y` messages at different points of a sequence can
  serve two different callers. The pair folding deduplicates labels and
  evidence instead.
- **The page hook is `created` + `updated`, not `saved` +
  `wasRecentlyCreated`.** That flag stays true for the instance's whole life,
  so every later save of a page created in the same request re-synced.
- **The editor's check answers `bindings` beside `problems`.** An unknown slug
  does not make the page draw an error box, and the block words `problems` as
  "a página vai mostrar este diagrama como erro". So the block prints
  "Vínculo sem efeito: …" and "Dica: …" instead.
- **`#uml-figure-{n}` exists on the READ view only** (`/docs`, the read-only
  page). The editor builds its blocks in Editor.js, which has no such id. An
  Editor opening the link lands on the page, not on the figure.
- **The card shows to every account that sees the solution.** Only the evidence
  links into cadernos are gated (§6.1). When the cadernos column is hidden, the
  relationships column takes the card's whole width.
- **The empty state reuses the `diagrams` illustration.** Phase 4 renames it
  rather than deleting it.
- **`Solutions\Diagrams` and `DiagramController::destroy`'s slot for it stay
  until phase 4.** The component is no longer on the page; the delete still
  answers with its slot, which the page simply does not have.
- **`DiagramGraphService` was renamed `EcosystemMapService` in this phase**,
  since `/map` switched source now, and `DiagramGraphServiceTest` became
  `EcosystemMapTest`. Its edge cases are in `SolutionRelationshipsTest`.
- **`get_solution` keeps `diagrams` until phase 4**, beside the new
  `relationships`.
- **Deploy:** run `sudo -u www-data php artisan migrate --force` (Envoy already
  does), then once `sudo -u www-data php artisan documentation:solution-links`.
  Until somebody binds a participant, every solution page says "Nenhum
  relacionamento documentado" and `/map`'s links view is empty. That is
  expected, and phase 3's conversion of the 11 drawings is what brings their
  pairs back.

### Phase 2 — 2026-11-26 ✅ DONE

Verified against §10's list:

| item | status | where |
|---|---|---|
| §5.2 table, model, normalized pair, authorship | ✅ | migration `2026_11_26_193414`, `SolutionRelationship`, `ManualRelationshipDirection` |
| §5.2 scoped `{relationship}` binding | ✅ | `Solution::resolveChildRouteBinding()` |
| §5.3 a record wins for its pair; `documented` kept | ✅ | `SolutionRelationshipService::overlay()`, `Relationship::source()` |
| §6.3 add, edit (re-point), override, remove — inline | ✅ | `Solutions\Relationships`, `SolutionRelationshipController`, `Store/UpdateSolutionRelationshipRequest` |
| source and via badges | ✅ | the card's second line |
| §6.4 delete confirm names the interconnections | ✅ | `solutions/show.blade.php` |
| §7 map counts manual pairs, card says "Cadastro: manual, por …" | ✅ | `EcosystemMapService`, `ecosystem-map.js` |
| §8.1 `set_solution_relationship` / `remove_solution_relationship`, log, revert | ✅ | `App\Mcp\Tools\Write\*`, `RecordFields` (`relationships`), `WriteList` |
| `get_solution` source / documented / recorded by | ✅ | `Presenter::relationship()` |

**Verified in a browser**, headless Chrome on a throwaway SQLite database:
recording an interconnection with the creator, overriding a documented pair
from its pencil, and the editor open on a manual row. The first run found a
real bug the tests had missed:

- Every direction `<select>` rendered EMPTY, and saving posted
  `direction: null` (422).
- The cause: the component had a public static `directionOptions()`, and a
  class component's public methods are handed to its view as variables,
  replacing the array `render()` passed under the same key.
- The fix: it is `directionChoices()` now. A render test asserts the option
  labels, and the trap is in `.claude/rules/blade-compiler-traps.md`.

Differences from the text above:

- **The override is `PUT solutions.relationships.override`** (the counterpart
  in the query string), not a POST to `store`. `x-ui.inline-edit` makes every
  POST a "+ Adicionar" creator chip, and the documented row's pencil is an edit.
- **The badges sit on the row's second line**, with "via", not beside the
  name. Beside it, the badge covered the counterpart's name and squeezed the
  open editor.
- **The direction options say "esta"** ("→ esta chama a outra"), not the
  solution's name, which cut the sentence inside the select.
- **MCP `source` values:** "documentado", "cadastrado", "cadastro sobre a
  documentação". The payload also carries `documented_direction` and
  `recorded_by`.
- **The write log names the relation "interconexões cadastradas"** and prints
  each as "slug (chama)".
- **A known edge of the existing revert:** `RevertMcpWrite` re-validates every
  column of the solution even when only a relation is being reverted, so a
  solution whose category option was deleted since cannot have its
  interconnections reverted either. That predates this phase (it applies to
  `people` too) and was left as it is.

### Phase 3 — 2026-11-26 🚧 command merged, production run pending

`App\Support\Diagrams\DiagramToUml` (the conversion, pure) and
`diagrams:to-uml` (the placement and the writes). Both are deleted in phase 4.

**Built against the production drawings**, read through `get_diagram` on
2026-11-26:

- data flows between systems;
- processes made only of steps and decisions, with no system at all
  (Fluxo Login Cliente, Pipelines Digibee e VTEX, Visão AS-IS);
- a lifeline sequence (SAP × Onfly);
- a mixed drawing (Amigo Leo).

Three of them were recreated in a throwaway database, converted by the real
command, and screenshotted as `/docs` draws them.

**What the person runs**, as www-data, after the deploy:

```bash
sudo -u www-data php artisan diagrams:to-uml --dry-run --user=<e-mail>
sudo -u www-data php artisan diagrams:to-uml --user=<e-mail>        # same output, written
```

After the real run, check two things. `/map` shows the drawings' pairs again,
and the command's last line reports no page still citing a diagram.

**Differences from §9:**

- **The type is chosen from the blocks**, not "component unless lifelines":
  - **sequence**: lifelines, OR only systems/actors with two or more links
    between one pair. "Visão Geral E-Commerce" is SAP → CPI → Digibee → VTEX
    and back, in three rounds, nine links over four systems. As components those
    rounds were lines drawn on top of each other; as a sequence they read in
    order, and the via inference sees SAP ⟷ VTEX through CPI and Digibee.
  - **activity**: no block is a system — a process of steps and decisions. Steps
    become `action`s, links become `flow`s.
  - **component**: otherwise.
- **Cells:**
  - **From the canvas positions**, when every block has one AND they fit 12 × 20.
  - **Otherwise from the links' depth.** An activity grows DOWNWARD (row = depth),
    since a process of 14 steps does not fit 12 columns; the others grow
    rightward.
  - **Collisions** move to the next free cell either way.
- **An appended diagram goes under `## {nome do desenho}`**, not
  `## Diagrama`. Two drawings landing on one page would otherwise get two
  identical headings.
- **`--show`** prints each block; **`--user`** signs the page saves.
- **Known limit: a response is a call.** The canvas never distinguished a
  response from a call, so "Onfly → SAP: RDVs aprovadas" converts to a `sync`
  message and the pair reads "mútua". That is the same direction the old map
  drew. It was not guessed, because the reverse message right after a call is
  NOT always its response: VTEX → Digibee in the e-commerce flow is a webhook.
  The person reviewing marks `"kind": "return"` in the editor where it is one.
- **Manually declared systems are dropped.** "Pipelines Digibee e VTEX" lists
  Digibee, CPI and SAP as participants with no block for them, and no
  relationship follows from them. A UML block has nowhere honest to put "these
  systems take part, somewhere".

### Phase 3 — production run, 2026-11-27 ✅ DONE

**The run:** `diagrams:to-uml` converted all 11 drawings, and the validator
refused none. Its last line reported ONE page still citing a diagram,
`leo-e-commerce-vtex/clientes`. That citation was to
`cadastro-e-atualizacao-de-clientes-2`, a drawing deleted BEFORE the
conversion, so the page showed the "Diagrama removido" card and the command
had nothing to convert.

**Fixed through the MCP** (`update_page`, every change logged in "Alterações
via MCP" and revertible):

- `clientes`: the dead citation and the page's Mermaid + ASCII tabs, which
  this app never draws, became one `{% uml type="sequence" %}`. It is bound to
  `sap-s-4hana`, `digibee-ipaas` and `vtex`, with its alt/loop/returns. **No
  page cites a diagram any more, so phase 4's gate is open.**
- Crossings removed by moving cells only:
  - `accessone-iam/teste`. That diagram was not a test: it is the page's real
    integration architecture (Senior HCM → AccessOne → Entra ID). It was
    renamed "Integrações do AccessOne", not deleted.
  - `amigo-leo/amigo-leo`
  - `integracoes-leo-360/dados-e-acessos`
  - `leo-e-commerce-vtex/fluxo-cadastro-cliente`, top-down
  - `leo-e-commerce-vtex/pagina-inicial`, the Pipelines activity, top-down
  - `freshdesk-integracoes/integracao-freshdesk`
- **Moved:** the two "Dados Mestre de Clientes…" diagrams went from
  `integracao-freshdesk` to `dados-mestre-de-clientes`, next to the pictures
  of the same flows.
- **Returns marked:** the sequence's responses are `"kind": "return"` in
  "Dados Mestre" and in "SAP × Onfly".

**Left to the person:**

- **`pdv-online`: the "Visão AS-IS • Processo" diagram** landed on the
  caderno's first page ("Difusão / Fluxo de Produtos e Preços", 20 KB) when it
  belongs on `visao-as-is` (50 KB). Neither page was resent whole through the
  MCP, which was too large to retype safely. The re-laid, validated JSON
  (14 steps, top-down) was handed over to paste in the editor.
- **`amigo-leo`: the old canvas's palette leftovers.** "Decisão", "Ator
  (pessoa/área)", Início, Fim and "Etapaestá muito grande eu acho" are still
  in the diagram. Removing them was refused by the permission classifier as
  beyond the approved scope, rightly so.
- **`accessone-iam`: the "UBER" node** looks like a test entry.
- **Found on the way, unrelated:** `onfly/integracao-sap-x-onfly-manual-de-orientacao`
  publishes the Onfly API token in plain text (the "Parâmetros por Empresa
  SAP" table). It belongs in `{% secret %}`, and the token should be rotated.

### Phase 4a — 2026-11-27 ✅ DONE (the catalog drawings)

Removed: the `/diagrams` module (model, policy, the five controllers, their
requests, views, components, routes, the rail entry, the home cards, the
seeder, the factories), the `{% diagram %}` construct on both ends of the
round trip (`GitbookRenderer`, `docs-markdown.js`, `docs-tools/diagram.js`),
the reader picture routes (`docs.diagram`, `public.docs.diagram`,
`DiagramCitation`, `ReaderUrls::diagramPicture`), "Desenhar esta página"
(`DiagramDraftService`, `DiagramModelService`, `pending-drawing.js`,
`ajax-post.js`'s new-tab mode), `search_diagrams`/`get_diagram` and the
`diagrams` key of `get_solution`, the `diagram:{slug}` items of `update_page`'s
loss guard, `ApprovedTopology` and the approval's topology handoff, and the
phase-3 converter (`diagrams:to-uml`, `DiagramToUml`).

What changed shape rather than going:

- **Parse cache** `v3`: the key is the text, the URL root and the `{% file %}`
  media; `render()` lost `linkDiagrams`. **Search index** `v7`: the "Diagramas"
  facet now finds UML figures (`class="ak-uml"`).
- **`DeleteSolution`** only rewrites committee drawings now (a solution node
  becomes free text) and deletes; interconnections and evidence rows cascade.
  Its confirm says the UML diagrams keep drawing the element, unbound.
- **CATI:** the conformance and deviation checks and the requirements fact
  (`relationships`, "Integrações já documentadas") read
  `SolutionRelationshipService`. The deck's per-drawing slides became one
  "Relacionamentos documentados" table (Solução / Sentido / Via / Origem,
  12 rows per slide, then "(cont.)").
- **Revert of an MCP create** is blocked by "relacionamentos documentados"
  (an evidence row naming the solution) where it used to be blocked by its
  diagrams.
- **`AccessModule::Documentation`** is labelled "Documentação".

**Migrations** (`2026_11_27_120019_*`, one concern each): the gate plus the
media sweep (refuses while any page has a `{% diagram %}` line and names it;
deletes the `App\Models\Diagram` media through the `Media` model), then drop
`approved_topologies`, `diagram_solution`, `diagrams`. Each drop's `down()`
recreates the empty table, so a rollback finds the schema the previous release
expects. `purge_test_diagrams` (2026-09-30) was rewritten against the tables and
the morph name, since a fresh database could no longer load the model it used.

**Tests:** the diagram-only files went with the code; the rest were moved to
relationships (deck slide, CATI facts and questions, the MCP evidence for a
reader closed to the documentation) or to the committee canvas (the web
rendition). New: `TopologyDiagramRemovalTest` (the gate and the media sweep)
and "renders the map page container". Suite: 1882 passed, 1 skipped, and the
same 3 failures `main` has (DigibeeDesignProbe ×2, PipelineLifecycleRun ×1).

### Phase 4b — 2026-11-27 ✅ DONE (the committee canvas)

AS IS / TO BE are uploads, like the C4 slots: the Diagramas tab renders four
upload slots (`data-ak-cati-diagram-pick` / `-upload`), `SubmissionDiagram`
has one collection (`submission_diagram`), `picture()` and `isFilled()` read
it, and the deck's AS IS / TO BE slides lost their link to a canvas.

Removed: the canvas (`chain-viz.js` and its CSS, `components/chain/*`,
`submissions/diagram.blade.php`, `Submissions\DiagramWorkspace`,
`ChainWorkspace`), its contract and helpers (`ChainCanvas`, `EditsChain`,
`ChainGraph`, `ChainLabeler`, `ChainNodeKind`, `Protocol`, `TopologyDiff`), the
thirteen chain requests, `StoreDiagramPictureRequest`, `AuthorizesChainOwner`,
`ValidatesChainNode`, the routes `submissions.diagrams.{edit, layout.save,
picture.store, chain.*}`, the reduced-motion exception for presentation mode,
the lazy loader entry, and the npm packages `html-to-image` and `gif.js`.
`DeleteSolution` is one line now (there is no chain left to sweep); it stays an
Action because two callers delete through it. The upload request authorizes on
its own (`update` on the bound `SubmissionDiagram`).

**Migrations:** `2026_11_27_153112` moves each canvas PNG (`diagram`) into the
upload collection through the `Media` model (same row, same file), deletes it
instead where an upload already existed, deletes the pasted pictures (`docs`),
and LOGS (`CATI canvas removal: drawings with no saved picture were dropped`)
any AS IS / TO BE that had blocks but no PNG. `2026_11_27_153113` (one second
later on purpose, so it sorts after) drops `chain` and `viz_layout`; its
`down()` gives them back empty.

**Rules:** `diagram-chain-canvas.md` and `chain-viz-pointer-events.md` deleted;
`cati-submissions`, `media-and-uploads`, `css-and-docs-rendering`,
`data-ak-attribute-reference`, `slugs-and-routing`, `inline-edit-pattern` (its
stale integration section) and the AGENTS.md Security example updated.

**Tests:** `CatiSubmissionDiagramTest` rewritten around uploads;
`CatiCanvasRemovalTest` covers the move, the kept upload and the pasted
pictures; the canvas-only tests went with the code.

### Review of phases 1–4 — 2026-11-27

Checked against §8 after phase 4b's deploy. Found and fixed: the delete confirm
did not count the manual interconnections (§8.4); the MCP instructions lacked
the sentence on where relationships come from (§8.1); `MediaController` still
authorized committee canvas pictures, a branch with nothing left to serve;
`verify-integration-viz.mjs` was still in the repo root; and a few comments
(flowSpec picker, `Tool`, `Documentable`) plus NOTICE's map attribution still
named the drawings. Production verified through the MCP: `get_solution` returns
the relationships with their evidence, and `search_diagrams` is unknown.

### Phase 5 — 2026-11-27 ✅ DONE (documentation)

README: the intro, the module table, the architecture notes (single source of
truth, Concerns, Actions/Services), the solution delete, the "Diagramas" and
"Diagramas de uma solução" sections (replaced by "Relacionamentos"), the map,
the citation and "Desenhar esta página" passages, the CATI and MCP features, the
canvas technical notes and the test coverage list now describe what exists; the
"Manutenção da documentação" commands gained `documentation:solution-links`.
The CATI phase plans (`docs/cati-fase-{2,3,4}.md`) keep their text as a record,
each with a dated note on what was superseded. AGENTS.md and the rules were
updated with phases 4a and 4b.


### Final review — 2026-11-27

Every name in §8.5 swept from the code, the rules and AGENTS.md; every rule's
`paths:` resolves. One promise left open: the `diagrams` empty-state
illustration, which phase 1 said phase 4 would rename. It is
`empty-relationships` now, used by the card and the showcase. The `/docs` home
page in production still described the canvas: `KnowledgeBaseHomeSeeder` runs
once per environment, so phase 4a's edit of `home.md` never reached it, and the
live page is edited by hand.
