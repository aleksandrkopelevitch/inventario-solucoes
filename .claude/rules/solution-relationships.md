---
paths:
  - "app/Support/Documentation/UmlSolutionBindings.php"
  - "app/Actions/Documentation/SyncPageSolutionLinks.php"
  - "app/Actions/Documentation/RewriteSolutionBindings.php"
  - "app/Services/SolutionRelationshipService.php"
  - "app/Support/Relationships/Relationship.php"
  - "app/Enums/RelationshipDirection.php"
  - "app/Models/UmlSolutionLink.php"
  - "app/View/Components/Solutions/Relationships.php"
  - "resources/views/components/solutions/relationships.blade.php"
  - "app/Console/Commands/SyncSolutionLinksCommand.php"
  - "tests/Feature/SolutionRelationshipsTest.php"
  - "app/Models/SolutionRelationship.php"
  - "app/Enums/ManualRelationshipDirection.php"
  - "app/Actions/Catalog/SaveManualRelationship.php"
  - "app/Http/Controllers/Inventory/SolutionRelationshipController.php"
  - "app/Http/Requests/StoreSolutionRelationshipRequest.php"
  - "app/Http/Requests/UpdateSolutionRelationshipRequest.php"
  - "app/Mcp/Tools/Write/SetSolutionRelationship.php"
  - "app/Mcp/Tools/Write/RemoveSolutionRelationship.php"
  - "tests/Feature/ManualRelationshipTest.php"
---

### Who talks to whom comes from the documentation's UML (since 2026-11-26)

The spec is `docs-and-specs/solution-relationships-from-uml.md`.

- **Phase 1 is shipped:** bindings, the index, platform inference, the
  "Relacionamentos" card, `/map` and `get_solution.relationships`.
- **Phase 2 is shipped:** manual interconnections, inline and through the MCP.
- **Still to come:** the removal of `/diagrams` (phases 3–4).

**A participant, element or group is bound to a catalog solution by
`"solution": "<slug>"` in the `{% uml %}` JSON, and ONLY that way.** A label
equal to a solution's name binds nothing. EndoFlow's "API" or "Worker" must
never become a relationship because something in the catalog shares the name.
The editor's check only HINTS at such a name ("parece ser a solução…").

The validation is split in two:

- **`UmlDiagram::parse()` checks the binding's SHAPE and nothing else.** It is
  a slug, and the element is one of `UmlDiagram::BINDABLE_KINDS`. It never asks
  the database, so the reader and the preview never wait on a query.
- **`UmlSolutionBindings` resolves the slugs**, in one query per body. An
  unknown slug is treated differently by each caller:
  - The reader draws the diagram and derives nothing.
  - The editor's check (`notebooks.pages.uml`) lists it under `bindings`, kept
    apart from `problems`, which the block words as "a página vai mostrar este
    diagrama como erro". That would be false here.
  - The MCP write is refused (`GitbookRenderer::problems()`), but only for
    blocks that changed.

What counts as a call:

- **Sequence diagrams:**
  - `sync`, `async`, `create` and `destroy` mean `from` calls `to`.
  - `return` is the answer to a call, not a call. Counting it would turn every
    request/response pair into "mútua".
  - A gate has only one end on a participant, so it never counts.
- **Structural diagrams:**
  - `directed`, `dependency` and `flow` mean `from` calls `to`.
  - `association` is a link with NO direction ("sem sentido definido"), drawn
    on `/map` with no arrowhead.
  - Every other edge kind states structure, not communication.
- **Groups:** an element with no binding of its own takes the innermost bound
  group's.

**`uml_solution_links` is evidence, not pairs.** It holds one row per
call/association, keyed by page, `diagram_position` and `ordinal`.

- **Rebuilt per page, synchronously.** `DocumentationPage::booted()` re-syncs
  on `created`, and on `updated` when `documentation` changed.
- **Use those two events, not `saved` + `wasRecentlyCreated`.** That flag stays
  true for the instance's whole life, so every later save of a page created in
  the same request would re-sync. The test caught exactly that.
- **Cascades and the repair tool.** Rows cascade with the page and with either
  solution. `documentation:solution-links` rebuilds everything, and is the
  repair tool.

Pairs are folded ON READ by `SolutionRelationshipService`:

- **Calls both ways:** "mútua".
- **Calls one way:** a direction.
- **Only associations:** undirected.
- **Through a platform**, a solution of category `ipaas` (Digibee, SAP CPI). The
  end-to-end pair is inferred, "via" that platform, and the direct pairs to the
  platform stay. The inference has three rules:
  - **Inside ONE diagram, never across two.** Digibee connects dozens of
    systems, and joining unrelated pages through it would invent integrations.
  - **In a sequence, the ORDER decides.** X → P, then P's next outgoing call
    before any other caller reaches P gives X → Y. Up to two platforms can sit
    in the middle (`MAX_PLATFORM_HOPS`).
  - **In a structural diagram, only an unambiguous fan-in or fan-out counts.**
    A hub drawn with several callers and several callees infers nothing.
- **At read time, on purpose:** changing a solution's category changes the
  answer at once, with no page to re-sync.
- **Never drawn on `/map`:** via pairs are excluded from `pairs()`.

**The slug is the address, so both ends of a solution's life reach the text.**

