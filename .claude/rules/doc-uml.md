---
paths:
  - "app/Support/Documentation/UmlDiagram.php"
  - "resources/js/modules/docs-uml.js"
  - "resources/js/modules/docs-tools/uml.js"
  - "app/Mcp/Tools/GetCodeDocumentationGuide.php"
  - "resources/data/mcp/code-documentation-guide.md"
  - "tests/Feature/DocumentationUmlTest.php"
---

### UML diagrams in a page — the author places, the code draws (since 2026-11-24)

`{% uml type="…" title="…" %}` + a JSON body + `{% enduml %}` is the eighth
construct of the dialect: a static UML diagram drawn INTO a page, in UML
notation (the conceptdraw design elements the user handed over as the
reference: lifelines with actor/boundary/control/entity heads, filled vs open
arrowheads, dashed returns, `alt`/`loop` frames with the pentagon tag, class
boxes with compartments, the hollow triangle, the diamonds, the folded note).
Eight types (`UmlDiagram::TYPES`): `sequence` and seven structural ones that
share one grid layout. It is NOT the topology `Diagram` (the canvas, a
`{% diagram slug %}` citation): it lives in the page's text, has no record of
its own, and cannot be moved by anybody — it is redrawn from the text.

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
  `uml:{title}` (its type when untitled), beside `diagram:{slug}` and
  `file:{…}`. The Assiste IA freezes the block whole (`BlockVault`, "diagrama
  UML"), like a scene.

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

**The guide teaches only what the validator accepts.** The playbook and the
syntax a model reads live in `resources/data/mcp/code-documentation-guide.md`
(served by `get_code_documentation_guide`, pointed at from `initialize`), and
`DocumentationUmlTest` parses its JSON and scene examples — a guide whose
example the server refuses teaches a model to fail.

Checking a layout change without the app: `renderUml(diagram, measurer())`
is pure (it returns the SVG and its size; outside a browser `measurer()`
approximates widths), so a node script can render it and `sharp` (a dev
dependency) can turn it into a PNG to look at.
