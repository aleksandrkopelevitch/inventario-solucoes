---
paths:
  - "app/Support/Documentation/UmlDiagram.php"
  - "resources/js/modules/docs-uml.js"
  - "resources/js/modules/docs-tools/uml.js"
  - "app/Mcp/Tools/GetCodeDocumentationGuide.php"
  - "app/Http/Controllers/NotebookPageUmlController.php"
  - "app/Http/Requests/CheckPageUmlRequest.php"
  - "resources/data/mcp/code-documentation-guide.md"
  - "tests/Feature/DocumentationUmlTest.php"
---

### UML diagrams in a page — the author places, the code draws (since 2026-11-24)

`{% uml type="…" title="…" %}` + a JSON body + `{% enduml %}` is a construct
of the dialect of our own: a static UML diagram drawn INTO a page, in UML
notation (the conceptdraw design elements the user handed over as the
reference: lifelines with actor/boundary/control/entity heads, filled vs open
arrowheads, dashed returns, `alt`/`loop` frames with the pentagon tag, class
boxes with compartments, the hollow triangle, the diamonds, the folded note).
Eight types (`UmlDiagram::TYPES`): `sequence` and seven structural ones that
share one grid layout. It lives in the page's text, has no record of its own,
and cannot be moved by anybody — it is redrawn from the text. (It replaced the
catalog's topology drawings, removed on 2026-11-27.)

**It exists so a model can write architecture documentation through the MCP
server** (`get_code_documentation_guide` → `create_page`), and Mermaid was the
thing to replace: this app never rendered it, so every "diagram" a model wrote
arrived as a grey code block.

The split is the scenes' split (doc-scenes.md), with ONE difference:

- **The author decides what and WHERE.** For a sequence the order IS the
  layout (participants are columns in the order given, messages rows); for a
  structural diagram each element names its grid cell, `at: [col, row]`. A
  class diagram's layout is part of what it says — the parent above its
  children, the whole beside its parts — so it is the author's, not an
  auto-layout's guess. A scene's model never sends a coordinate; this one does,
  but only a CELL.
- **`docs-uml.js` decides HOW**: every size from measured text, every shape,
  dash and arrowhead, edge clipping at each outline, label placement off the
  line. A model asked for SVG varies per call and hands the page markup that
  could carry a script — the same reason as the scenes.

Five things that are easy to undo:

- **`UmlDiagram::parse()` is the ONE validator**, and both doors use it:
  `GitbookRenderer::renderUml()` (a valid diagram → the figure; an invalid one
  → `.ak-uml-error` listing EVERY reason, never silence — a figure that
  vanished would read as an unfinished page) and the MCP write tools through
  `GitbookRenderer::problems()`, which refuse the body with the same sentences.
  Paths in those sentences are JSON paths counted from 1
  (`messages[3].else[1].messages[2]`), because that is what the author has to
  find.
- **Layout mistakes are REFUSED, not drawn**: two elements in one cell, a group
  (`package`/`boundary`/`node`/`frame`) whose bounding box swallows an element
  that is not its member, two groups that half-overlap. A group is drawn as the
  box around its members' cells, so each of those would draw a lie.
- **The author's JSON is stored VERBATIM.** `docs-markdown.js` carries the body
  as `source` and writes it back untouched, so opening a page and saving it never
  reformats somebody's diagram. The reader is handed the NORMALIZED diagram
  (`data-ak-uml`); the editor's preview hands `mountUml()` the raw JSON, which is
  why `docs-uml.js` normalizes too (`normalize()`: defaults, and a fragment's
  `label`/`messages`/`else` into `operands`). Forget that and a fragment the
  author just typed draws nothing in the editor while the page renders it fine.
- **Scenes and diagrams are validated on WRITE, but only the ones that
  changed.** `problems($new, $previous)` skips a block present byte-for-byte in
  the stored body, so a model editing one paragraph is never refused over a
  hand-made figure it did not touch. Scenes are held to their model draft's
  limits (`StepScene::validate()` and friends; the tree's on the flat outline).
  A scene's body is on its OWN line — `{% step title="…" %}` / detail /
  `{% endstep %}`; on one line the renderer ignores it, which is exactly the
  mistake the guide used to teach.
- **Dropping a diagram is a loss to confirm.** `update_page`'s guard names it
  `uml:{title}` (its type when untitled), beside `file:{…}` and
  `shrink:N%`. The Assiste IA freezes the block whole (`BlockVault`, "diagrama
  UML"), like a scene.

**Three things the first review found (2026-11-24), each fixed and each easy to undo:**

- **Every figure owns its gradients.** The ids are `ak-uml-{n}-fill/-head/-note`,
  and the rules that paint with them are scoped to the figure's `<svg id>`
  (`figureStyle()`). With one shared `uml-fill`, every `url(#uml-fill)` on the
  page resolved to the FIRST declaration, and when that figure sat in a hidden
  tab Chrome painted every other diagram's boxes with no fill — verified in
  headless Chrome before and after. Never move those three rules back into the
  shared, unscoped part of the style.
- **A relation drawn through another element is refused on write, drawn on
  read.** `UmlDiagram::$warnings` (judged in grid units, on a cell's 0.4 core
  only, because it refuses a write and a false alarm costs more than a miss)
  joins `problems()` for MCP writes; the reader never turns a stored diagram
  into an error box over it. The editor's preview, which knows every box's real
  size, reports the exact crossings (`renderUml().warnings`) under the block.
  The first EndoFlow documentation shipped two such crossings that a node-side
  PNG render (approximate text widths) did not show — check a layout with the
  browser's own metrics, not with `sharp`.
- **Edge names slide along their line** until they land on neither a box nor
  another label (end labels — multiplicity, role — are placed first and never
  move). With no free spot the name keeps the middle.
- **A preview that cannot be drawn throws**, and the editor's block prints the
  message: no participants/elements, or anything that produced a non-finite
  size or a `NaN`. Messages, notes and refs pointing at unknown participants
  are skipped, so a half-typed JSON still previews what it can.
- **Two diagrams with the same label are two loss items** (`uml:{label}#2`):
  deduplicated, dropping one of a pair passed unconfirmed.

**The second review (2026-11-25) closed the rest of the reference palette and two
ways of being surprised:**

- **The editor asks the server.** The preview draws leniently (an element with
  no `at`, an unknown kind, two elements in one cell), so the "Diagrama UML"
  block POSTs its JSON to `notebooks.pages.uml` (NotebookPageUmlController,
  CheckPageUmlRequest, `update` on the caderno) 700 ms after the author stops
  typing and prints the problems the page would show. Only the last question's
  answer is used. Keep it the ONE validator: never mirror UmlDiagram in JS.
- **A figure inside a `{% hint %}` is refused on write.** The reader draws it
  (a hint's body goes through renderLines()), but the editor's hint is inline
  text, so the author sees raw JSON there. Tested 2026-11-25: saving and typing
  in such a hint preserve the block, so nothing stored is damaged — the refusal
  only stops new ones.
- **Every element draws its `stereotype`** (note, actor, usecase, lollipop and
  decision used to accept and drop it).
- **The palette now has** `gate` (a message end on the frame, a small square)
  and `state` (a state invariant on one lifeline, a continuation over several)
  in sequences; `port`, `nary`, `text`, `divider`, `template` (class, abstract,
  interface only) and the `callout` relation in grids; and `from_role`/`to_role`
  beside the multiplicity, on the other side of the line.

**An element may be BOUND to a catalog solution** (`"solution": "<slug>"`,
since 2026-11-26): that is where the solution pages' "Relacionamentos" and the
`/map` arrows come from. `parse()` checks only the shape (a slug, on a
`BINDABLE_KINDS` element or a group) and stays free of the database; whether
the slug exists, and what the diagram then says about who calls whom, is
`.claude/rules/solution-relationships.md`. The figure carries
`id="uml-figure-{n}"` (document order, counted by the renderer) so a
relationship's evidence can link straight to it.

**Inline it is a picture; fullscreen is the way to read a big one.** The SVG
shrinks to the column but never below 60% of its natural width (the stage
scrolls past that — labels stop being legible). The figure carries
`.ak-uml-toggle` buttons ("Tela cheia", and inside it "Tamanho real" /
"Ajustar à tela"): native `requestFullscreen()` on the stage where the browser
allows it on an element, a fixed `is-expanded` overlay where it does not
(iPhone Safari, headless Chrome — which is how the overlay path was verified).
The stage is NOT positioned inline, so the buttons sit on the figure's corner
and never scroll away with a wide diagram; in fullscreen the stage is fixed and
they sit on the screen's corner. Fit vs 1:1 uses `margin: auto` on the SVG, not
`justify-content: center`, which makes the left edge of an overflowing diagram
unreachable.

**In the editor the JSON is out of sight (since 2026-11-27).** The "Diagrama
UML" block shows its type, its title and the diagram; the JSON opens in
`#main-modal` from a "Código" `.ak-uml-toggle` beside "Tela cheia" (hidden in
fullscreen), via `mountUml(stage, diagram, {onCode})` — the reader passes no
`onCode` and gets no such button. Typing there edits the block as the inline
field did (data, `dispatchChange()`, the preview redrawing behind the dialog),
and the status line is mirrored under the field. Two things that are easy to
break:

- **The click that opens it is what makes the typing an edit.** The dialog is
  outside `[data-ak-docs-editor]`, so docs-editor.js's `userEdited` never sees
  its keystrokes; the trusted click on "Código" (a button inside the editor
  that is not text) is what arms autosave. A synthetic `.click()` does not, so
  a browser test must click through CDP `Input.dispatchMouseEvent`.
- **A diagram that cannot be drawn keeps a door to its code**
  (`.ak-uml-tool__code`, "Abrir o código do diagrama"): the toggles go with the
  picture, and the JSON is the only way to fix it.

**A bound element wears its solution's logo** as a 24 px badge centred on its
top-left corner (a package group's tab, a `node`'s box under the 3D top). A
group's left-anchored label moves past it. The logos are catalog state, so they
are NOT in the parse: `renderUml()` writes only the slugs
(`data-ak-uml-bound`), and `GitbookRenderer::resolveUmlLogos()` swaps them,
per render and in one query, for `data-ak-uml-logos` (`{slug: url}`, the
`PublicImage` thumbnail). Put them inside the parse and a changed logo waits 30
days for the cache. The editor's check answers the same map (`logos`) and the
block redraws once with it. A slug with no logo, or no solution, draws no badge.

**The guide teaches only what the validator accepts.** The playbook and the
syntax a model reads live in `resources/data/mcp/code-documentation-guide.md`
(served by `get_code_documentation_guide`, pointed at from `initialize`), and
`DocumentationUmlTest` parses its JSON and scene examples — a guide whose
example the server refuses teaches a model to fail.

Checking a layout change without the app: `renderUml(diagram, measurer())`
is pure (it returns the SVG and its size; outside a browser `measurer()`
approximates widths), so a node script can render it and `sharp` (a dev
dependency) can turn it into a PNG to look at.