- **A rename:** `SaveSolution` calls `RewriteSolutionBindings`, which rewrites
  `"solution": "<old>"` inside `{% uml %}` blocks only. It works by regex and
  never decodes and re-encodes the JSON, which is stored verbatim (doc-uml.md).
- **A create:** `SyncPageSolutionLinks::forPagesNaming()` picks up the pages
  that named the slug before it existed.

**The "Relacionamentos" card** (`Solutions\Relationships`, slot
`solution-relationships-slot`) replaced the solution page's "Diagramas" column.

- THIS solution is always on the left of each row, so "──▶" always reads "esta
  chama a outra".
- The arrow's words are its `title` and an `sr-only` sentence.
- **It is a catalog fact**, shown to everybody who sees the solution. Only the
  "Onde está documentado" links into cadernos are gated (`viewAny` on Notebook).
  The disclosure is a native `<details>`, so it needs no hook.
- **The evidence links point at `#uml-figure-{n}`**, the figure's id. They
  scroll to the figure on the READ view (`/docs`, a reader without edit
  rights). The editor builds its blocks in Editor.js, where no such id exists
  yet.
- **`GitbookRenderer` numbers the figures** in document order (`$umlCount`,
  reset per `render()`), valid or not. That is the same count
  `UmlSolutionBindings` stores as `diagram_position`. Change one and the other
  must follow.

**MCP:**

- `get_solution.relationships` prints PT-BR labels ("chama", "é chamada por",
  "mútua", "sem sentido definido") and `via`.
- `evidence` is dropped without the documentation module.
- An unpublished caderno's evidence goes only to a writing connection, marked
  `"published": false`. The relationship itself goes to every caller.
- The guide (`code-documentation-guide.md` § "Vínculo com o catálogo") teaches
  the binding, and `DocumentationUmlTest` parses its example and checks its
  slugs against a seeded catalog.

**In tests, give every solution a `category`.** `SolutionFactory` picks one at
random, and `ipaas` is among them, so an unlucky factory row turns into a
platform and changes what is inferred.

### Manual interconnections (phase 2, since 2026-11-26)

A person may RECORD that two solutions talk (`solution_relationships`,
`SolutionRelationship`), on the card or through the MCP.

**One record per pair, stored normalized.**

- `solution_a_id` is the lower id, and `direction` (`a_to_b`, `b_to_a`, `both`)
  is read from A.
- A plain unique index then holds whichever side it was entered from;
  `LEAST/GREATEST` would be spelled differently by SQLite and Postgres.
- Screens and tools speak `RelationshipDirection` from the asking solution's
  side. `ManualRelationshipDirection::fromSide()` / `asSeenFrom()` translate.
- There is no "undirected" option: the person asked for left, right or both.
- `created_by_id`/`updated_by_id` come from `booted()`, never from a payload.

**A record WINS for its pair.**

- `SolutionRelationshipService::overlay()` lays the records over the documented
  pairs. A record replaces the direction, and keeps what the documentation said
  as `Relationship::$documented`.
- `source()` is `documented` | `manual` | `adjusted`. The card says "manual" or
  "ajustado manualmente" + documentado: “…”, under the row, never beside the
  name, where a badge ate the room the name and the open editor need.
- Removing an override brings the documentation back. A documented-only pair
  has no ✕: it is removed by editing the diagram.

**The card, inline** (`.claude/rules/inline-edit-pattern.md`):

- **"+ Adicionar interconexão"** is a creator. It offers every other solution
  except the ones that already have a record.
- **The pencil on a manual row** edits both fields (re-pointing included),
  `PATCH solutions.relationships.update`.
- **The pencil on a documented row** edits the direction only, and records the
  override with `PUT solutions.relationships.override?other_solution_id=…`.
  It is a PUT, not a POST, because `x-ui.inline-edit` turns any POST into a
  "+ Adicionar" creator chip.
- **The ✕** is `x-ui.row-remove` on a row that has a record.
- **Every response** is the card's slot.
- **`{relationship}` is resolved by `Solution::resolveChildRouteBinding()`**:
  the record lives in two columns, so no relation can scope it. A record that
  does not involve the URL's solution is a 404.

**The direction options say "esta"** ("→ esta chama a outra"), not the name: a
long name cut the sentence inside the 13rem select. The helper that builds them
is `directionChoices()`, because a class component's public method named like a
view key replaces that key (`.claude/rules/blade-compiler-traps.md`). Every
select once rendered empty because of it.

**MCP: `set_solution_relationship` / `remove_solution_relationship`.** Both are
catalog-module write tools, `SolutionWriteTool`s.

- **Directions:** "chama", "é chamada por", "mútua", read from `solution`'s
  side.
- **Set** is idempotent, and when it contradicts the documentation it says so
  ("A documentação dizia outra coisa…").
- **Remove** refuses a documented-only pair, naming the page and diagram to
  edit instead.
- **The log:** both write `mcp_writes` as the solution's `relationships` set
  (`RecordFields`, the same shape as `people`), so the existing revert re-syncs
  the set.
- **The revert caveat:** a revert re-validates the solution's own columns
  (`RevertMcpWrite::invalidRestore()`), so a test reverting one needs the
  attribute vocabulary seeded.
- **`get_solution.relationships[]`** carries `source` ("documentado",
  "cadastrado", "cadastro sobre a documentação"), `documented_direction` and
  `recorded_by`.
