---
paths:
  - "app/Models/Diagram.php"
  - "app/Actions/SetDiagramSystems.php"
  - "app/Http/Requests/SyncDiagramSystemsRequest.php"
  - "app/View/Components/Diagrams/**"
  - "app/Models/SubmissionDiagram.php"
  - "app/Contracts/ChainCanvas.php"
  - "app/Enums/ChainNodeKind.php"
  - "app/Actions/SyncDiagramFromChain.php"
  - "app/Http/Controllers/Concerns/EditsChain.php"
  - "app/Http/Controllers/DiagramController.php"
  - "app/Http/Controllers/DiagramPictureController.php"
  - "app/Http/Controllers/SubmissionDiagramController.php"
  - "app/Http/Requests/*Chain*.php"
  - "app/Support/ChainGraph.php"
  - "app/Policies/DiagramPolicy.php"
  - "resources/js/modules/chain-viz.js"
  - "resources/js/modules/chain-select.js"
  - "resources/views/components/chain/**"
  - "resources/views/diagrams/**"
---

### Diagram topology invariant — the chain is the single source of truth

A `Diagram` is a drawing of a flow, and a first-class record: `/diagrams` is its
module, `/diagrams/{diagram}` is the canvas that authors it. It **belongs to a
caderno** (`notebook_id`, required, not fillable — written through
`WriteNotebookDiagram`), and is created only from inside one; see
`.claude/rules/page-to-diagram-draft.md`. It is still addressed by its own slug,
because that is what a `{% diagram %}` citation names.

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
the text's height happens to be. Shape carries the kind (solid orange diamond =
decision, dashed border = external free text), and only the two flow terminals
keep a fill of their own, green/red.

**The default look IS a reference profile, measured, not invented.** Since
2026-10-03 the "Original" theme reproduces the "Login e cadastro VTEX" boards
(IBM Plex Sans 15/20, `#F4F6F8` ground, `#5A6675` arrows, `#B9C2CE` hairline,
`#F6C453` diamond, `#17212B` pill) — the values sit as `--viz-*` tokens on
`[data-ak-chain-viz]`, and the other five themes stay as alternatives. The
SIZES are fixed, in the board's proportion: an action card and the pill are
160×76, a decision 160×90, and a long text wraps and grows the block taller,
never wider (208px only with a picture beside the text). Those are the board's
measured 200×96 / 200×112 / 260 scaled down by a fifth on 2026-10-10 — at full
size the user found the blocks too big, mostly empty room around a line or two.
`ModelLayout` centres blocks in their lanes by these numbers, so a change here is
a change there. Content-sized blocks were the first version, and the user
rejected it: "Aprovado" came out a 150×60 chip. An
ACTION block (`system`/`step`, `chain-viz.js::isActionKind()`) is the only kind
that takes any of the author's styling, and all of it is `viz_layout`, never
the chain:

- `tone` — `white` (none) / `blue` / `red` / `green` / `orange`, fill + 2px
  border + ink chosen together (`SaveChainLayoutRequest::TONES`). It replaced
  the free `color`/`textColor` pair, which the request no longer validates, so
  a stale value is dropped on the next save; the client maps the old palette's
  pastels to the nearest tone (`LEGACY_TONES`) rather than turning them white.
- `pill` — the dark rounded shape. A SHAPE, not a kind, and dark whatever the
  tone: `applyNodeStyle()` never writes `data-tone` alongside `is-pill`, and
  picking a tone takes the block out of the pill.
- `imageMode` — `left` (picture beside the text) or `top` (the box disappears
  and the block is the picture with its text underneath, in `--viz-caption`,
  which a dark theme turns white). It replaced `logoOnly`, still read as `top`.

