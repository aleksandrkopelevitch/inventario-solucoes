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
  - "app/Policies/DiagramPolicy.php"
  - "resources/js/modules/chain-viz.js"
  - "resources/js/modules/chain-select.js"
  - "resources/views/components/chain/**"
  - "resources/views/diagrams/**"
---

### Diagram topology invariant — the chain is the single source of truth

A `Diagram` is a drawing of a flow, and a first-class record: `/diagrams` is its
module, `/diagrams/{diagram}` is the canvas that authors it.

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
