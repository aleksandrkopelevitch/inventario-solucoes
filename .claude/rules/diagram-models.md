---
paths:
  - "app/Enums/DiagramModel.php"
  - "app/Support/Diagrams/**"
  - "app/Services/Documentation/DiagramModelService.php"
  - "app/Services/Documentation/DiagramModelPromptBuilder.php"
  - "app/Actions/Documentation/CreateDiagramFromModel.php"
  - "app/View/Components/Submissions/Diagrams.php"
  - "resources/views/components/submissions/diagrams.blade.php"
---

### The four models, and the line between semantics and geometry

A page can be drawn five ways: the free graph (`ChainDraft`, see
`.claude/rules/page-to-diagram-draft.md`) and the four MODELS — sequence,
lifecycle, data flow, process. All five produce an ordinary `Diagram` on the
canvas. There is no second kind of record, no rendered artifact and nothing
read-only: the first thing anybody does with a generated drawing is drag
something, and that works because there was never anything else to it.

**`ModelSpec` carries semantics; `ModelLayout` owns geometry.** The spec has
not one coordinate in it — who takes part, in which lane, in what order — and
the prompts say so out loud ("NÃO escreva posição, coordenada, largura, altura
ou ordem numérica"). A language model handed a canvas produces coordinates
that look plausible and overlap; handed an order it produces an order. That
line is the reason this works at all, and moving anything across it is how it
stops:

- The position of an item inside its lane is its ORDER OF APPEARANCE there.
  The model already expressed that by listing them, and asking for a row
  number as well would be asking it to agree with itself twice.
- A sequence's lifelines are all the same height. A column that stopped at its
  own last message would read as "this participant left", which the page never
  said.
- The two laned models are one function (`laned()`), because a stage and an
  actor are the same layout seen from two sides — writing them apart is how
  they start behaving differently when somebody drags one.

**The model's name goes in the drawing's NAME** (`DiagramModel::suffixed()`,
applied by `CreateDiagramFromModel`): "Pedido de compra — Sequência". All four
models answer a question about the same page, so generating two of them put two
identically-named rows in the catalog, tellable apart only by opening each. It
is a suffix on the name rather than a column because what comes out is an
ordinary diagram from the moment it exists — somebody renames it, and renames it
out of this shape, like any other. The slug follows from the suffixed name, so
the address says it too, and the helper refuses to apply a suffix a model
already wrote.

**Validation names the offending id**, because its sentences are handed back
for the repair round. The one rule worth knowing: an item filed under a lane
nobody declared is refused, since it would otherwise reach the layout and be
placed at the origin, silently on top of whatever is already there.

### Two node kinds exist because of these models

`ChainNodeKind::Lifeline` is a participant column: its header is ordinary
block content and its body is the empty room the messages cross. It is the
only kind with a size stored in `viz_layout` (`nodes[i].height`), because it
is the only one whose body is defined by what is NOT written in it. It may
reference a Solution — a sequence's participants are the catalog's systems, so
`SyncDiagramFromChain` derives them like any other block. Its category color
goes on the HEADER, never on the column: tinting the box paints a band the
height of the diagram behind the arrows.

`ChainNodeKind::Step` is an activity. It exists because a step written as a
free-text SYSTEM inherited the dashed border the canvas reserves for "outside
Leo" — true of a partner's API, nonsense about "Abre o chamado".

`edges[i].fromT` / `toT` is the other half of the sequence: where along a side
an arrow attaches, as a fraction of the block's height. The eight anchors can
say "on the right"; they cannot say "on the right, at the third step".

### AS IS × TO BE is computed, never stored

`TopologyDiff::between()` is a pure function of two chains, read when the
committee tab is rendered. Nothing is generated and nothing is kept, which is
the point: a comparison that is never stored cannot describe two drawings that
have since moved on, so there is no "gerar de novo" button and no moment where
the panel is lying.

Identity is what makes it right:

- A block is the same block when it names the same **Solution** — whatever
  somebody typed over it — or, for free text, when the labels FOLD to the same
  thing (`Fold::text`, so an accent is not a change).
- Chain INDEX is deliberately never used. `removeNode()` reindexes, so two
  canvases authored separately share no index, and matching on one would
  report every block as added and removed at once.
- A link is identified by the PAIR it joins, not by its direction or protocol.
  Turning an arrow around is a change to a link that already exists, and
  reporting it as a removal plus an addition buries the blocks that really
  came and went.

The panel is withheld until both canvases are FILLED — `isFilled()`, not "has
nodes", because `SubmissionDiagram::open()` seeds a root block and an
untouched canvas would otherwise compare as a drawing of one box.

### What was removed, and why it is not coming back

A vendored Archify sidecar (MIT, tt-a1i/archify) used to render four of these
as self-contained HTML artifacts, stored as media and served under a sandbox
CSP. It was removed once the canvas could draw the same four itself. The
reason is not the dependency: it is that an artifact could not be EDITED. A
diagram somebody cannot drag is a picture of an answer rather than the answer,
and this app already had a canvas.

What survives of it is the credit on the two token themes in
`components/chain/viz.blade.php` — the "Arquitetura" and "Blueprint" palettes
were measured out of one of its artifacts. No code of theirs ships here.