The picture itself is CONTENT and lives in the chain: `chain.nodes[i].media_id`,
written by `setChainNodeImage()`/`removeChainNodeImage()` (pasted with the block
selected, or the toolbar's "Imagem"), refused on a kind that is not
`ChainNodeKind::acceptsImage()`, and carried across `updateChainNode()` so a
rename does not lose it. A replaced or removed picture is deleted, it belonged
to that block alone. With no picture of its own, a block naming a Solution shows
the catalog logo in the same slot.

**Measure after the font, not before.** Every block is sized by its text, and
on a first visit the text is set in the fallback until IBM Plex arrives —
`render()` measures again on `document.fonts.ready`, or the arrows stay anchored
to (and routed around) boxes that no longer exist.

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
`Concerns\EditsChain` calls for every one of the twelve endpoints. The ecosystem
map is a reading of those columns, which is what makes it a reading of the
drawings rather than a second truth. `Diagram.viz_layout`
(`{nodes: [{x,y}], edges: [{from,to}], comments}`) is a purely **visual**
concern — node position/style and per-block comments in the graphical canvas
(`resources/js/modules/chain-viz.js`) — and must NEVER drive topology;
`saveLayout()` writes only `viz_layout`, never touching `chain` or the derived
columns. Don't write the derived columns directly — edit `chain` and let the
action re-derive.

#### `diagram_solution` has a second writer — and only a second

`diagram_solution.manual` says who wrote a participant row. The rows at `false`
are the derived ones above and `SyncDiagramFromChain` still owns them whole: it
detaches and rebuilds exactly those on every mutation. The rows at `true` are
written by `App\Actions\SetDiagramSystems` alone
(`PATCH diagrams/{diagram}/systems`, `x-diagrams.systems` in the page's top
bar), and they exist because **a drawing's systems are not always blocks in
it**: a generated process or data flow is lanes and neutral steps, so its chain
named no solution and the drawing reached neither the ecosystem map nor any
solution's page.

Three things keep the two halves from contradicting each other, and each one is
load-bearing:

- **Neither writer touches the other's rows.** `SyncDiagramFromChain` scopes its
  detach with `wherePivot('manual', false)`; `SetDiagramSystems` scopes its own
  with `true`. A plain `detach()`/`sync()` on `participants` from either side
  silently deletes the other half — which for the derivation means a declared
  set that survived only until the next block was dragged.
- **Drawn beats declared.** A system that gains a `system` block loses its
  manual row (the derivation detaches it by id before attaching), and
  `SetDiagramSystems` filters a solution already in the chain out of the set it
  is handed rather than refusing it. Otherwise the same system is in
  `participants` twice, and the pivot's unique `(diagram, solution, position)`
  eventually collides.
- **`source`/`target`/`direction` stay derived from the chain alone.** Those
  describe the FLOW, and a declared system has no edge to read a direction from.
  The manual rows sit at `SetDiagramSystems::POSITION_BASE` and above precisely
  so they sort after everything the chain contributes.

The ecosystem map still draws an EDGE only between two systems joined by a chain
edge whose both ends resolve to a solution (`DiagramGraphService` reads the
chain for that, not the pivot), so a declared system appears as a node the
drawing touches and never as a relationship nobody drew.

The panel showing this lists BOTH halves and labels them, because the
difference is the only thing that explains why one can be removed there and the
other cannot — a drawn system is unlinked by editing its block. Showing only the
declared ones would read as the complete list of a diagram's systems while
being a fraction of it.

**Adding a block must not change the zoom.** `appendNode()` used to end with
`fit()`, which recomputes `view.scale` — so drawing a ten-block flow meant ten
scale jumps and threw away the zoom the person had chosen to work at. It calls
`panIntoView()` instead: the minimum pan that brings the new block into the
viewport, scale untouched, and nothing at all when it was already visible. Only
"Centralizar" and the initial load re-frame. (Do not confuse
`panIntoView()` with `revealNode()` in the same file — that one is presentation
mode's fade-in. The two names collided in the first version of this and the
second declaration silently won.) There used to be an "Organizar" button that
reset every block to a left-to-right row; the user had it removed on 2026-10-10,
since on any real drawing it threw the author's layout away.

**The canvas is owner-agnostic, and there are two owners.**
`App\Contracts\ChainCanvas` is the contract; `Concerns\EditsChain` performs
all twelve mutations against anything implementing it. `Diagram` re-derives its
columns in `afterChainMutation()`; a `SubmissionDiagram` (a proposal's AS IS /
TO BE) derives nothing, deliberately. The client never learns which it is
editing, because every endpoint it calls arrives inside the graph payload
(`ChainCanvas::chainUrls()`) — which is why `chain-viz.js` contains no route of
its own and must keep containing none.

**An arrow is routed around its two blocks, never behind them.**
`orthogonalPoints()` in `chain-viz.js` used to pick a route from the two ends'
orientation alone, which is right only while the destination lies ahead of the
face the arrow leaves from. Drag a block past the one its right-hand arrow
points down to and the single elbow at `(s3.x, s0.y)` turned back, ran the
width of the block BEHIND it and came out of its underside — the stroke and the
wide hit target used to grab it both hidden under the card. `routeBetween()`
now takes the two blocks' boxes (`boxOf()`), keeps the classic route whenever
it is clear (so drawings that already looked right do not move), and otherwise
picks the cheapest orthogonal route — fewest bends, then shortest — that
neither doubles back from `s0`, leaves `s3`'s face, nor crosses a box.
Corridors are tried in the GAP between the blocks first, which is the route a
person would draw. A lifeline is never passed as a box: its anchor sits on the
dashed line inside it by design. The corridor fan-out (`corridorOffsets()`)
reads the corridor of the route actually drawn, and a detour offset that would
run into a block is dropped rather than applied.

**Undo is a stack of whole STATES, and half of it is server-side.**
Ctrl+Z / Ctrl+Y (Ctrl+Shift+Z; ⌘ on a Mac) step through `{chain, layout}`
snapshots (`chain-viz.js::stepHistory()`), not through operations with an
inverse each. The layout is local until "Salvar", so a step between two states
with the same chain is a local redraw. The chain is written the moment it
changes, so a step across a chain change PUTs the whole state back
(`restoreUrl` → `EditsChain::restoreChain()`, `RestoreChainRequest`) — undoing a
deleted block only on screen would leave the database without it. Three things
hold it together:

- **Every mutation answers with the stored `chain`** (`EditsChain::answer()`),
  and so does the graph payload. The resolved graph the canvas draws from has
  labels and URLs where the chain has ids and cannot be turned back into one.
  The mutating fetches all go through `chainFetch()`, which hands that chain to
  the history; a new endpoint that skips it is a mutation Ctrl+Z cannot see.
- **A state is recorded only when CONSISTENT** — as many layout entries as the
  chain has nodes and edges. Between a mutation's answer and the redraw the two
  disagree, and restoring such a state would hand one block's position to
  another; `RestoreChainRequest` refuses one for the same reason.
- **The restore trusts the payload's shape, not its content**: the root node is
  kept as stored, and a `media_id` that is not one of this owner's pictures is
  dropped. A picture removed from a block was DELETED with it, so undoing that
  brings the block back without its picture — a known limit, not a bug.

**A link sits above a lane, and the lane's thin parts win over the link.** The
hit layer (`.ak-viz-hits`) used to sit at `z-index: -1`, behind the lanes, so an
arrow inside a lane could not be clicked at all — the press grabbed the lane.
It now sits in plain DOM order above the (prepended) lanes and below the blocks;
where a 16px hit target covers a lane's resize strip or title strip, its
`pointerdown` hands the gesture to what it covers (`laneChromeUnder()`). Lanes
resize from all four edges and corners; a top/left handle moves that edge and
the opposite one stays put.

**An arrow's label can be dragged along it**, stored as
`viz_layout.edges[i].labelT` — a fraction of the route's LENGTH, so it keeps its
place on the arrow when a block moves and the route changes shape. Null means
the default (middle of the longest straight run). `spreadProtocolPills()` never
pushes a placed label; it only steps the others around it.
