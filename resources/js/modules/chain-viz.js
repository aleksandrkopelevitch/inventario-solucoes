import { toCanvas, getFontEmbedCSS } from 'html-to-image'
import GIF from 'gif.js'
import gifWorkerUrl from 'gif.js/dist/gif.worker.js?url'
import { setButtonLoading } from './button-loading'
import { fold } from './fold.js'

// `gifWorkerUrl` (Vite's `?url` import) resolves to a URL on the DEV SERVER's
// own origin (e.g. `https://host:5174/...`) whenever Vite is running in dev
// mode, which is a DIFFERENT origin than the page itself (`https://host`, no
// port) even on the same hostname — `new Worker(url)` enforces same-origin
// unconditionally (unlike `fetch()`, this isn't CORS-negotiable), so passing
// `gifWorkerUrl` straight to `GIF`'s `workerScript` option throws
// `SecurityError: Failed to construct 'Worker'` every single time in dev
// (confirmed 2026-08-04 — exactly the "Não foi possível gerar o vídeo."
// report). Fetching the script's TEXT first and handing the worker a
// `Blob`/`URL.createObjectURL()` of it sidesteps the restriction entirely — a
// blob: URL is always same-origin-safe to build a Worker from — and this
// works identically in production (same-origin build, the extra fetch is
// just redundant but harmless), so it's a real fix, not a dev-only patch.
// Memoized at module level (not per mount()) since the script's content is
// static for the page's lifetime and every diagram instance would resolve
// the exact same URL; the blob URL is intentionally never revoked — it needs
// to keep working for as long as the page can still open the export menu.
let gifWorkerBlobUrl = null
async function resolveGifWorkerUrl() {
    if (gifWorkerBlobUrl) return gifWorkerBlobUrl
    const res = await fetch(gifWorkerUrl)
    const text = await res.text()
    gifWorkerBlobUrl = URL.createObjectURL(new Blob([text], { type: 'application/javascript' }))
    return gifWorkerBlobUrl
}

// The integration's graphical view — the "Diagrama" tab of the integration's
// unified page (Diagrams\Workspace; the page also has a "Documentação" tab).
// It draws the integration's chain (`chain`) as a graph: nodes joined by arrows
// whose direction follows the segment (`->` forward, `<-` back, `<->` both) and
// whose label is the protocol. Every node has a KIND (`kind`, see
// `App\Enums\ChainNodeKind`): `system` (a registered solution or an external
// system as free text), `decision` (a fork in the flow, drawn as a chamfered
// hexagon), `actor` (a person or an area, drawn as a rounded badge with an
// icon) or `start`/`end` (the beginning and the end of the flow, drawn as a
// solid-coloured circle — green/red — with the kind's icon inside and the label
// written BELOW the circle rather than beside it). The kind arrives resolved in
// the graph (`nodes[i].kind` plus `nodes[i].icon`, SVG already rendered on the
// server); nodes saved before kinds existed arrive as `system`.
//
// Nodes are <div>s with a shadow, edges are SVG, both inside a #world carrying
// a transform. The data arrives resolved in the `data-ak-chain-graph` of the
// single (hidden, auto-selected) row that `diagrams/workspace.blade.php`
// renders (`chain-select.js` emits `ak:diagram-selected`). Nodes referencing a
// solution also carry `logo`, `environment` and `cloud` (label plus an icon SVG
// already rendered on the server) — shown as an avatar and discreet chips on
// top of the block.
//
// Two layers are purely visual (`viz_layout`, never `chain`): a block's border
// and an arrow can each be marked dashed independently of the other (an icon
// button in each toolbar — never a checkbox), and lanes (`lanes`) — free,
// coloured background rectangles with a full-height vertical label on the left
// edge, in a darker shade so it stands out (`darkenHex()`) — mark an area of
// the flow. A lane never references a node; the user simply drags the blocks
// into the area they want, as they already do anywhere on the canvas. The
// topbar's "Raias" button creates one on the spot (centred on the current
// viewport, with no panel or dialog in the way) and selects it. Position and
// size are edited on the canvas itself (`rebuildLanes()`): dragging the lane's
// BODY (label included) moves it (`drag.type === 'lane-move'`), dragging one of
// its 3 handles resizes it — right (width only), bottom (height only) or the
// corner (both, `drag.type === 'lane-resize'`). Only a click WITHOUT a drag ON
// THE LABEL (`drag.onLabel`) opens the colour/name/remove toolbar
// (`selectLane()`) — clicking without dragging anywhere else on the body does
// nothing, on purpose: the dark label is the only selection target.
//
// Clicking (without dragging) a node selects it and opens the contextual
// toolbar (title / comment / open solution) in a floating panel pinned to the
// canvas's top-left corner — excalidraw.com style, never anchored to the block
// — which disappears as soon as the block is deselected. It works with or
// without `editable`. Editing position (when `editable`): drag a block to
// reposition it; drag an arrow end's handle to stick it to one of the node's 8
// anchors (4 main ones plus 2 on the top and 2 on the bottom). "Organizar"
// recomputes the default left-to-right layout. The "Salvar" button persists
// layout, anchors and comments (`viz_layout`) on the server — it is
// presentation only and never touches the topology.
//
// The KIND of an existing block (unavailable on the root node, index 0) is
// changed on the toolbar's SECOND ROW — icons only
// (`refreshKindRow()`/`changeNodeKind()`, from `[data-ak-node-kinds]`), applied
// immediately (a PATCH to `graph.nodeUpdateUrl`, with no separate "Salvar" —
// the server re-derives participants/source/target/direction and answers
// already resolved, see `Solutions\ChainGraph::resolveNode`). A block's
// text/solution is edited on the SHAPE itself, by double click
// (`startInlineLabelEdit()`): on a `system` block that is also the solution
// search (inline autocomplete); decision/actor/start/end are free text only,
// start and end with a default of their own ("Início"/"Fim") that the server
// fills in when they are left blank.
//
// The protocol pill on top of each arrow follows the same spirit: clickable
// when `editable` (including the dashed "+ protocolo" pill of a step with no
// protocol yet), opening a compact panel of a single row of ICONS
// (`selectEdge()`/`openProtocolEditor()`, the same panel pinned on the left
// that the block toolbar uses — it does not live inside it, the two merely
// exclude each other): direction (two independent toggles, `->`/`<-`/`<->`),
// dashed (another icon toggle) and "Desligar" (which removes the link only —
// the blocks go on existing, which is how a block can end up with no connection
// at all); direction and dashed apply immediately, with no "Salvar". The
// protocol itself has no field in THAT panel — a double click on the pill turns
// it into an `<input>` in place (`startInlineProtocolEdit()`), free text with
// the `Protocol` enum offered as autocomplete. A PATCH to `graph.edgeUpdateUrl`
// (direction or protocol) or a DELETE to `graph.edgeRemoveUrl` (disconnect);
// there is no protected "root" link here, any edge can be edited or removed.
//
// The topbar's "+" button (`openAddEditor()`) adds a NEW, BARE block: a
// horizontal row of kind ICONS (`buildAddKindIcons()`, the same list as
// `refreshKindRow()` but with no persistent selection) — with no arrow, no
// protocol, and no solution or free text to fill in here. Clicking an icon
// creates the block (`createNodeFromKind()`, a POST to `graph.nodeAddUrl` with
// the kind's own name as the initial text), which `appendNode()` draws and
// positions to the right of the last block, and the next step is
// `startInlineLabelEdit()` on it — naming it (or, for `system`, searching for
// the solution) happens on the newly created block, the same gesture as
// renaming an existing one. The SAME panel reopens (`openQuickAddEditor()`), in
// the same fixed corner, when an arrow pulled from a port is dropped on empty
// space (see form 1 below) — in that case the block is born AT THE POINT where
// the arrow was dropped (not to the right of the last one) and links itself to
// the originating port automatically, in the same click on the icon; only the
// new block's position uses that point, the panel itself always opens in the
// corner.
//
// The chain is a FREE GRAPH, not a straight line, and it does not require every
// block to be connected to something: `graph.edges[i]` carries `{from, to,
// arrow, protocol}` with explicit node indices, and the number of edges is
// independent of the number of nodes. There are two ways to connect and
// reconnect blocks, both available on EVERY node (the root included):
//   1. Dragging an arrow out of one of a block's 4 PORTS (the small circles
//      that appear on hover, children of the node — see `paintNode()` and
//      `startPortDrag()`) and dropping it on any other block creates a NEW link
//      on the spot: a POST to `graph.edgeAddUrl` with `->` and no protocol,
//      with no dialog in the way — direction and protocol are adjusted
//      afterwards on the pill. During the drag, `drag.type === 'connect'` draws
//      a dashed preview to the pointer and highlights the block under it;
//      dropping on the originating block cancels, but dropping on EMPTY CANVAS
//      opens the "Adicionar bloco" panel right there (`openQuickAddEditor()`,
//      see above) — pulling an arrow into the void earns a new block already
//      linked, instead of simply doing nothing.
//   2. Dragging the handle of an EXISTING arrow end into ANOTHER block (not
//      merely onto another anchor of the same pair of nodes) reconnects that
//      link to that block — `nodeAtPoint()` decides, during the drag, whether
//      the pointer is over a node different from that end's original one; on
//      release, `retargetEdge()` sends the PATCH to `graph.edgeRetargetUrl`
//      (applied optimistically before the response, so it does not visually
//      "snap back" while the request is in flight — it reverts on error). A
//      block cannot link to itself: dropping on the opposite end of the SAME
//      link is ignored, keeping the original node.
// Between the two ways of connecting, the bare block from the add panel and
// "Desligar" in the link editor, the topology is a genuinely free graph — nodes
// and links are created independently, with nothing forcing every block to be
// connected.
//
// The integration's name and status — the only metadata that does not live on a
// node or an edge of the chain — are NOT edited here: they live in the page's
// top bar (`Diagrams\Meta`, inline editing), visible on the Documentação tab
// too. This module had a panel of its own for that until 2026-08-17; two
// editors of the same field desynchronise on the first edit. Creating a new
// Diagram is the solution list's "Nova" form (the "Novo diagrama" form), which
// already delivers the chain with the root node alone.

const SVG_NS = 'http://www.w3.org/2000/svg'
const MIN_SCALE = 0.3
const MAX_SCALE = 2.2
const LEVEL_GAP = 90 // espaço horizontal entre nós consecutivos
const FIT_PAD = 60
const EDGE_GAP = 8   // afastamento da linha em relação ao centro do handle (evita invadir o círculo)
const EDGE_GAP_LIFELINE = 15 // idem, do lado de uma linha de vida — ver o comentário em draw()
const MOVE_TOLERANCE = 3 // distance (px, world space) that tells a click from a drag

// 8 anchors per node (fraction of the width/height + the outgoing normal).
const ANCHORS = {
    l:  { fx: 0,    fy: 0.5, nx: -1, ny: 0 },
    r:  { fx: 1,    fy: 0.5, nx: 1,  ny: 0 },
    t:  { fx: 0.5,  fy: 0,   nx: 0,  ny: -1 },
    b:  { fx: 0.5,  fy: 1,   nx: 0,  ny: 1 },
    tl: { fx: 0.25, fy: 0,   nx: 0,  ny: -1 }, // intermediária topo
    tr: { fx: 0.75, fy: 0,   nx: 0,  ny: -1 }, // intermediária topo
    bl: { fx: 0.25, fy: 1,   nx: 0,  ny: 1 },  // intermediária base
    br: { fx: 0.75, fy: 1,   nx: 0,  ny: 1 },  // intermediária base
}
const ANCHOR_KEYS = Object.keys(ANCHORS)

// ── orthogonal arrow routing ─────────────────────────────────────────
// A link leaves perpendicular to the face it is born on, turns at a right
// angle and arrives perpendicular to the destination face — the drawing a
// technical diagram uses, in place of the Bézier curve that was here before.
//
// Two measurements make the drawing: the STRAIGHT RUN before the first curve
// (without it the arrow would turn while still touching the block, and the
// rounded corner would eat the arrowhead) and the corner RADIUS, which is only
// a light rounding — the angle has to go on reading as 90°, not as a curve.
const EDGE_STUB = 22
const EDGE_CORNER = 10
// How far two overlapping corridors step apart from each other, and how
// close two have to be to count as the same corridor.
const EDGE_CORRIDOR_STEP = 18
const EDGE_CORRIDOR_BUCKET = 14
// Distance between two ends competing for the SAME face of a block.
const ANCHOR_FAN_STEP = 16

/** The route's vertices between two anchors, straight end runs included. */
function orthogonalPoints(p0, p3, stub = EDGE_STUB, offset = 0) {
    const s0 = { x: p0.x + p0.nx * stub, y: p0.y + p0.ny * stub }
    const s3 = { x: p3.x + p3.nx * stub, y: p3.y + p3.ny * stub }
    // This canvas's normals are all axial (see ANCHORS), so "does it leave
    // horizontally" is the whole question: there is no diagonal anchor whose
    // exit axis would be ambiguous.
    const fromHoriz = p0.nx !== 0
    const toHoriz = p3.nx !== 0

    // The detour must not push the corridor OUTSIDE the gap between the two
    // ends. With two blocks close together, an 18px step put the corridor
    // behind the source block: the line left it, doubled back over itself and
    // came in again. Clamped to the gap, the dense case loses a little
    // separation instead of drawing something wrong.
    const between = (value, a, b) => Math.min(Math.max(value, Math.min(a, b)), Math.max(a, b))

    let mids
    if (fromHoriz && toHoriz) {
        const mx = between((s0.x + s3.x) / 2 + offset, s0.x, s3.x)
        mids = [{ x: mx, y: s0.y }, { x: mx, y: s3.y }]
    } else if (!fromHoriz && !toHoriz) {
        const my = between((s0.y + s3.y) / 2 + offset, s0.y, s3.y)
        mids = [{ x: s0.x, y: my }, { x: s3.x, y: my }]
    } else if (fromHoriz) {
        mids = [{ x: s3.x, y: s0.y }]
    } else {
        mids = [{ x: s0.x, y: s3.y }]
    }

    return [p0, s0, ...mids, s3, p3]
}

/**
 * A route's CORRIDOR: the long middle run, where it crosses the empty space
 * between the two blocks. `null` when the route makes a single elbow (leaving
 * horizontally and arriving vertically, or the other way round) — there is no
 * middle run there for anyone to contend over.
 *
 * `from`/`to` are the corridor's ends on the OTHER axis, so we can tell whether
 * two of them really cross or merely happened to land on the same coordinate in
 * different parts of the drawing.
 */
function corridorOf(p0, p3, stub = EDGE_STUB) {
    const s0 = { x: p0.x + p0.nx * stub, y: p0.y + p0.ny * stub }
    const s3 = { x: p3.x + p3.nx * stub, y: p3.y + p3.ny * stub }
    const fromHoriz = p0.nx !== 0
    const toHoriz = p3.nx !== 0

    if (fromHoriz && toHoriz) return { axis: 'x', coord: (s0.x + s3.x) / 2, from: s0.y, to: s3.y }
    if (!fromHoriz && !toHoriz) return { axis: 'y', coord: (s0.y + s3.y) / 2, from: s0.x, to: s3.x }

    return null
}

/**
 * How far to push each corridor aside, so that two that would run ON TOP of
 * each other run side by side instead.
 *
 * This used to apply only to two links between the SAME pair of blocks. But the
 * tangle in a large drawing does not come from there: it comes from links with
 * no relation to one another whose middle happened to land at the same height —
 * in a lane drawing that is the common case, because everybody crosses the same
 * band of empty space between two lanes. Grouping by COORDINATE rather than by
 * pair covers both, and the repeated pair is just the particular case where the
 * corridor coincides entirely.
 *
 * Only the ones that actually overlap move: two corridors at the same height in
 * distant parts of the canvas stay exactly where they were. The offset is
 * symmetric around the axis, so a corridor on its own pays nothing — the common
 * case does not shift.
 */
function corridorOffsets(corridors) {
    const offsets = corridors.map(() => 0)
    const groups = new Map()

    corridors.forEach((corridor, i) => {
        if (!corridor) return
        const key = corridor.axis + ':' + Math.round(corridor.coord / EDGE_CORRIDOR_BUCKET)
        if (!groups.has(key)) groups.set(key, [])
        groups.get(key).push(i)
    })

    groups.forEach((members) => {
        if (members.length < 2) return

        const span = (i) => {
            const c = corridors[i]

            return { lo: Math.min(c.from, c.to), hi: Math.max(c.from, c.to) }
        }
        const clusters = []

        members
            .slice()
            // Sorting by `lo` is LOAD-BEARING, not cosmetic: it is what makes
            // the `clusters.find()` below — which takes the first match it
            // finds — equivalent to a correct interval merge. With the input
            // sorted, every new span has a `lo` at or above every cluster's,
            // clusters are born disjoint and never overlap again, so at most
            // ONE candidate matches and "first" never really chooses
            // (measured: 2,147,981 lookups, none with more than one
            // candidate). Drop the sort and the same input leaves two
            // overlapping clusters with independent offset ladders.
            .sort((a, b) => span(a).lo - span(b).lo)
            .forEach((i) => {
                const { lo, hi } = span(i)
                const touching = clusters.find((c) => lo <= c.hi && hi >= c.lo)

                if (touching) {
                    touching.lo = Math.min(touching.lo, lo)
                    touching.hi = Math.max(touching.hi, hi)
                    touching.members.push(i)
                } else {
                    clusters.push({ lo, hi, members: [i] })
                }
            })

        clusters.forEach(({ members: overlapping }) => {
            if (overlapping.length < 2) return
            overlapping.forEach((i, rank) => {
                offsets[i] = (rank - (overlapping.length - 1) / 2) * EDGE_CORRIDOR_STEP
            })
        })
    })

    return offsets
}

/**
 * Where to land a route's label: in the MIDDLE OF ITS LONGEST STRAIGHT RUN.
 *
 * It used to be the stroke's geometric middle, which on an orthogonal route
 * frequently falls on a curve — the label came out askew over the elbow, biting
 * into both runs. A tie favours the horizontal run: horizontal text over a
 * horizontal line occupies the same direction, and covers less of the drawing
 * than the same box laid across a vertical one.
 */
function labelAnchor(points) {
    // Merge the collinear runs BEFORE measuring, because merged is what you
    // SEE: `roundedPath()` draws the route already merged, while
    // `orthogonalPoints()` still hands it over as raw vertices — a straight
    // arrow is five points, two of them in the same place. Scored vertex by
    // vertex, the longest "run" of a straight line was HALF the corridor, and
    // the label landed at the 25% mark of the stroke the user actually sees
    // (64px off centre across a 256px gap).
    const runs = []

    for (let i = 1; i < points.length; i++) {
        const [a, b] = [points[i - 1], points[i]]

        // The repeated middle vertex of a straight route.
        if (a.x === b.x && a.y === b.y) continue

        const horiz = Math.abs(b.x - a.x) >= Math.abs(b.y - a.y)
        const last = runs[runs.length - 1]

        // Same axis and same line: this continues the previous run.
        if (last && last.horiz === horiz && (horiz ? last.a.y === b.y : last.a.x === b.x)) {
            last.b = b
            continue
        }

        runs.push({ horiz, a, b })
    }

    let best = null

    for (const run of runs) {
        const length = run.horiz ? Math.abs(run.b.x - run.a.x) : Math.abs(run.b.y - run.a.y)
        const score = run.horiz ? length * 1.35 : length

        if (!best || score > best.score) {
            best = { score, horiz: run.horiz, x: (run.a.x + run.b.x) / 2, y: (run.a.y + run.b.y) / 2 }
        }
    }

    return best
}

/**
 * A polyline with rounded corners, as a `<path>`'s `d`.
 *
 * Each corner's radius is capped at HALF of the shorter of the two segments it
 * joins: without that, two blocks almost touching produce a curve larger than
 * the segment itself and the stroke doubles back on its own.
 */
function roundedPath(points, radius = EDGE_CORNER) {
    const pts = []

    points.forEach((point) => {
        const last = pts[pts.length - 1]
        if (!last || Math.abs(last.x - point.x) > 0.01 || Math.abs(last.y - point.y) > 0.01) {
            pts.push({ x: point.x, y: point.y })
        }
    })

    // A collinear vertex is not a corner — it goes, or it becomes a curve in
    // the middle of a straight run (the case of two perfectly aligned blocks).
    for (let i = pts.length - 2; i > 0; i--) {
        const [a, b, c] = [pts[i - 1], pts[i], pts[i + 1]]
        if (Math.abs((b.x - a.x) * (c.y - b.y) - (b.y - a.y) * (c.x - b.x)) < 0.01) pts.splice(i, 1)
    }

    if (pts.length < 2) return ''

    const n = (value) => Math.round(value * 100) / 100
    let d = `M ${n(pts[0].x)} ${n(pts[0].y)}`

    for (let i = 1; i < pts.length - 1; i++) {
        const [prev, cur, next] = [pts[i - 1], pts[i], pts[i + 1]]
        const inLen = Math.hypot(cur.x - prev.x, cur.y - prev.y)
        const outLen = Math.hypot(next.x - cur.x, next.y - cur.y)
        const r = Math.min(radius, inLen / 2, outLen / 2)

        if (r < 0.5) {
            d += ` L ${n(cur.x)} ${n(cur.y)}`
            continue
        }

        const t1 = { x: cur.x + ((prev.x - cur.x) / inLen) * r, y: cur.y + ((prev.y - cur.y) / inLen) * r }
        const t2 = { x: cur.x + ((next.x - cur.x) / outLen) * r, y: cur.y + ((next.y - cur.y) / outLen) * r }
        d += ` L ${n(t1.x)} ${n(t1.y)} Q ${n(cur.x)} ${n(cur.y)}, ${n(t2.x)} ${n(t2.y)}`
    }

    const end = pts[pts.length - 1]

    return `${d} L ${n(end.x)} ${n(end.y)}`
}
// The sides that get a connection port on the block (the 4 main anchors — the
// intermediate top/bottom ones exist only for an arrow tip to stick to).
const ANCHOR_SIDES = ['t', 'r', 'b', 'l']

// The block colour palette (the same logic as the reference mind map: presets
// plus a custom colour) and the font families selectable per block. Very light
// shades on purpose (2026-07-28: the previous palette's colours were too
// strong) plus pure white as the first option — the text stays dark on all of
// them (see `textColorFor()`), since every one of them is high in luminance.
const PALETTE = ['#FFFFFF', '#E9EDFB', '#E6F1FC', '#E3F4EA', '#FCF1D4', '#FBE7EC', '#EFE7FB', '#EDF1F5']
const FONTS = {
    sans: "'Space Grotesk', 'Inter', system-ui, sans-serif",
    serif: "Georgia, 'Times New Roman', serif",
    mono: "ui-monospace, 'SF Mono', Menlo, Consolas, monospace",
}
// `sm` is today's size (13px, see `.ak-viz-node` in the CSS) — kept here as an
// explicit value (rather than "absent = the CSS default") so it fits the same
// pattern as the font <select>, which always has a value selected.
const FONT_SIZES = { sm: '13px', md: '15px', lg: '17px' }

// A new lane's default colours (cycled, one per `lanes.length` at creation
// time) and its initial size (world px) — both only suggestions, editable
// afterwards by dragging the lane or its handles, or through the "Raias" panel.
// The same list serves both colour pickers (body and header, see
// `buildLaneSwatches()`/`buildLaneHeaderSwatches()`) — black/white/beige/grey
// cover the neutral cases the original 6 "brand" colours did not (a pure
// black or white header, for instance).
const LANE_COLORS = ['#2F6FED', '#7C3AED', '#16A34A', '#EA580C', '#DB2777', '#0891B2', '#000000', '#FFFFFF', '#E8DCC4', '#9CA3AF']
const LANE_DEFAULT_WIDTH = 420
const LANE_DEFAULT_HEIGHT = 240
// Minimum/maximum size (world px) of a lane in either dimension — the same
// clamp applied while resizing by a handle (`drag.type === 'lane-resize'`) and
// in the server's own validation (`SaveChainLayoutRequest`).
const LANE_MIN_SIZE = 100
const LANE_MAX_SIZE = 6000

// A basic note ("post-it") — fixed width, no resizing (see `rebuildNotes()`);
// the height grows on its own with the text (`contenteditable`), so there is no
// equivalent fixed value for it.
const NOTE_DEFAULT_WIDTH = 190
const NOTE_MIN_HEIGHT = 90

// A new lane's default style — square corners, solid border, flat fill,
// horizontal orientation (vertical label on the left edge, as it has always
// been), a visible label and small text. A lane saved before any of these
// fields existed carries no such key (`applyLayout()` backfills by reading this
// very object), so changing one of these defaults also changes how old lanes
// are read — do not do it without thinking about backward compatibility.
// `headerColor` is left out of this object on purpose: absent (not an explicit
// `null`) is the "not customised yet" signal `laneHeaderColor()` uses to decide
// between the explicit value and the automatic darkening of the body colour —
// putting it here as a fixed `null` would work the same, but the object would
// already carry the key, obscuring that contract.
const LANE_STYLE_DEFAULTS = {
    rounded: false,
    dashed: false,
    opacity: 0.08,
    orientation: 'horizontal',
    showTitle: true,
    fontSize: 'sm',
}
// Range of the opacity slider — the same range `SaveChainLayoutRequest`
// validates.
const LANE_OPACITY_MIN = 0.03
const LANE_OPACITY_MAX = 0.5
// The lane label's text sizes — a scale of its own (smaller than the blocks'
// `FONT_SIZES`), since the label is a narrow strip; `sm` (11px) is the size it
// has always been, kept as an explicit default for the same reason as
// `FONT_SIZES` above.
const LANE_FONT_SIZES = { sm: '11px', md: '13px', lg: '15px' }

// ── presentation mode — dots travelling along the arrows ─────────────────
// Up to 5 dots at once, one per "branch" of the flow — see
// `computePresentationPaths()`. A vibrant palette, well spread around the
// colour wheel (purple, the brand's own lime, yellow, orange, cyan) so the 5
// dots always stay easy to tell apart — kept separate from `LANE_COLORS` on
// purpose, since there the colour has to work as the translucent background of
// a whole lane, while here it is only a bright dot on an edge. The ceiling is a
// safety net against a pathological cycle (it should never be reached in
// practice — the real cycle protection is per node already visited ON THE SAME
// path, not a count).
const PRESENT_MAX_PATHS = 5
const PRESENT_HARD_CAP_EDGES = 200
const PRESENT_DOT_COLORS = ['#A855F7', '#AADB1E', '#FACC15', '#FB923C', '#22D3EE']

// ── exporting the diagram (image/GIF) — see `captureDiagramCanvas()` ──────
// It crops exactly around the content (nodes ∪ lanes), never to the viewport
// open in the browser — which is what avoids the "frame" of white space `fit()`
// leaves on purpose (letterbox contain, designed for editing, where the
// viewport has a shape of its own). `EXPORT_LONG_SIDE` is the final image's
// longest side; the other side is derived from the content's real aspect ratio,
// so the output ALWAYS fills the frame completely.
const EXPORT_PAD = 48
const EXPORT_LONG_SIDE = 1600
// Frames capture back-to-back — no artificial delay between them (see
// exportVideo()); real capture time already dwarfs any inter-frame wait
// worth imposing (measured 2026-08-03: ~550-900ms per frame, mostly the DOM
// clone + serialize step — NOT pixel count, confirmed by timing 1600px vs
// 1100px captures directly: barely different). `EXPORT_GIF_LONG_SIDE`
// (smaller than the still PNG's `EXPORT_LONG_SIDE`) buys a modest amount of
// that back on the rasterize/decode step, but the real lever for "more
// frames" is `EXPORT_GIF_SECONDS` — this is architecturally a slow,
// per-frame-DOM-clone capture, not a real-time recorder, so there's a hard
// floor on frame RATE; the only way to get more frames is more total time.
const EXPORT_GIF_LONG_SIDE = 1100
const EXPORT_GIF_SECONDS = 14
// 1×1 transparent PNG — `html-to-image`'s own fallback for a broken `<img>`
// (logo file missing/404) is `imagePlaceholder || ''`, and an EMPTY `src` is
// a real browser trap: `<img src="">` resolves to the CURRENT page URL and
// tries to load the HTML document itself as an image, which fails and takes
// the whole capture down with it (confirmed via a broken Solution logo in
// this exact diagram — `err.target.src` came back as this page's own URL).
// Passing a real, valid placeholder avoids that trap entirely.
const EXPORT_IMAGE_PLACEHOLDER = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='

// Screenshot "look" presets — a deliberately narrow set of CSS-only overrides
// (canvas background + edge/marker/pill color, nothing else) applied for the
// duration of a single capture via a `data-viz-preset` attribute set on
// `world`/the edges `<svg>` right before `toCanvas()` and removed right
// after (see `captureDiagramCanvas()`) — the matching rules live in the
// component's outer `<style>` (nodes, real HTML — computed style gets copied
// per-element regardless of external stylesheet) and in the edges SVG's OWN
// internal `<style>` (nested SVGs are raw-cloned wholesale — see that
// block's comment). Deliberately does NOT touch font, font-size, padding, or
// anything else that affects layout/wrapping: a prior attempt to get this
// same variety by sending the exported PNG to Gemini for a visual "restyle"
// reliably garbled small text ("SAP S/4HANA" → "SAM4AMA", "AllStrategy" →
// "AllSnatag") since it's a generative model re-drawing pixels, not a
// stylesheet — removed 2026-08-03. A CSS-only swap can never do that: the
// same DOM, the same box model, just different colors.
// Only `bg` — export's flat canvas fill (`toCanvas()`'s `backgroundColor`
// option, a plain color, no gradient support). Edge/marker/pill/node-shadow
// colors for each theme live directly in this component's CSS (the outer
// <style> for nodes, the edges <svg>'s own internal <style> for
// edges/markers/pills) — kept here only where JS genuinely needs the value
// (this one, since a detached export clone has no `.ak-viz-viewport` to read
// a computed background from). `corporativo`'s live canvas is actually a
// subtle gradient (see `.ak-viz-viewport[data-viz-preset="corporativo"]`) —
// canvas fills can't do gradients here, so this approximates its overall tone.
const EXPORT_PRESETS = {
    original:    { bg: '#F7F9FC' },
    casual:      { bg: '#FFF7ED' },
    corporativo: { bg: '#F5F8F6' },
    tech:        { bg: '#132A45' },
    // The two token themes: their canvas IS `--viz-bg`, so these two values
    // must stay equal to the `.ak-viz-viewport[data-viz-preset="…"]` blocks in
    // the component's <style>. Both keep the dotted grid live (they only
    // retint it); a flat fill is all `toCanvas()` can take, so the export
    // drops the dots the same way every other preset here does.
    arquitetura: { bg: '#020617' },
    blueprint:   { bg: '#EDF7FA' },
}

// Discovers up to `PRESENT_MAX_PATHS` paths through the chain's free graph, one
// per branch — a pure function, touching neither the DOM nor the module's
// state, only `graph.nodes`/`graph.edges` (the same shape as `graphRef`). Each
// edge contributes a single "outgoing" direction (`outgoing[node]`): `'->'`
// leaves `from`; `'<-'` leaves `to` (the walk samples the `<path>` back to
// front — see `reversed` in the consumer); `'<->'` leaves `from` only and never
// creates the reverse entry — so a bidirectional link is walked in one
// direction, by at most one dot, with no exclusion needed afterwards. A root is
// a node with no entry in that same direction; a root with NO outgoing edge at
// all (an isolated node) is ignored — there would be nothing to animate, and it
// is not worth one of the 5 slots.
//
// A FIFO queue of "seeds" (`{startNode, forcedEdge}`): the roots go in first,
// in index order — each walk always follows the LOWEST-index outgoing edge of
// the current node, and the first time ANY walk passes through a node with 2 or
// more outgoing edges, the rest leave as new seeds at the back of the queue
// (`branchSpawned`, global — a merge node does not spawn duplicate branches just
// because a second path came through it later). That also gives the
// "branch-first, breadth-first" discovery order asked for: the first path's
// branches come before the second's.
//
// Cycle protection: each walk has its own `visited` (nodes); when it tries to
// advance to a node already visited ON THAT WALK, the edge closing the cycle
// still enters the list — it is what makes the dot's return read as a genuinely
// continuous loop — and the walk stops there (rather than spinning forever
// reprocessing the same stretch).
function computePresentationPaths(graph) {
    const nodeCount = graph?.nodes?.length || 0
    const edgeList = graph?.edges || []
    if (!nodeCount || !edgeList.length) return []

    const outgoing = Array.from({ length: nodeCount }, () => [])
    const hasIncoming = new Array(nodeCount).fill(false)
    edgeList.forEach((edge, i) => {
        const arrow = edge.arrow || '->'
        if (arrow === '->' || arrow === '<->') {
            outgoing[edge.from]?.push({ edgeIndex: i, to: edge.to, reversed: false })
            hasIncoming[edge.to] = true
        } else if (arrow === '<-') {
            outgoing[edge.to]?.push({ edgeIndex: i, to: edge.from, reversed: true })
            hasIncoming[edge.from] = true
        }
    })

    const queue = []
    for (let n = 0; n < nodeCount; n++) {
        if (!hasIncoming[n] && outgoing[n].length > 0) queue.push({ startNode: n, forcedEdge: null })
    }

    const branchSpawned = new Set()
    const paths = []
    let qi = 0
    while (qi < queue.length && paths.length < PRESENT_MAX_PATHS) {
        const { startNode, forcedEdge } = queue[qi++]
        const visited = new Set([startNode])
        const pathEdges = []
        let current = startNode
        let firstStep = forcedEdge

        while (pathEdges.length < PRESENT_HARD_CAP_EDGES) {
            const opts = outgoing[current]
            let step
            if (firstStep) {
                step = firstStep
                firstStep = null
            } else {
                if (opts.length === 0) break // beco sem saída
                step = opts[0]
            }
            if (opts.length > 1 && !branchSpawned.has(current)) {
                branchSpawned.add(current)
                opts.forEach((o) => { if (o !== step) queue.push({ startNode: current, forcedEdge: o }) })
            }

            const closesCycle = visited.has(step.to)
            pathEdges.push({ edgeIndex: step.edgeIndex, reversed: step.reversed })
            if (closesCycle) break
            visited.add(step.to)
            current = step.to
        }

        if (pathEdges.length > 0) paths.push({ startNode, edges: pathEdges })
    }

    return paths.map((p, k) => ({ ...p, color: PRESENT_DOT_COLORS[k % PRESENT_DOT_COLORS.length] }))
}

// Nodes with NO edge touching them (neither `from` nor `to`, whatever the
// `arrow`) never enter `computePresentationPaths()` — no dot will ever reach
// them, so there is no sense leaving them waiting for the safety sweep in
// `onDotFirstLoopComplete()` (which only fires once EVERY dot has closed its
// first loop, and that can take a while). A pure function, kept apart from
// `computePresentationPaths()` because the rule is a different one: this is
// degree zero, with no notion of path or direction at all.
function computeIsolatedNodes(graphRef) {
    const nodeCount = graphRef?.nodes?.length || 0
    const connected = new Array(nodeCount).fill(false)
    ;(graphRef?.edges || []).forEach((edge) => {
        connected[edge.from] = true
        connected[edge.to] = true
    })
    return connected.flatMap((isConnected, i) => (isConnected ? [] : [i]))
}

function luminance(hex) {
    const h = hex.replace('#', '')
    const r = parseInt(h.substr(0, 2), 16) / 255
    const g = parseInt(h.substr(2, 2), 16) / 255
    const b = parseInt(h.substr(4, 2), 16) / 255
    return 0.2126 * r + 0.7152 * g + 0.0722 * b
}
const textColorFor = (hex) => (luminance(hex) < 0.55 ? '#FFFFFF' : '#1A1A2E')
const isHex = (v) => typeof v === 'string' && /^#[0-9a-f]{6}$/i.test(v)
function hexToRgba(hex, alpha) {
    const h = hex.replace('#', '')
    const r = parseInt(h.substr(0, 2), 16)
    const g = parseInt(h.substr(2, 2), 16)
    const b = parseInt(h.substr(4, 2), 16)
    return `rgba(${r}, ${g}, ${b}, ${alpha})`
}
// The same colour, one shade darker — the AUTOMATIC value for a lane's header
// (the strip/label carrying the title) when the user has never chosen a header
// colour of their own, see `laneHeaderColor()` just below.
function darkenHex(hex, amount) {
    const h = hex.replace('#', '')
    const scale = (v) => Math.max(0, Math.min(255, Math.round(v * (1 - amount))))
    const r = scale(parseInt(h.substr(0, 2), 16))
    const g = scale(parseInt(h.substr(2, 2), 16))
    const b = scale(parseInt(h.substr(4, 2), 16))
    return `#${[r, g, b].map((v) => v.toString(16).padStart(2, '0')).join('')}`
}

// The CSS `background` of a lane's BODY — always solid (the colour with alpha
// at the chosen opacity); the diagonal and woven patterns that used to be here
// were removed (solid only now), see `LANE_STYLE_DEFAULTS`.
function laneBackgroundCss(lane) {
    const opacity = Number.isFinite(lane.opacity) ? lane.opacity : LANE_STYLE_DEFAULTS.opacity
    return hexToRgba(lane.color, opacity)
}

// The HEADER's colour (the label/strip carrying the title) — independent of the
// body colour (`lane.color`) once the user has chosen one explicitly
// (`lane.headerColor`, `setLaneHeaderColor()`); with no explicit choice it falls
// back automatically to the usual darkening of the body colour, so a lane that
// never touched this looks exactly as it always did.
function laneHeaderColor(lane) {
    return isHex(lane.headerColor) ? lane.headerColor : darkenHex(lane.color, 0.35)
}

// The block's avatar: the solution's logo, or (with no logo) a badge carrying
// the name's initial — the catalog's own fallback (`x-ui.logo`), rebuilt here in
// plain DOM because the data-viz nodes never go through Blade. On a
// decision/actor block the logo's place is taken by the kind's icon
// (`data.icon`, a heroicon already rendered on the server — see
// `ChainNodeKind::icon()`).
function buildAvatar(data) {
    const avatar = document.createElement('span')
    avatar.className = 'ak-viz-node-avatar'
    if (data.logo) {
        const img = document.createElement('img')
        img.src = data.logo
        img.alt = ''
        avatar.appendChild(img)
    } else {
        avatar.classList.add('is-fallback')
        avatar.textContent = (data.label ?? '').trim().charAt(0).toUpperCase() || '?'
    }
    return avatar
}

function buildKindIcon(icon) {
    const avatar = document.createElement('span')
    avatar.className = 'ak-viz-node-avatar is-kind'
    avatar.innerHTML = icon
    return avatar
}

// 4 connection ports per block (top/right/bottom/left) — the "pull an arrow
// out of here". They are children of the node (so they follow its position and
// size with no maths at all) and have no listener of their own:
// `startNodePointer()` recognises a `[data-viz-port]` in the pointerdown's
// target and starts a link drag instead of a block drag. Visible only on
// hover/selection and only when editable (CSS).
function buildPorts(el) {
    ANCHOR_SIDES.forEach((side) => {
        const port = document.createElement('span')
        port.className = 'ak-viz-port is-' + side
        port.setAttribute('data-viz-port', side)
        port.title = 'Arraste até outro bloco para criar uma ligação'
        el.appendChild(port)
    })
}

// (Re)draws a block's content from the node's resolved data — used both when
// mounting the whole graph (`render()`) and after editing one node's title
// (`applyNodeData()`), so the two paths can never build the block's DOM
// differently.
function paintNode(el, data) {
    const kind = data.kind || 'system'
    // Only a system block carrying a registered solution (a real logo) can
    // become "logo only" — free text, decision and actor have no image at all
    // to show on its own, and a solution with no logo would fall back to the
    // initial badge, which makes no sense "alone" in place of the card.
    const logoOnly = kind === 'system' && !!data.solution && !!data.logo && !!data.logoOnly
    // `is-free` (the "outside Leo" dashed border) belongs only to a system
    // block with no Solution — decision/actor/start/end have shapes and
    // colours of their own, see the classes below.
    el.classList.toggle('is-free', kind === 'system' && !data.solution)
    el.classList.toggle('is-decision', kind === 'decision')
    el.classList.toggle('is-actor', kind === 'actor')
    el.classList.toggle('is-start', kind === 'start')
    el.classList.toggle('is-end', kind === 'end')
    el.classList.toggle('is-image', kind === 'image')
    el.classList.toggle('is-lifeline', kind === 'lifeline')
    el.classList.toggle('is-step', kind === 'step')
    el.classList.toggle('is-logo-only', logoOnly)
    el.classList.toggle('has-comment', !!data.comment)
    el.classList.toggle('is-dashed', !!data.dashed)
    // The colour family of the solution's CATEGORY
    // (`ChainGraph::resolveNode()`), read only by the themes that tint a block
    // by what the system IS — see the `--viz-node-accent` block in the
    // component's <style>. Removed (rather than left empty) when the block is
    // not a registered solution: the selector is `[data-category-family]`, so
    // an empty value would still match.
    if (data.categoryFamily) el.dataset.categoryFamily = data.categoryFamily
    else delete el.dataset.categoryFamily
    el.innerHTML = ''
    // Cleared along with the content: only the round blocks write a `title`
    // (the full label, since the chip below the circle truncates), and turning
    // an actor into a system would leave the old tooltip stuck to the new
    // block.
    el.title = ''

    // "Somente logo": the whole card (avatar + name) goes and only the
    // solution's image at full size is left — the same spirit as a pasted image
    // (`kind === 'image'` above), except that here it is a catalog solution
    // rather than media belonging to the node.
    if (logoOnly) {
        const img = document.createElement('img')
        img.src = data.logo
        img.alt = data.label || ''
        el.appendChild(img)

        const badge = document.createElement('span')
        badge.className = 'ak-viz-comment-badge'
        el.appendChild(badge)

        buildPorts(el)
        return
    }

    // A pasted image (Ctrl+V): the image alone, with no avatar and no label —
    // the content already IS the image. It stays a block like any other (port,
    // comment badge), so it can send and receive arrows normally. A missing
    // `data.mediaUrl` (media removed elsewhere, or a malformed `image` node)
    // lands on an empty frame with the fallback icon instead of breaking.
    if (kind === 'image') {
        if (data.mediaUrl) {
            const img = document.createElement('img')
            img.src = data.mediaUrl
            img.alt = data.label || 'Imagem'
            img.draggable = false
            el.appendChild(img)
        } else {
            const fallback = document.createElement('span')
            fallback.className = 'ak-viz-node-image-fallback'
            if (data.icon) fallback.innerHTML = data.icon
            el.appendChild(fallback)
        }

        const badge = document.createElement('span')
        badge.className = 'ak-viz-comment-badge'
        el.appendChild(badge)

        buildPorts(el)
        return
    }

    // The three ROUND blocks — start, end and actor: the icon inside the
    // circle and the label written BELOW it (`.ak-viz-node-endcap-label`),
    // never beside it. A layout entirely different from the other kinds, see
    // the CSS (`.is-start`/`.is-end`/`.is-actor`).
    //
    // The actor joined them on 2026-08-26: it used to be a pill with the icon
    // next to the text, and therefore read as one more box in the flow. It is
    // not a step — it is who the flow happens to, and the terminals' own
    // silhouette says so without a legend. The label outside the shape is also
    // what stops a long name from stretching the circle.
    //
    // The `title` keeps the whole label: the chip below the circle is capped in
    // width (CSS) and a long name shows up truncated in it.
    if (kind === 'start' || kind === 'end' || kind === 'actor') {
        if (data.icon) el.appendChild(buildKindIcon(data.icon))
        const label = document.createElement('span')
        label.className = 'ak-viz-node-endcap-label'
        label.textContent = data.label ?? (kind === 'start' ? 'Início' : kind === 'end' ? 'Fim' : '?')
        el.appendChild(label)
        el.title = label.textContent

        const badge = document.createElement('span')
        badge.className = 'ak-viz-comment-badge'
        el.appendChild(badge)

        buildPorts(el)
        return
    }

    // The block's body: avatar (the solution's logo, or the name's initial when
    // there is no logo; the kind's icon on decision/actor) plus the name. A
    // free-text system node has no avatar at all.
    const body = document.createElement('div')
    body.className = 'ak-viz-node-body'
    if (data.solution) body.appendChild(buildAvatar(data))
    else if (data.icon) body.appendChild(buildKindIcon(data.icon))
    const text = document.createElement('span')
    text.className = 'ak-viz-node-text'
    text.textContent = data.label ?? '?'
    body.appendChild(text)
    el.appendChild(body)

    const badge = document.createElement('span')
    badge.className = 'ak-viz-comment-badge'
    el.appendChild(badge)

    buildPorts(el)
}

const mounted = new WeakSet()
const roots = new Set()
const savedLayouts = new Map() // slug -> último layout salvo na sessão (mantém consistência sem reload)
let uidCounter = 0
let solutionsListCache = null // [{id,name}] — lido uma vez de [data-ak-solutions] (diagrams/workspace.blade.php)
let protocolsListCache = null // [{value,label}] — lido uma vez de [data-ak-protocols] (diagrams/workspace.blade.php)
let kindsListCache = null // [{value,label,system,placeholder}] — lido uma vez de [data-ak-node-kinds] (diagrams/workspace.blade.php)

function getSolutionsList() {
    if (solutionsListCache) return solutionsListCache
    const raw = document.querySelector('[data-ak-solutions]')?.getAttribute('data-ak-solutions')
    try {
        solutionsListCache = raw ? JSON.parse(raw) : []
    } catch {
        solutionsListCache = []
    }
    return solutionsListCache
}

function getProtocolsList() {
    if (protocolsListCache) return protocolsListCache
    const raw = document.querySelector('[data-ak-protocols]')?.getAttribute('data-ak-protocols')
    try {
        protocolsListCache = raw ? JSON.parse(raw) : []
    } catch {
        protocolsListCache = []
    }
    return protocolsListCache
}

// Block kinds (`App\Enums\ChainNodeKind`) — resolved on the server, never
// hardcoded here: `system` is the only one that accepts a registered solution,
// and each kind carries the placeholder for the free-text input.
function getNodeKindsList() {
    if (kindsListCache) return kindsListCache
    const raw = document.querySelector('[data-ak-node-kinds]')?.getAttribute('data-ak-node-kinds')
    try {
        kindsListCache = raw ? JSON.parse(raw) : []
    } catch {
        kindsListCache = []
    }
    return kindsListCache
}

function nodeKind(value) {
    const kinds = getNodeKindsList()
    return kinds.find((k) => k.value === value) ?? kinds.find((k) => k.system) ?? { value: 'system', system: true, placeholder: '' }
}

export function init() {
    document.querySelectorAll('[data-ak-chain-viz]').forEach(mount)
}

document.addEventListener('ak:diagram-selected', (e) => {
    roots.forEach((root) => root.__akVizRender?.(e.detail?.graph ?? null, e.detail?.name ?? '', e.detail?.slug ?? ''))
})

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''
}

// ── markdown (a lean parser, no dependencies) ────────────────────
function escapeHtml(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
}

// escapeHtml() alone doesn't touch quotes, which is fine for HTML content but
// not for a value going inside a quoted attribute — a literal `"` in a
// user-authored comment (e.g. an image alt text) would otherwise break out of
// `src="…"`/`alt="…"` and inject arbitrary attributes.
function escapeAttr(s) {
    return escapeHtml(s).replace(/"/g, '&quot;')
}

// Blocks `javascript:`/`data:`/`vbscript:` etc. in a comment's link/image
// target — only http(s) and root-relative/hash URLs render as a real link;
// anything else falls back to `#` rather than executing on click.
function safeUrl(u) {
    return /^(https?:\/\/|\/|#)/i.test(u) ? u : '#'
}

function mdInline(s) {
    const codes = []
    s = s.replace(/`([^`]+)`/g, (m, c) => { codes.push(c); return '\x00' + (codes.length - 1) + '\x00' })
    s = s.replace(/!\[([^\]]*)\]\(([^)\s]+)\)/g, (m, a, u) => `<img src="${escapeAttr(safeUrl(u))}" alt="${escapeAttr(a)}" style="max-width:100%;border-radius:6px">`)
    s = s.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (m, t, u) => `<a href="${escapeAttr(safeUrl(u))}" target="_blank" rel="noopener">${t}</a>`)
    s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    s = s.replace(/__([^_]+)__/g, '<strong>$1</strong>')
    s = s.replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>')
    s = s.replace(/(^|[^\w])_([^_\n]+)_/g, '$1<em>$2</em>')
    s = s.replace(/~~([^~]+)~~/g, '<del>$1</del>')
    s = s.replace(/\x00(\d+)\x00/g, (m, i) => `<code>${codes[+i]}</code>`)
    return s
}

function renderMarkdown(src) {
    if (!src || !src.trim()) return '<p class="md-empty">Sem comentário ainda.</p>'
    src = src.replace(/\r\n/g, '\n')
    const blocks = []
    src = src.replace(/```([\s\S]*?)```/g, (m, code) => { blocks.push(code.replace(/^\n/, '').replace(/\n$/, '')); return '\x01' + (blocks.length - 1) + '\x01' })
    const lines = src.split('\n')
    let html = ''
    let i = 0
    const isSpecial = (ln) => /^\x01\d+\x01$/.test(ln) || /^(#{1,6})\s/.test(ln) || /^\s*>/.test(ln) || /^\s*[-*+]\s/.test(ln) || /^\s*\d+\.\s/.test(ln) || /^\s*([-*_])\1\1+\s*$/.test(ln) || /^\s*$/.test(ln)
    while (i < lines.length) {
        const line = lines[i]
        const cm = line.match(/^\x01(\d+)\x01$/)
        if (cm) { html += `<pre><code>${escapeHtml(blocks[+cm[1]])}</code></pre>`; i++; continue }
        if (/^\s*$/.test(line)) { i++; continue }
        const h = line.match(/^(#{1,6})\s+(.*)$/)
        if (h) { const lv = h[1].length; html += `<h${lv}>${mdInline(escapeHtml(h[2]))}</h${lv}>`; i++; continue }
        if (/^\s*([-*_])\1\1+\s*$/.test(line)) { html += '<hr>'; i++; continue }
        if (/^\s*>/.test(line)) {
            const buf = []
            while (i < lines.length && /^\s*>/.test(lines[i])) { buf.push(lines[i].replace(/^\s*>\s?/, '')); i++ }
            html += `<blockquote>${renderMarkdown(buf.join('\n'))}</blockquote>`
            continue
        }
        if (/^\s*[-*+]\s+/.test(line)) {
            const items = []
            while (i < lines.length && /^\s*[-*+]\s+/.test(lines[i])) { items.push(lines[i].replace(/^\s*[-*+]\s+/, '')); i++ }
            html += '<ul>' + items.map((x) => `<li>${mdInline(escapeHtml(x))}</li>`).join('') + '</ul>'
            continue
        }
        if (/^\s*\d+\.\s+/.test(line)) {
            const items = []
            while (i < lines.length && /^\s*\d+\.\s+/.test(lines[i])) { items.push(lines[i].replace(/^\s*\d+\.\s+/, '')); i++ }
            html += '<ol>' + items.map((x) => `<li>${mdInline(escapeHtml(x))}</li>`).join('') + '</ol>'
            continue
        }
        const para = []
        while (i < lines.length && !isSpecial(lines[i])) { para.push(lines[i]); i++ }
        html += `<p>${mdInline(escapeHtml(para.join('\n'))).replace(/\n/g, '<br>')}</p>`
    }
    return html
}

function mount(root) {
    if (mounted.has(root)) return
    mounted.add(root)
    roots.add(root)

    const stage = root.querySelector('[data-viz-stage]')
    const viewport = root.querySelector('[data-viz-viewport]')
    const world = root.querySelector('[data-viz-world]')
    const edges = root.querySelector('[data-viz-edges]')
    // The pointer targets' own layer — see the markup for why they are not in
    // the edges SVG.
    const hits = root.querySelector('[data-viz-hits]')
    const empty = root.querySelector('[data-viz-empty]')
    const emptyTitle = root.querySelector('[data-viz-empty-title]')
    const emptyHint = root.querySelector('[data-viz-empty-hint]')
    const zoomLabel = root.querySelector('[data-viz-zoom-label]')
    const markerEnd = root.querySelector('[data-viz-marker-end]')
    const markerStart = root.querySelector('[data-viz-marker-start]')
    const saveBtn = root.querySelector('[data-viz-save]')
    const saveSep = root.querySelector('[data-viz-save-sep]')
    const saveLabel = root.querySelector('[data-viz-save-label]')
    // The name of the integration being drawn — the canvas no longer DISPLAYS
    // it (the page's top bar does, with the status next to it), but the empty
    // state's label still uses it, and `removeNode()`'s re-render needs it
    // without having to read it back out of the DOM.
    let currentName = ''
    const organizeBtn = root.querySelector('[data-viz-organize]')
    const addNodeBtn = root.querySelector('[data-viz-add-node]')
    const lanesBtn = root.querySelector('[data-viz-lanes]')
    const notesBtn = root.querySelector('[data-viz-add-note]')
    const presentToggleBtn = root.querySelector('[data-viz-present-toggle]')
    const presentIconStart = root.querySelector('[data-viz-present-icon-start]')
    const presentIconStop = root.querySelector('[data-viz-present-icon-stop]')
    const presentSpeedWrap = root.querySelector('[data-viz-present-speed-wrap]')
    const presentSpeedSelect = root.querySelector('[data-viz-present-speed]')
    const exportToggleBtn = root.querySelector('[data-viz-export-toggle]')
    const exportPngBtn = root.querySelector('[data-viz-export-png]')
    const exportGifBtn = root.querySelector('[data-viz-export-gif]')
    const exportStatus = root.querySelector('[data-viz-export-status]')
    const themeSelect = root.querySelector('[data-viz-theme]')
    const laneToolbar = root.querySelector('[data-viz-lane-toolbar]')
    const laneToolbarSwatches = root.querySelector('[data-viz-lane-toolbar-swatches]')
    const laneToolbarHeaderSwatches = root.querySelector('[data-viz-lane-toolbar-header-swatches]')
    const laneToolbarRemove = root.querySelector('[data-viz-lane-toolbar-remove]')
    const laneToolbarRoundedBtn = root.querySelector('[data-viz-lane-toolbar-rounded]')
    const laneToolbarRoundedIcon = root.querySelector('[data-viz-lane-toolbar-rounded-icon]')
    const laneToolbarDashedBtn = root.querySelector('[data-viz-lane-toolbar-dashed]')
    const laneToolbarOrientationBtns = root.querySelectorAll('[data-viz-lane-toolbar-orientation]')
    const laneToolbarFontSize = root.querySelector('[data-viz-lane-toolbar-font-size]')
    const laneToolbarOpacity = root.querySelector('[data-viz-lane-toolbar-opacity]')
    // The `x-forms.toggle` component renders the real `<input type=checkbox>`
    // INSIDE the `<label>` that carries `data-viz-lane-toolbar-title` (a
    // component's `$attributes` only reaches its root element) — so the hook
    // points at the wrapper and the checkbox itself is reached with
    // `.querySelector('input')` on it, the same idea as `viz-text-color-input`
    // having an `id` of its own besides the component's `data-viz-text-color`.
    const laneToolbarTitleWrap = root.querySelector('[data-viz-lane-toolbar-title]')
    const laneToolbarTitleInput = laneToolbarTitleWrap?.querySelector('input') ?? null
    const addEditor = root.querySelector('[data-viz-add-editor]')
    const addKindIcons = root.querySelector('[data-viz-add-kind-icons]')
    const addHint = root.querySelector('[data-viz-add-hint]')
    const bottomBar = root.querySelector('[data-viz-bottombar]')
    const toolbar = root.querySelector('[data-viz-toolbar]')
    const toolbarStyle = root.querySelector('[data-viz-toolbar-style]')
    // Second row: dashed, the light border of an image / "logo only" (both
    // conditional), the block's kind (icons — see `refreshKindRow()` /
    // `changeNodeKind()`) and the actions (comment/delete), all together.
    const toolbarRow2 = root.querySelector('[data-viz-toolbar-row2]')
    const toolbarSwatches = root.querySelector('[data-viz-swatches]')
    const toolbarCustomColor = root.querySelector('[data-viz-custom-color]')
    const toolbarTextColor = root.querySelector('[data-viz-text-color]')
    const toolbarTextColorWrap = root.querySelector('[data-viz-text-color-wrap]')
    const toolbarFont = root.querySelector('[data-viz-font]')
    const toolbarFontSize = root.querySelector('[data-viz-font-size]')
    const toolbarDashedBtn = root.querySelector('[data-viz-toolbar-dashed]')
    const toolbarImageBorderWrap = root.querySelector('[data-viz-toolbar-image-border]')
    const toolbarImageBorderToggle = root.querySelector('[data-viz-toolbar-image-border-toggle]')
    const toolbarImageBorderColor = root.querySelector('[data-viz-image-border-color]')
    const toolbarLogoOnlyWrap = root.querySelector('[data-viz-toolbar-logo-only]')
    const toolbarLogoOnlyToggle = root.querySelector('[data-viz-toolbar-logo-only-toggle]')
    const toolbarComment = root.querySelector('[data-viz-toolbar-comment]')
    const toolbarRenameBtn = root.querySelector('[data-viz-toolbar-rename]')
    const toolbarRemoveBtn = root.querySelector('[data-viz-toolbar-remove]')
    const toolbarRemoveSep = root.querySelector('[data-viz-toolbar-remove-sep]')
    const toolbarKindRow = root.querySelector('[data-viz-toolbar-kind]')
    const toolbarKindIcons = root.querySelector('[data-viz-toolbar-kind-icons]')
    const protocolEditor = root.querySelector('[data-viz-protocol-editor]')
    const protocolArrowLeft = root.querySelector('[data-viz-protocol-arrow-left]')
    const protocolArrowRight = root.querySelector('[data-viz-protocol-arrow-right]')
    const protocolDashedBtn = root.querySelector('[data-viz-protocol-dashed]')
    const protocolDelete = root.querySelector('[data-viz-protocol-delete]')
    const sidebar = root.querySelector('[data-viz-sidebar]')
    const sidebarNode = root.querySelector('[data-viz-sidebar-node]')
    const sidebarInput = root.querySelector('[data-viz-sidebar-input]')
    const sidebarPreview = root.querySelector('[data-viz-sidebar-preview]')
    const sidebarClose = root.querySelector('[data-viz-sidebar-close]')

    const uid = 'akviz' + ++uidCounter
    markerEnd.id = uid + '-end'
    markerStart.id = uid + '-start'

    const view = { x: FIT_PAD, y: FIT_PAD, scale: 1 }
    let nodes = []          // { label, solution, url, comment, logo, environment, cloud, el, w, h, x, y }
    let graphRef = null
    let edgeAnchors = []    // [{from, to, dashed}] por índice de edge (âncora visual — from/to aqui são anchor keys, não nós)
    let lanes = []          // [{label, color, x, y, width, height}] — raias (viz_layout.lanes), puramente visual
    let laneEls = []        // [{wrap, label, handles:{e,s,se}}] — elementos DOM das raias, paralelos a `lanes`
    let notes = []          // [{x, y, text}] — anotações "post-it" (viz_layout.notes), puramente visuais como as raias
    let noteEls = []        // [{wrap, body}] — elementos DOM das anotações, paralelos a `notes`
    let selectedLane = null // index of the lane whose toolbar (colour/name/remove) is open, or null
    let creatingEdge = false // a new link's POST is in flight — see `appendEdgeLocally()`
    let slug = ''
    let editable = false
    let saveUrl = null
    // Where the canvas publishes its own picture after a save (see
    // `publishDiagram()` at the end of `save()`).
    let diagramUrl = null
    let currentTheme = 'original' // see applyTheme() — persisted in viz_layout.theme, live on the canvas AND in the export
    // ── presentation mode — see `enterPresentation()`/`presentTick()` ──
    let presenting = false
    let savedEditableBeforePresenting = false // valor real de `editable` (vindo do servidor), restaurado ao sair
    let presentPaths = []             // computePresentationPaths() do graphRef atual
    let presentDots = []              // estado de execução de cada bolinha — ver startPresentAnimation()
    let presentRafId = null
    let presentLastTs = null          // timestamp do frame anterior — null força o 1º frame a ter dt=0
    let presentSpeedMultiplier = 1    // 0.5–1.5, controlado pelo <select> de velocidade
    const PRESENT_BASE_SPEED = 90      // px de mundo por segundo, em 1x
    let presentRevealedNodes = []     // bool[] por índice de nó — fadeIn é idempotente (ver revealNode())
    let presentRevealedEdges = []     // bool[] por índice de edge — mesma ideia, ver revealEdge()
    let presentFirstLoopPending = 0   // quantas bolinhas ainda não fecharam a 1ª volta
    let presentFallbackFired = false  // já revelou tudo que sobrou ao fim da 1ª volta de todas
    let drag = null         // {type:'handle'|'node', ...} — 'handle' carrega edge/end/origNode/otherNode/targetNode
    let dirty = false
    let selectedIndex = null
    let hoveredEdge = null  // índice da ligação sob o ponteiro (`drawEdgeHit()`) — revela o pill vazio dela
    let commentIndex = null
    let selectedEdge = null // índice em chain.edges com o editor de protocolo aberto
    let edgeLabelEls = []   // <g> de cada pill de protocolo desenhada no draw() atual — base p/ ancorar o input inline de edição de protocolo
    // A local mirror of the link editor's two direction toggles
    // (`data-viz-protocol-arrow-left/right`) — `left` = arrowhead at the origin
    // (`<-`), `right` = arrowhead at the destination (`->`); both together make
    // `<->`. It never ends up with both off: '->'/'<-'/'<->' are the only valid
    // values, so `toggleArrowSide()` ignores the click that would turn off the
    // last active one — see `currentArrowValue()`/`setArrowUI()`.
    let arrowState = { left: false, right: true }
    let pastingImage = false // uma imagem colada por vez — ver handlePasteImage()
    // true when the most recent `render()` applied SAVED positions
    // (`viz_layout`) rather than `layoutDefault()` — the "hidden tab"
    // `ResizeObserver` below only reflows (`layoutDefault()` again) when this is
    // false; a saved layout is not its to reposition.
    let usedCustomLayout = false
    // Filled in only when the "Adicionar bloco" panel opens from dropping an
    // arrow on EMPTY CANVAS (not from the topbar's "+") — see
    // `openQuickAddEditor()`. `quickAddOrigin` is the port the arrow left from
    // (for `createEdgeFrom()` once the block exists); `quickAddPos` is the WORLD
    // point of the drop, so the new block is born there (instead of to the right
    // of the last block, which is what the topbar's "+" does) — the panel itself
    // does not use that point, it always opens in the canvas's fixed corner.
    // Both go back to `null` together in `closeAddEditor()`.
    let quickAddOrigin = null
    let quickAddPos = null
    // Inline protocol editing on the arrow's label itself
    // (`startInlineProtocolEdit()`) — `inlineProtocolInput` is the active
    // floating `<input>` (or `null`), `inlineProtocolReposition` the function
    // that re-anchors it (along with the suggestion box) every time the canvas
    // runs `applyView()`/`draw()`, since this input (unlike the arrow's context
    // panel) goes on living stuck to the pill itself, in SCREEN space rather
    // than in `world`'s.
    let inlineProtocolInput = null
    let inlineProtocolReposition = null
    let inlineProtocolEditIndex = null // index of the edge being edited inline, or null — `drawProtocolPill()` hides its static text
    // Same idea for renaming a lane inline (`startInlineLaneLabelEdit()`) —
    // it re-anchors the label's floating input on every `applyView()`.
    let inlineLaneLabelReposition = null

    function applyView() {
        world.style.transform = `translate(${view.x}px,${view.y}px) scale(${view.scale})`
        // Counter-scaling for the AFFORDANCES (a block's ports, the arrow-end
        // handles, the anchors, the empty protocol pill): they live inside
        // `world`, so the transform above would fatten them along with the
        // drawing — at 220% an 11px port becomes 24px and starts dominating the
        // block it is only supposed to point at. Multiplied by `1/scale` they
        // keep the same ON-SCREEN size at any zoom, which is what one expects
        // of a control (the drawing itself — blocks, text, stroke, arrow, a pill
        // with a protocol written in it — goes on scaling, because it is
        // content).
        root.style.setProperty('--viz-inv-scale', String(1 / view.scale))
        if (zoomLabel) zoomLabel.textContent = Math.round(view.scale * 100) + '%'
        // The "Adicionar bloco" panel opened by a drop is anchored to a WORLD
        // point (`quickAddPos`), so it follows that point through a zoom — like
        // the protocol `<input>` just below. A pan closes the panel first (the
        // pointerdown on the background goes through `selectNode(null)`); a zoom
        // from the wheel or the buttons does not.
        if (quickAddPos) positionAddEditorAt(quickAddPos.x, quickAddPos.y)
        inlineProtocolReposition?.()
        inlineLaneLabelReposition?.()
        // The lane, the block and the arrow themselves need nothing here: they
        // are children of `world` (world space), so pan and zoom already move
        // and scale them for free through the CSS transform above. The context
        // panels (the block's toolbar, the lane's, the protocol editor) no
        // longer re-anchor — they are pinned to `stage`'s corner
        // (excalidraw.com style), so pan and zoom never have to move them.
    }

    function screenToWorld(clientX, clientY) {
        const r = viewport.getBoundingClientRect()
        return {
            x: (clientX - r.left - view.x) / view.scale,
            y: (clientY - r.top - view.y) / view.scale,
        }
    }

    /**
     * Where an arrow touches a block.
     *
     * `t` (0..1) slides the anchor along the SIDE, and exists because of the
     * lifeline: the 8 anchors can say "on the right", not "on the right, at the
     * height of the third step" — which is exactly what a message at one
     * instant in time needs. It applies to the vertical sides (`l`/`r`) only,
     * where sliding means going down; on the horizontal ones there is nothing to
     * slide that is not the choice of anchor itself.
     */
    function anchorPoint(node, key, t = null) {
        const a = ANCHORS[key] ?? ANCHORS.r
        const slide = t !== null && Number.isFinite(t) && a.nx !== 0
        // On a LIFELINE the message touches the dashed line, which is drawn
        // down the block's centre — not the card's edge. Without this the arrow
        // is born and dies in the empty space between two columns, touching
        // neither: that is how the generated sequence looked "arrowless".
        const onLifeline = node.kind === 'lifeline' && a.nx !== 0

        return {
            x: node.x + node.w * (onLifeline ? 0.5 : a.fx),
            y: node.y + node.h * (slide ? Math.min(1, Math.max(0, t)) : a.fy),
            nx: a.nx,
            ny: a.ny,
        }
    }

    /**
     * How far to slide each end ALONG the face it is born on, so that several
     * links sharing a face are not all born at the same point.
     *
     * It was the most visible defect of a crowded drawing: three arrows
     * arriving at the top of a block became a trident with a single vertex,
     * and at that vertex you could neither tell which arrow went where nor
     * pick the one you wanted with the mouse. They share the face now.
     *
     * The order within the face comes from the OTHER end, not from the list of
     * links: what comes from the left lands on the left. Without that the
     * arrows would cross right where they have just separated, which is worse
     * than the trident.
     *
     * An end with an explicit `t` (a message placed on a lifeline) is left
     * out: there the height is content, chosen by whoever generated the
     * drawing.
     */
    function fanOffsets(edgeList) {
        const slots = edgeList.map(() => ({ from: 0, to: 0 }))
        const faces = new Map()

        const claim = (nodeIndex, key, i, end) => {
            if (!nodes[nodeIndex]) return
            const id = nodeIndex + '|' + key
            if (!faces.has(id)) faces.set(id, { nodeIndex, key, ends: [] })
            faces.get(id).ends.push({ i, end })
        }

        edgeList.forEach((edge, i) => {
            const a = edgeAnchors[i] || {}
            if (a.fromT == null) claim(edge.from, a.from ?? 'r', i, 'from')
            if (a.toT == null) claim(edge.to, a.to ?? 'l', i, 'to')
        })

        faces.forEach(({ nodeIndex, key, ends }) => {
            if (ends.length < 2) return

            const node = nodes[nodeIndex]
            const alongY = (ANCHORS[key] ?? ANCHORS.r).nx !== 0
            const other = ({ i, end }) => {
                const peer = nodes[end === 'from' ? edgeList[i].to : edgeList[i].from]

                if (!peer) return 0

                return alongY ? peer.y + peer.h / 2 : peer.x + peer.w / 2
            }
            // A face cannot be shared beyond its own size, and the ends stay
            // away from the corners: an arrow leaving the corner of a rounded
            // block reads as if it were attached to nothing.
            const room = (alongY ? node.h : node.w) - 28
            const step = Math.min(ANCHOR_FAN_STEP, Math.max(0, room) / (ends.length - 1))

            ends
                .slice()
                .sort((a, b) => other(a) - other(b))
                .forEach(({ i, end }, rank) => {
                    slots[i][end] = (rank - (ends.length - 1) / 2) * step
                })
        })

        return slots
    }

    function clearWorld() {
        nodes.forEach((n) => n.el.remove())
        nodes = []
        // Lanes are per-drawing state, not per page session — without this,
        // switching to a drawing with no lanes (or with fewer) would leave the
        // previous one's lanes hanging in `world`: the
        // `nodes.forEach(...).remove()` above clears only the nodes, and none
        // of them is a lane. `entry.wrap.remove()` is enough, since the label
        // and the handles are its children and go with it.
        laneEls.forEach((entry) => entry.wrap.remove())
        laneEls = []
        lanes = []
        noteEls.forEach((entry) => entry.wrap.remove())
        noteEls = []
        notes = []
        clearOverlays()
    }

    function clearOverlays() {
        edges.querySelectorAll('.ak-viz-edge, .ak-viz-plabel').forEach((el) => el.remove())
        hits.replaceChildren()
        world.querySelectorAll('.ak-viz-handle, .ak-viz-anchor').forEach((el) => el.remove())
    }

    function setDirty(value) {
        dirty = value
        if (saveBtn) saveBtn.disabled = !value
    }

    // Applies the theme (Original/Casual/Corporativo/Tech) to the LIVE canvas,
    // not only at export time: `data-viz-preset` stays on `world`, on `edges`
    // (the svg) AND on `viewport` (the canvas's ground, dot grid included —
    // `viewport` is never captured in the export, which passes
    // `EXPORT_PRESETS[...].bg` straight to `toCanvas()`'s `backgroundColor`,
    // but IT is what the user looks at while editing, so it needs the same
    // colour change here) for as long as that theme is active. So the SAME CSS
    // rules the export uses (block/edge/pill, in this component's stylesheet)
    // already paint ordinary editing too, and nothing has to be duplicated
    // between "looking at it" and "exporting it". `markDirty` is `false` only
    // on load (applying the theme already saved in `viz_layout.theme` — that is
    // not a new edit) and `true` on a real choice by the user (which enables
    // "Salvar", the same way moving a block does).
    function applyTheme(theme, { markDirty: shouldMarkDirty = true } = {}) {
        currentTheme = EXPORT_PRESETS[theme] ? theme : 'original'
        if (currentTheme === 'original') {
            delete world.dataset.vizPreset
            delete edges.dataset.vizPreset
            delete viewport.dataset.vizPreset
        } else {
            world.dataset.vizPreset = currentTheme
            edges.dataset.vizPreset = currentTheme
            viewport.dataset.vizPreset = currentTheme
        }
        if (themeSelect && themeSelect.value !== currentTheme) themeSelect.value = currentTheme
        if (shouldMarkDirty && editable) setDirty(true)
    }

    function showEmpty(name) {
        empty.style.display = ''
        refreshEditableUI()
        presentToggleBtn?.classList.add('!hidden') // with no chain loaded there is nothing to present
        exportToggleBtn?.classList.add('!hidden') // likewise — nothing to export
        themeSelect?.closest('[data-viz-theme-wrap]')?.classList.add('!hidden')
        currentName = name || ''
        // A diagram with NO chain at all (name known) and a canvas that has
        // not been handed a graph yet (name empty) are different states, and
        // the second one exists only for the instant between the page loading
        // and `chain-select.js`'s auto-select running. The old copy told people
        // to "pick one from the list" — that list was the solution's rail of
        // integrations, which no longer exists.
        if (emptyTitle) emptyTitle.textContent = name || 'Nada desenhado ainda'
        if (emptyHint) {
            emptyHint.textContent = name
                ? 'Este diagrama ainda não tem nenhum bloco.'
                : 'Use o "+" na barra de cima para criar o primeiro bloco.'
        }
    }

    function render(graph, name, slugArg) {
        // Switching the selected drawing re-renders this SAME mounted instance
        // (`clearWorld()` just below destroys every node and edge) — without
        // leaving the presentation first, `presentTick()`'s rAF would go on
        // running against detached elements.
        if (presenting) exitPresentation()
        selectNode(null)
        closeComment()
        closeAddEditor()
        closeLaneToolbar()
        clearWorld()
        graphRef = graph
        slug = slugArg || ''
        editable = !!graph?.editable
        saveUrl = graph?.saveUrl ?? null
        diagramUrl = graph?.diagramUrl ?? null

        if (!graph || !Array.isArray(graph.nodes) || graph.nodes.length === 0) {
            showEmpty(name)
            return
        }
        empty.style.display = 'none'
        currentName = name || ''
        refreshEditableUI()
        presentToggleBtn?.classList.remove('!hidden')
        exportToggleBtn?.classList.remove('!hidden')
        themeSelect?.closest('[data-viz-theme-wrap]')?.classList.remove('!hidden')

        graph.nodes.forEach((data, i) => {
            const el = document.createElement('div')
            el.className = 'ak-viz-node'
            paintNode(el, data)

            el.addEventListener('pointerdown', (e) => startNodePointer(e, i))
            el.addEventListener('dblclick', () => startInlineLabelEdit(i))
            world.appendChild(el)
            nodes.push({ ...data, el, w: 0, h: 0, x: 0, y: 0, color: null, textColor: null, font: 'sans', fontSize: 'sm', imageBorderColor: null })
        })
        nodes.forEach((n) => {
            n.w = n.el.offsetWidth
            n.h = n.el.offsetHeight
        })

        // Same reasoning as `appendNode()`: an image node's <img> loads
        // asynchronously, so it can still be 0×0 above — remeasure once it
        // actually loads and redo the layout that was computed from the
        // wrong size (only when no saved layout owns the positions —
        // `usedCustomLayout`, set right below; read here lazily since this
        // listener only fires later, well after that assignment runs).
        nodes.forEach((n) => {
            if (n.kind !== 'image') return
            const img = n.el.querySelector('img')
            if (!img || img.complete) return
            img.addEventListener('load', () => {
                n.w = n.el.offsetWidth
                n.h = n.el.offsetHeight
                if (!usedCustomLayout) {
                    layoutDefault()
                    nodes.forEach((m) => {
                        m.el.style.left = m.x + 'px'
                        m.el.style.top = m.y + 'px'
                    })
                }
                // Never redraw over a presentation already running: draw()
                // rebuilds every edge path, which would invalidate the
                // `pathEl`/`length` the travelling dots have already cached
                // (`startPresentAnimation()`). The only cost is an image whose
                // anchor is slightly out of date in that rare case (a slow
                // load plus entering the presentation before it finishes),
                // which is acceptable.
                if (!presenting) draw()
            }, { once: true })
        })

        layoutDefault()
        // One visual anchor per link (`graph.edges`), not per consecutive pair
        // of nodes — the chain is a free graph, so the number of edges is
        // independent of the number of nodes.
        edgeAnchors = Array.from({ length: (graph.edges || []).length }, () => ({ from: 'r', to: 'l', dashed: false }))

        const layoutToApply = savedLayouts.get(slug) ?? graph.layout
        // The same condition `applyLayout()` uses internally to know whether
        // it will OVERWRITE `layoutDefault()` with saved positions — the
        // "hidden tab" `ResizeObserver` further down reads this SAME variable
        // to know whether it may reflow (`layoutDefault()` again, with no saved
        // layout in play) or only re-measure w/h (the positions came from
        // `viz_layout` and are not its to move).
        usedCustomLayout = Array.isArray(layoutToApply?.nodes) && layoutToApply.nodes.length === nodes.length
        applyLayout(layoutToApply)
        // `logoOnly` only arrives after `applyLayout()` (it comes from the
        // saved `viz_layout`), but `paintNode()` already ran for every node
        // above without knowing it yet — repaint the ones that need it now,
        // re-measuring w/h (their content went from a card to a bare image).
        nodes.forEach((n) => {
            if (!n.logoOnly) return
            paintNode(n.el, n)
            n.w = n.el.offsetWidth
            n.h = n.el.offsetHeight
        })
        nodes.forEach((n) => {
            n.el.style.left = n.x + 'px'
            n.el.style.top = n.y + 'px'
            applyNodeStyle(n)
        })

        toolbarStyle?.classList.toggle('!hidden', !editable)
        toolbarRow2?.classList.toggle('!hidden', !editable)

        draw()
        setDirty(false)
        fit()
    }

    // A block's background colour / text colour / font — it overrides the
    // theme's CSS default only where the user chose something; a null
    // `textColor` is recomputed from the contrast against `color` (the same
    // rule the reference mind map uses).
    function applyNodeStyle(n) {
        n.el.style.background = n.color || ''
        n.el.style.color = n.textColor || (n.color ? textColorFor(n.color) : '')
        n.el.style.fontFamily = FONTS[n.font] || FONTS.sans
        n.el.style.fontSize = FONT_SIZES[n.fontSize] || FONT_SIZES.sm
        n.el.classList.toggle('is-dashed', !!n.dashed)
        // Optional light border — images only
        // (`viz_layout.nodes[i].imageBorderColor`); guarded by `kind` so an
        // inline `border` is never written on the other kinds, whose outline is
        // class CSS alone (`.is-dashed` and friends).
        if (n.kind === 'image') n.el.style.border = n.imageBorderColor ? `1.5px solid ${n.imageBorderColor}` : ''
        // A lifeline's height is the one size that comes from the layout rather
        // than from the content: the block's body IS the empty room under the
        // header, and that is what the messages cross.
        if (n.kind === 'lifeline') n.el.style.height = Number.isFinite(n.height) ? `${n.height}px` : ''
    }

    // The default left-to-right layout, centres on the line y=0.
    function layoutDefault() {
        let x = 0
        nodes.forEach((n) => {
            n.x = x
            n.y = -n.h / 2
            x += n.w + LEVEL_GAP
        })
    }

    // "Organizar": repositions the blocks and resets the arrows' anchors to
    // the default, nothing else — labels and topology are untouched. Each
    // edge's `dashed` is preserved (only from/to go back to the default).
    function organize() {
        if (!nodes.length) return
        layoutDefault()
        edgeAnchors = edgeAnchors.map((a) => ({ from: 'r', to: 'l', dashed: !!a.dashed }))
        nodes.forEach((n) => {
            n.el.style.left = n.x + 'px'
            n.el.style.top = n.y + 'px'
        })
        draw()
        setDirty(true)
        fit()
    }

    // Applies a saved layout (positions + anchors + comments + lanes) when it
    // is compatible with the current chain. `lanes` was already reset by
    // `clearWorld()` at the start of `render()` — this only overwrites when the
    // saved layout actually carries something, and always ends by redrawing the
    // lanes, empty or not.
    function applyLayout(layout) {
        applyTheme(typeof layout?.theme === 'string' ? layout.theme : 'original', { markDirty: false })
        if (!layout) {
            rebuildLanes()
            rebuildNotes()
            return
        }
        if (Array.isArray(layout.nodes) && layout.nodes.length === nodes.length) {
            nodes.forEach((n, i) => {
                const p = layout.nodes[i]
                if (p && Number.isFinite(p.x) && Number.isFinite(p.y)) {
                    n.x = p.x
                    n.y = p.y
                }
                if (isHex(p?.color)) n.color = p.color
                if (isHex(p?.textColor)) n.textColor = p.textColor
                if (p && FONTS[p.font]) n.font = p.font
                if (p && FONT_SIZES[p.fontSize]) n.fontSize = p.fontSize
                if (p && typeof p.dashed === 'boolean') n.dashed = p.dashed
                if (isHex(p?.imageBorderColor)) n.imageBorderColor = p.imageBorderColor
                if (p && typeof p.logoOnly === 'boolean') n.logoOnly = p.logoOnly
                // Only a lifeline has a stored height — see the note in
                // SaveChainLayoutRequest.
                if (p && Number.isFinite(p.height)) n.height = p.height
            })
        }
        if (Array.isArray(layout.edges) && layout.edges.length === edgeAnchors.length) {
            layout.edges.forEach((e, i) => {
                if (e && ANCHORS[e.from] && ANCHORS[e.to]) {
                    edgeAnchors[i] = {
                        from: e.from,
                        to: e.to,
                        dashed: !!e.dashed,
                        fromT: Number.isFinite(e.fromT) ? e.fromT : null,
                        toT: Number.isFinite(e.toT) ? e.toT : null,
                    }
                }
            })
        }
        if (Array.isArray(layout.comments) && layout.comments.length === nodes.length) {
            layout.comments.forEach((c, i) => {
                if (typeof c === 'string' && c.trim()) {
                    nodes[i].comment = c
                    nodes[i].el.classList.add('has-comment')
                }
            })
        }
        if (Array.isArray(layout.lanes)) {
            const clampSize = (v, fallback) => (Number.isFinite(v) ? Math.round(Math.max(LANE_MIN_SIZE, Math.min(LANE_MAX_SIZE, v))) : fallback)
            const clampOpacity = (v) => (Number.isFinite(v) ? Math.max(LANE_OPACITY_MIN, Math.min(LANE_OPACITY_MAX, v)) : LANE_STYLE_DEFAULTS.opacity)
            lanes = layout.lanes
                .filter((l) => l && typeof l.label === 'string')
                .map((l) => ({
                    // Backfill: a lane saved before one of these fields
                    // existed does not carry the key — `LANE_STYLE_DEFAULTS`
                    // covers the hole, and the checks below treat any value
                    // that is present but unexpected (wrong enum, wrong type)
                    // the same way, falling back to the default.
                    ...LANE_STYLE_DEFAULTS,
                    label: l.label,
                    color: isHex(l.color) ? l.color : LANE_COLORS[0],
                    // ABSENT, rather than a key present holding `null`, on
                    // purpose: that is the signal `laneHeaderColor()` reads to
                    // know the header is still automatic (darkened from
                    // `color`) instead of an explicit choice.
                    ...(isHex(l.headerColor) ? { headerColor: l.headerColor } : {}),
                    x: Number.isFinite(l.x) ? l.x : 0,
                    y: Number.isFinite(l.y) ? l.y : 0,
                    width: clampSize(l.width, LANE_DEFAULT_WIDTH),
                    height: clampSize(l.height, LANE_DEFAULT_HEIGHT),
                    rounded: typeof l.rounded === 'boolean' ? l.rounded : LANE_STYLE_DEFAULTS.rounded,
                    dashed: typeof l.dashed === 'boolean' ? l.dashed : LANE_STYLE_DEFAULTS.dashed,
                    opacity: clampOpacity(l.opacity),
                    orientation: l.orientation === 'vertical' ? 'vertical' : LANE_STYLE_DEFAULTS.orientation,
                    showTitle: typeof l.showTitle === 'boolean' ? l.showTitle : LANE_STYLE_DEFAULTS.showTitle,
                    fontSize: LANE_FONT_SIZES[l.fontSize] ? l.fontSize : LANE_STYLE_DEFAULTS.fontSize,
                }))
        }
        if (Array.isArray(layout.notes)) {
            notes = layout.notes
                .filter((n) => n && Number.isFinite(n.x) && Number.isFinite(n.y))
                .map((n) => ({ x: n.x, y: n.y, text: typeof n.text === 'string' ? n.text : '' }))
        }
        rebuildLanes()
        rebuildNotes()
    }

    // Bounding box (world space) of every node — used by `fit()`, and `null`
    // when there is no node at all. Lanes are NOT counted: `fit()` frames the
    // BLOCKS, and an empty lane dragged far away from them should not pull the
    // framing along behind it.
    function nodesBBox() {
        if (!nodes.length) return null
        let minX = Infinity
        let minY = Infinity
        let maxX = -Infinity
        let maxY = -Infinity
        nodes.forEach((n) => {
            minX = Math.min(minX, n.x)
            minY = Math.min(minY, n.y)
            maxX = Math.max(maxX, n.x + n.w)
            maxY = Math.max(maxY, n.y + n.h)
        })
        return { minX, minY, maxX, maxY }
    }

    // The union of `nodesBBox()` with the lanes AND the notes — used ONLY by
    // the export (`captureDiagramCanvas()`), never by `fit()`: a lane resized
    // larger than the current cluster of blocks (common — people leave "room to
    // grow"), or a post-it sitting far from any block, has to be inside the
    // exported crop even with no node there, or it comes out cut off in the
    // final image. A note's size is not persisted (only `x`/`y` — see
    // `rebuildNotes()`), so it is measured straight from the DOM here, with the
    // same fallback `rebuildNotes()` uses when the element is not mounted yet.
    function contentBBox() {
        const nb = nodesBBox()
        if (!nb && !lanes.length && !notes.length) return null
        let { minX, minY, maxX, maxY } = nb || { minX: Infinity, minY: Infinity, maxX: -Infinity, maxY: -Infinity }
        lanes.forEach((l) => {
            minX = Math.min(minX, l.x)
            minY = Math.min(minY, l.y)
            maxX = Math.max(maxX, l.x + l.width)
            maxY = Math.max(maxY, l.y + l.height)
        })
        notes.forEach((note, i) => {
            const w = noteEls[i]?.wrap.offsetWidth || NOTE_DEFAULT_WIDTH
            const h = noteEls[i]?.wrap.offsetHeight || NOTE_MIN_HEIGHT
            minX = Math.min(minX, note.x)
            minY = Math.min(minY, note.y)
            maxX = Math.max(maxX, note.x + w)
            maxY = Math.max(maxY, note.y + h)
        })
        return { minX, minY, maxX, maxY }
    }

    // ── lanes — free background rectangles, purely visual ──────
    // Rebuilds the lanes' `<div>`s from `lanes` from scratch (called whenever
    // the list changes: add/remove/recolour/rename/move/resize) — the count is
    // small, so recreating everything is simpler than patching incrementally.
    // Each lane is ONE lane (coloured area + label + 3 resize handles, all
    // children of the same `wrap`) and a child of `world` — WORLD space,
    // exactly like a block: `x`/`y`/`width`/`height` become
    // `left`/`top`/`width`/`height` in CSS with no conversion at all, and
    // pan/zoom move and scale them for free through `world`'s transform
    // (nothing to recompute in `applyView()`). That is only possible because a
    // lane stopped being forced to 100% of the viewport's width — the earlier
    // version (a lane was always a full-width horizontal band, stacked with its
    // neighbours) lived in SCREEN space on purpose, to keep that width pinned
    // to the viewport whatever the zoom; a free rectangle has no such reason.
    //
    // Everything is inserted BEFORE the nodes in `world` (`prepend`) — document
    // order, not a negative z-index (the usual lesson: neither
    // `.ak-viz-viewport` nor `[data-ak-chain-viz]` establishes a stacking
    // context of its own, so a negative z-index would escape and paint behind
    // the whole component's `bg-surface` instead of just behind the nodes). So
    // a block over a lane is always visible and clickable, and only the part of
    // the lane NOT covered by a block answers a drag of its own body (move) or
    // of its handles (resize).
    function rebuildLanes() {
        laneEls.forEach((entry) => entry.wrap.remove())
        laneEls = lanes.map((lane, i) => {
            const wrap = document.createElement('div')
            wrap.className = 'ak-viz-lane'
            wrap.classList.toggle('is-vertical', lane.orientation === 'vertical')
            wrap.classList.toggle('is-rounded', !!lane.rounded)
            wrap.style.left = lane.x + 'px'
            wrap.style.top = lane.y + 'px'
            wrap.style.width = lane.width + 'px'
            wrap.style.height = lane.height + 'px'
            // The fill is subtle on purpose (adjustable opacity,
            // `laneBackgroundCss()`), but the BORDER — solid or dashed,
            // `lane.dashed` — has to read as the rectangle's real outline, and
            // the title, at full colour, is the most vivid thing of all.
            wrap.style.background = laneBackgroundCss(lane)
            wrap.style.borderColor = hexToRgba(lane.color, 0.7)
            wrap.style.borderStyle = lane.dashed ? 'dashed' : 'solid'
            // Dragging the whole BODY (outside the label and the handles)
            // moves the lane (`x`/`y`) — but only the label opens the
            // colour/name/remove toolbar on a click without a drag (`onLabel`,
            // see the global pointerup); clicking the rest of the body without
            // dragging does nothing. The exception: with no title
            // (`showTitle === false`) there is no separate strip to reserve as
            // the selection target, so the whole body takes the label's role —
            // the same click-versus-drag distinction a block makes
            // (`drag.moved`, see `startNodePointer()`), except that here
            // whether a pure click becomes a selection depends on WHICH part of
            // the rectangle started the gesture.
            wrap.addEventListener('pointerdown', (e) => {
                if (e.button !== 0 || !editable) return
                e.stopPropagation()
                e.preventDefault()
                drag = { type: 'lane-move', index: i, startClientX: e.clientX, startClientY: e.clientY, startX: lane.x, startY: lane.y, moved: false, onLabel: lane.showTitle === false }
            })
            // With no title the whole body takes the label's role, renaming
            // included (`startInlineLaneLabelEdit()`) — the same
            // `showTitle === false` distinction as everything else in this
            // function.
            wrap.addEventListener('dblclick', (e) => {
                if (!editable || lane.showTitle !== false) return
                e.stopPropagation()
                startInlineLaneLabelEdit(i)
            })

            // The label: a full-height or full-width strip carrying the TITLE
            // — on the left edge with vertical text (horizontal orientation,
            // the classic band) or along the top with ordinary left-to-right
            // text (vertical orientation, `.is-vertical` above). Its colour is
            // independent of the body's (`laneHeaderColor()`), and the text
            // colour is picked by contrast (`textColorFor()`) rather than fixed
            // white, since the header can now be light (white/beige). It
            // carries `pointer-events: auto` of its own (when editable) so it
            // can be the one target that opens the toolbar on a click — and it
            // needs its own pointerdown (with `stopPropagation`) instead of
            // letting the event bubble to `wrap`, or the `onLabel` above would
            // always read `false`. With no title (`showTitle === false`) it
            // leaves the screen and `wrap` takes over the selection; it is not
            // removed from the DOM, so `rebuildLanes()` stays simple to rebuild
            // from scratch.
            const label = document.createElement('span')
            label.className = 'ak-viz-lane-label'
            const headerColor = laneHeaderColor(lane)
            label.style.background = headerColor
            label.style.color = textColorFor(headerColor)
            label.style.fontSize = LANE_FONT_SIZES[lane.fontSize] || LANE_FONT_SIZES.sm
            label.style.display = lane.showTitle === false ? 'none' : ''
            label.textContent = lane.label
            label.addEventListener('pointerdown', (e) => {
                if (e.button !== 0 || !editable) return
                e.stopPropagation()
                e.preventDefault()
                drag = { type: 'lane-move', index: i, startClientX: e.clientX, startClientY: e.clientY, startX: lane.x, startY: lane.y, moved: false, onLabel: true }
            })
            // Renaming straight on the header — a double click swaps the
            // static `<span>` for an overlaid `<input>`, the same idea as
            // `startInlineLabelEdit()` on a block (see that function below).
            label.addEventListener('dblclick', (e) => {
                if (!editable) return
                e.stopPropagation()
                startInlineLaneLabelEdit(i)
            })
            wrap.appendChild(label)

            // 3 resize handles — right (width only), bottom (height only)
            // and the corner (both at once), the same pattern as any rectangle
            // editor (Figma, Miro, Excalidraw). Interactive only when editable,
            // through the same CSS attribute the label above uses. The corner
            // is appended last (after CSS has positioned it) purely so it beats
            // the bottom and right handles on the exact pixel where all three
            // meet.
            const handles = {}
            ;['e', 's', 'se'].forEach((dir) => {
                const handle = document.createElement('div')
                handle.className = `ak-viz-lane-resize ak-viz-lane-resize-${dir}`
                handle.addEventListener('pointerdown', (e) => {
                    if (e.button !== 0 || !editable) return
                    e.stopPropagation()
                    e.preventDefault()
                    drag = {
                        type: 'lane-resize',
                        index: i,
                        dir,
                        startClientX: e.clientX,
                        startClientY: e.clientY,
                        startW: lane.width,
                        startH: lane.height,
                    }
                    handle.classList.add('is-resizing')
                })
                handles[dir] = handle
                wrap.appendChild(handle)
            })

            return { wrap, label, handles }
        })
        if (laneEls.length) world.prepend(...laneEls.map((entry) => entry.wrap))
    }

    function removeLane(index) {
        lanes.splice(index, 1)
        closeLaneToolbar()
        rebuildLanes()
        setDirty(true)
    }

    // Born centred on the CURRENT viewport, not at a fixed corner of the
    // world — the same idea as `appendNode()` being born near what already
    // exists: the new lane probably belongs near what the person is looking at
    // now, not at (0,0). No panel or dialog in the way: clicking the topbar's
    // "Raias" button creates and selects the lane outright (`selectLane()`
    // opens the toolbar right away, ready to rename).
    function addLane() {
        if (!editable || !graphRef) return
        const vpRect = viewport.getBoundingClientRect()
        const center = screenToWorld(vpRect.left + vpRect.width / 2, vpRect.top + vpRect.height / 2)
        lanes.push({
            ...LANE_STYLE_DEFAULTS,
            label: `Raia ${lanes.length + 1}`,
            color: LANE_COLORS[lanes.length % LANE_COLORS.length],
            x: Math.round(center.x - LANE_DEFAULT_WIDTH / 2),
            y: Math.round(center.y - LANE_DEFAULT_HEIGHT / 2),
            width: LANE_DEFAULT_WIDTH,
            height: LANE_DEFAULT_HEIGHT,
        })
        rebuildLanes()
        setDirty(true)
        selectLane(lanes.length - 1)
    }

    // ── "post-it" notes — free multiline text, purely visual ──
    // The same spirit as the lanes (a child of `world`, in WORLD space, pan and
    // zoom for free through `world`'s transform) but deliberately much simpler
    // (a "basic note"): no toolbar of its own, no configurable colour (always
    // the post-it yellow), no resizing — just position (dragging the small
    // strip at the top) and the text itself, which grows with its content.
    // Unlike the lanes, they go in AFTER the nodes in `world` (`append`, not
    // `prepend`): a post-it is stuck ON TOP of the drawing, not behind it.
    function rebuildNotes() {
        noteEls.forEach((entry) => entry.wrap.remove())
        function autosize(body) {
            body.style.height = 'auto'
            body.style.height = body.scrollHeight + 'px'
        }
        noteEls = notes.map((note, i) => {
            const wrap = document.createElement('div')
            wrap.className = 'ak-viz-note'
            wrap.style.left = note.x + 'px'
            wrap.style.top = note.y + 'px'
            // A slight rotation alternating by index — the hand-stuck air of
            // a real post-it, without becoming a caricature (the same "sober
            // with a soul" dose as the rest of the app). Purely decorative and
            // not persisted: every load recomputes it from the position in the
            // list rather than from a stored value.
            wrap.style.transform = `rotate(${(i % 2 === 0 ? -1 : 1) * (1 + (i % 3) * 0.5)}deg)`

            // The strip at the top: the only part that drags (move) and the
            // one carrying the remove button — the body below is all editable
            // text, so it needs a "neutral" area to act as a handle, the same
            // spirit as a lane's label (`rebuildLanes()`).
            const handle = document.createElement('div')
            handle.className = 'ak-viz-note-handle'
            handle.addEventListener('pointerdown', (e) => {
                if (e.button !== 0 || !editable) return
                e.stopPropagation()
                e.preventDefault()
                drag = { type: 'note-move', index: i, startClientX: e.clientX, startClientY: e.clientY, startX: note.x, startY: note.y, moved: false }
            })

            const removeBtn = document.createElement('button')
            removeBtn.type = 'button'
            removeBtn.className = 'ak-viz-note-remove'
            removeBtn.title = 'Remover anotação'
            removeBtn.setAttribute('aria-label', 'Remover anotação')
            removeBtn.innerHTML = '&times;'
            removeBtn.addEventListener('pointerdown', (e) => e.stopPropagation())
            removeBtn.addEventListener('click', (e) => {
                e.stopPropagation()
                removeNote(i)
            })
            handle.appendChild(removeBtn)
            wrap.appendChild(handle)

            // The body is a `<textarea>`, not a `contenteditable` — no
            // markdown, no preview, it is a basic note, and it grows with its
            // text (`autosize()`), which is why no `height` is persisted, only
            // `x`/`y` (see `save()`). That choice is not aesthetic: pressing
            // Enter inside a `contenteditable` inserts elements (`<div>` or
            // `<br>`, depending on the browser) and reading `.textContent` back
            // FLATTENS all of it into one string with no line breaks at all —
            // unacceptable for a note that has to be genuinely multiline. A
            // `<textarea>`'s `.value` preserves `\n` for free. Its pointerdown
            // stops propagation (without `preventDefault`, which would keep the
            // text caret from being placed) so clicking the text never starts a
            // canvas drag.
            const body = document.createElement('textarea')
            body.className = 'ak-viz-note-body'
            body.rows = 1
            body.placeholder = 'Escreva aqui…'
            body.value = note.text || ''
            body.readOnly = !editable
            body.addEventListener('pointerdown', (e) => { if (editable) e.stopPropagation() })
            body.addEventListener('input', () => {
                notes[i].text = body.value
                setDirty(true)
                autosize(body)
            })
            wrap.appendChild(body)

            return { wrap, body }
        })
        if (noteEls.length) {
            world.append(...noteEls.map((entry) => entry.wrap))
            noteEls.forEach((entry) => autosize(entry.body))
        }
    }

    function removeNote(index) {
        notes.splice(index, 1)
        rebuildNotes()
        setDirty(true)
    }

    // Born centred on the CURRENT viewport, the same idea as `addLane()` — and
    // it arrives focused, ready to type, since there is no separate toolbar
    // that would open with it to point at the next step.
    function addNote() {
        if (!editable || !graphRef) return
        const vpRect = viewport.getBoundingClientRect()
        const center = screenToWorld(vpRect.left + vpRect.width / 2, vpRect.top + vpRect.height / 2)
        notes.push({
            x: Math.round(center.x - NOTE_DEFAULT_WIDTH / 2),
            y: Math.round(center.y - NOTE_MIN_HEIGHT / 2),
            text: '',
        })
        rebuildNotes()
        setDirty(true)
        noteEls[notes.length - 1]?.body.focus()
    }

    // ── the selected lane's toolbar (colour/name/remove) ──────────────
    // Opened by a click (without a drag) on any lane — the same spirit as a
    // block's contextual toolbar (`selectNode()`), but with colour (presets,
    // `buildLaneSwatches()`) plus name (a direct input, no separate pencil)
    // plus remove, since a lane has no title/comment/link to edit. Mutually
    // exclusive with the block's toolbar: `selectNode()` closes this one, and
    // this one closes that.
    function selectLane(index) {
        if (!editable || !laneEls[index]) return
        selectNode(null)
        closeProtocolEditor()
        closeAddEditor()
        closeLaneToolbar()
        selectedLane = index
        laneEls[index].wrap.classList.add('is-selected')
        buildLaneSwatches()
        buildLaneHeaderSwatches()
        refreshLaneToolbarControls()
        laneToolbar?.classList.remove('hidden')
        laneToolbar?.classList.add('flex')
    }

    // The element the lane's toolbar and selection anchor to — the label when
    // there is one (`showTitle` !== false), the whole body when there is not
    // (with no dedicated strip to anchor to, `wrap` itself takes the role).
    function laneAnchorEl(index) {
        const entry = laneEls[index]
        return lanes[index]?.showTitle === false ? entry.wrap : entry.label
    }

    // ── renaming straight on the header, by double click ──────────────────
    // It overlays a floating `<input>` (a child of `stage`, in SCREEN space —
    // the same convention as `startInlineProtocolEdit()`) centred on the label,
    // instead of swapping the static `<span>` in place the way
    // `startInlineLabelEdit()` does on a block: the label is only 26px wide or
    // tall (the swimlane's strip) and, in the horizontal orientation, carries
    // vertical text (`writing-mode`) — neither the room nor the orientation
    // suits typing directly. With no title (`showTitle === false`) the anchor
    // is the whole body, potentially enormous (up to `LANE_MAX_SIZE`), so the
    // centre used is that of its intersection with the stage's VISIBLE area
    // rather than of the whole rectangle — otherwise the input would be born
    // off screen on a large lane.
    function startInlineLaneLabelEdit(index) {
        if (!editable || !lanes[index]) return
        const entry = laneEls[index]
        if (!entry) return
        const lane = lanes[index]
        selectLane(index)

        const anchorEl = lane.showTitle === false ? entry.wrap : entry.label

        const input = document.createElement('input')
        input.type = 'text'
        input.className = 'ak-viz-lane-label-input'
        input.value = lane.label
        input.autocomplete = 'off'
        input.spellcheck = false
        stage.appendChild(input)

        function position() {
            const rect = anchorEl.getBoundingClientRect()
            const stageRect = stage.getBoundingClientRect()
            const visLeft = Math.max(rect.left, stageRect.left)
            const visTop = Math.max(rect.top, stageRect.top)
            const visRight = Math.min(rect.right, stageRect.right)
            const visBottom = Math.min(rect.bottom, stageRect.bottom)
            input.style.left = ((visLeft + visRight) / 2 - stageRect.left) + 'px'
            input.style.top = ((visTop + visBottom) / 2 - stageRect.top) + 'px'
        }
        position()
        input.focus()
        input.select()

        function cleanup() {
            input.removeEventListener('blur', onBlur)
            input.removeEventListener('keydown', onKeydown)
            input.remove()
            inlineLaneLabelReposition = null
        }

        function commit() {
            const newLabel = input.value.trim() || 'Raia'
            cleanup()
            if (newLabel === lane.label) return
            lane.label = newLabel
            entry.label.textContent = newLabel
            setDirty(true)
        }

        function cancel() { cleanup() }

        const onKeydown = (e) => {
            if (e.key === 'Escape') { e.preventDefault(); cancel(); return }
            if (e.key === 'Enter') { e.preventDefault(); commit() }
        }
        const onBlur = () => commit()

        inlineLaneLabelReposition = position
        input.addEventListener('keydown', onKeydown)
        input.addEventListener('blur', onBlur)
    }

    function closeLaneToolbar() {
        if (selectedLane !== null && laneEls[selectedLane]) laneEls[selectedLane].wrap.classList.remove('is-selected')
        selectedLane = null
        if (!laneToolbar || laneToolbar.classList.contains('hidden')) return
        laneToolbar.classList.add('hidden')
        laneToolbar.classList.remove('flex')
    }

    // Applies the HEADER's colour/text/size to the DOM already mounted —
    // called whenever `color` (the automatic one can move), `headerColor` or
    // `fontSize` changes, and it never has to know which of the three it was.
    function applyLaneHeaderStyle(lane, entry) {
        if (!entry) return
        const headerColor = laneHeaderColor(lane)
        entry.label.style.background = headerColor
        entry.label.style.color = textColorFor(headerColor)
        entry.label.style.fontSize = LANE_FONT_SIZES[lane.fontSize] || LANE_FONT_SIZES.sm
    }

    // Fixed presets (`LANE_COLORS`), the same pattern as `buildSwatches()` for
    // a block — no custom colour here, on purpose, only the predefined ones.
    // This is the BODY's colour (fill plus border); `buildLaneHeaderSwatches()`
    // just below is the same palette for the header, independently.
    function buildLaneSwatches() {
        if (!laneToolbarSwatches || selectedLane === null) return
        const current = lanes[selectedLane]?.color
        laneToolbarSwatches.innerHTML = ''
        LANE_COLORS.forEach((color) => {
            const sw = document.createElement('button')
            sw.type = 'button'
            sw.className = 'size-[22px] shrink-0 cursor-pointer rounded-md border border-black/10 transition-transform hover:scale-110'
            sw.style.background = color
            sw.title = color
            sw.style.boxShadow = current && current.toLowerCase() === color.toLowerCase()
                ? '0 0 0 2px var(--viz-bg), 0 0 0 3.5px var(--viz-select)'
                : ''
            sw.addEventListener('click', () => setLaneColor(color))
            laneToolbarSwatches.appendChild(sw)
        })
    }

    function setLaneColor(color) {
        if (selectedLane === null || !lanes[selectedLane]) return
        const lane = lanes[selectedLane]
        lane.color = color
        const entry = laneEls[selectedLane]
        if (entry) {
            entry.wrap.style.background = laneBackgroundCss(lane)
            entry.wrap.style.borderColor = hexToRgba(color, 0.7)
            // This only really shows if the header is still automatic
            // (`laneHeaderColor()` decides that by itself) — an explicit header
            // colour does not move when the body does.
            applyLaneHeaderStyle(lane, entry)
        }
        buildLaneSwatches()
        setDirty(true)
    }

    // The same palette and pattern as `buildLaneSwatches()`, for the header —
    // a colour independent of the body's (`lane.headerColor`; absent means
    // automatic, via `laneHeaderColor()`). No swatch reads as "selected" while
    // the header is automatic, which is right: no explicit choice has been made
    // yet.
    function buildLaneHeaderSwatches() {
        if (!laneToolbarHeaderSwatches || selectedLane === null) return
        const current = lanes[selectedLane]?.headerColor
        laneToolbarHeaderSwatches.innerHTML = ''
        LANE_COLORS.forEach((color) => {
            const sw = document.createElement('button')
            sw.type = 'button'
            sw.className = 'size-[22px] shrink-0 cursor-pointer rounded-md border border-black/10 transition-transform hover:scale-110'
            sw.style.background = color
            sw.title = color
            sw.style.boxShadow = current && current.toLowerCase() === color.toLowerCase()
                ? '0 0 0 2px var(--viz-bg), 0 0 0 3.5px var(--viz-select)'
                : ''
            sw.addEventListener('click', () => setLaneHeaderColor(color))
            laneToolbarHeaderSwatches.appendChild(sw)
        })
    }

    function setLaneHeaderColor(color) {
        if (selectedLane === null || !lanes[selectedLane]) return
        const lane = lanes[selectedLane]
        lane.headerColor = color
        applyLaneHeaderStyle(lane, laneEls[selectedLane])
        buildLaneHeaderSwatches()
        setDirty(true)
    }

    function setLaneFontSize(size) {
        if (selectedLane === null || !lanes[selectedLane] || !LANE_FONT_SIZES[size]) return
        const lane = lanes[selectedLane]
        lane.fontSize = size
        applyLaneHeaderStyle(lane, laneEls[selectedLane])
        setDirty(true)
    }

    function setLaneOpacity(rawValue) {
        if (selectedLane === null || !lanes[selectedLane]) return
        const opacity = Math.max(LANE_OPACITY_MIN, Math.min(LANE_OPACITY_MAX, Number(rawValue) || LANE_STYLE_DEFAULTS.opacity))
        lanes[selectedLane].opacity = opacity
        const entry = laneEls[selectedLane]
        if (entry) entry.wrap.style.background = laneBackgroundCss(lanes[selectedLane])
        setDirty(true)
    }

    // Mirrors the selected lane's state onto the toolbar's controls — called
    // whenever `selectLane()` opens (a lane may have been edited by another
    // path, or the toolbar may be reopening on a different lane) and after each
    // local toggle, the same spirit as `refreshToolbarControls()` for a block.
    function refreshLaneToolbarControls() {
        const lane = lanes[selectedLane]
        if (!lane) return
        if (laneToolbarRoundedBtn) {
            laneToolbarRoundedBtn.classList.toggle('!bg-accent-soft', !!lane.rounded)
            laneToolbarRoundedBtn.setAttribute('aria-pressed', String(!!lane.rounded))
        }
        if (laneToolbarRoundedIcon) {
            laneToolbarRoundedIcon.classList.toggle('rounded-md', !!lane.rounded)
            laneToolbarRoundedIcon.classList.toggle('rounded-none', !lane.rounded)
        }
        if (laneToolbarDashedBtn) {
            laneToolbarDashedBtn.classList.toggle('border-dashed', !!lane.dashed)
            laneToolbarDashedBtn.classList.toggle('!bg-accent-soft', !!lane.dashed)
        }
        laneToolbarOrientationBtns.forEach((btn) => {
            const active = btn.dataset.vizLaneToolbarOrientation === (lane.orientation || LANE_STYLE_DEFAULTS.orientation)
            btn.classList.toggle('!bg-accent-soft', active)
            btn.setAttribute('aria-pressed', String(active))
        })
        if (laneToolbarOpacity) laneToolbarOpacity.value = String(Number.isFinite(lane.opacity) ? lane.opacity : LANE_STYLE_DEFAULTS.opacity)
        if (laneToolbarFontSize) laneToolbarFontSize.value = lane.fontSize || LANE_STYLE_DEFAULTS.fontSize
        if (laneToolbarTitleInput) laneToolbarTitleInput.checked = lane.showTitle !== false
    }

    laneToolbarFontSize?.addEventListener('change', () => setLaneFontSize(laneToolbarFontSize.value))
    // Square or rounded corners — the lane itself only (the label follows
    // through `.is-rounded` in the CSS, see `chain/viz.blade.php`).
    laneToolbarRoundedBtn?.addEventListener('click', () => {
        if (selectedLane === null || !lanes[selectedLane]) return
        lanes[selectedLane].rounded = !lanes[selectedLane].rounded
        laneEls[selectedLane]?.wrap.classList.toggle('is-rounded', lanes[selectedLane].rounded)
        refreshLaneToolbarControls()
        setDirty(true)
    })
    // Solid or dashed border — the same button-mirrors-the-state toggle a
    // block has (`toolbarDashedBtn` above).
    laneToolbarDashedBtn?.addEventListener('click', () => {
        if (selectedLane === null || !lanes[selectedLane]) return
        lanes[selectedLane].dashed = !lanes[selectedLane].dashed
        const entry = laneEls[selectedLane]
        if (entry) entry.wrap.style.borderStyle = lanes[selectedLane].dashed ? 'dashed' : 'solid'
        refreshLaneToolbarControls()
        setDirty(true)
    })
    // Horizontal or vertical orientation — it moves the label from the left
    // edge (vertical text) to the top (ordinary text), `.is-vertical` in the
    // CSS.
    laneToolbarOrientationBtns.forEach((btn) => {
        btn.addEventListener('click', () => {
            if (selectedLane === null || !lanes[selectedLane]) return
            const orientation = btn.dataset.vizLaneToolbarOrientation === 'vertical' ? 'vertical' : 'horizontal'
            lanes[selectedLane].orientation = orientation
            laneEls[selectedLane]?.wrap.classList.toggle('is-vertical', orientation === 'vertical')
            refreshLaneToolbarControls()
            setDirty(true)
        })
    })
    laneToolbarOpacity?.addEventListener('input', () => setLaneOpacity(laneToolbarOpacity.value))
    // Title shown or hidden — with no title the whole body takes the
    // selection target's role (`laneAnchorEl()`, used by
    // `startInlineLaneLabelEdit()`).
    laneToolbarTitleInput?.addEventListener('change', () => {
        if (selectedLane === null || !lanes[selectedLane]) return
        lanes[selectedLane].showTitle = !!laneToolbarTitleInput.checked
        if (laneEls[selectedLane]) laneEls[selectedLane].label.style.display = lanes[selectedLane].showTitle === false ? 'none' : ''
        setDirty(true)
    })
    laneToolbarRemove?.addEventListener('click', () => {
        if (selectedLane === null) return
        removeLane(selectedLane)
    })

    laneToolbar?.addEventListener('pointerdown', (e) => e.stopPropagation())
    lanesBtn?.addEventListener('click', addLane)
    notesBtn?.addEventListener('click', addNote)

    function draw() {
        clearOverlays()
        // Lanes need nothing here: they are children of `world`, so dragging a
        // block (which runs `draw()` on every pointermove) does not affect them
        // at all — and neither does pan or zoom, through that same CSS
        // transform.
        edgeLabelEls = []
        const edgeList = graphRef.edges || []

        // The two readability fixes below are COLLECTIVE: an end only knows
        // which way to step aside once it knows how many others are competing
        // for the same face, and a corridor only knows it has to detour once it
        // knows who else runs through there. So all the geometry comes first,
        // and the drawing after it.
        const fan = fanOffsets(edgeList)
        const ends = edgeList.map((edge, i) => {
            // While this link is being dragged, draw the loose end on the node
            // under the pointer (`drag.targetNode`) rather than on the one
            // stored in `edge.from`/`edge.to` — this is the preview of the
            // retarget, confirmed only on pointerup (`retargetEdge()`).
            const fromIndex = (drag?.type === 'handle' && drag.edge === i && drag.end === 'from') ? drag.targetNode : edge.from
            const toIndex = (drag?.type === 'handle' && drag.edge === i && drag.end === 'to') ? drag.targetNode : edge.to
            const fromNode = nodes[fromIndex]
            const toNode = nodes[toIndex]
            if (!fromNode || !toNode) return null

            const anchors = edgeAnchors[i] || { from: 'r', to: 'l', dashed: false }
            const a0 = anchorPoint(fromNode, anchors.from, anchors.fromT)
            const a3 = anchorPoint(toNode, anchors.to, anchors.toT)
            // Share the face between the links competing for it: the offset
            // runs ALONG it, perpendicular to the normal.
            if (a0.nx !== 0) a0.y += fan[i].from
            else a0.x += fan[i].from
            if (a3.nx !== 0) a3.y += fan[i].to
            else a3.x += fan[i].to
            // Keeps the ends clear of the handle's centre, so the arrowhead
            // does not run into the circle. On a lifeline the anchor sits ON
            // the dashed line, at the very point where the connection handle is
            // drawn — and the handle is painted after, hiding the arrowhead. A
            // larger gap on that side puts the head where it can be seen,
            // without touching the other blocks, where 8px is still right.
            const gap0 = fromNode.kind === 'lifeline' ? EDGE_GAP_LIFELINE : EDGE_GAP
            const gap3 = toNode.kind === 'lifeline' ? EDGE_GAP_LIFELINE : EDGE_GAP

            return {
                fromIndex,
                toIndex,
                anchors,
                a0,
                a3,
                p0: { x: a0.x + a0.nx * gap0, y: a0.y + a0.ny * gap0, nx: a0.nx, ny: a0.ny },
                p3: { x: a3.x + a3.nx * gap3, y: a3.y + a3.ny * gap3, nx: a3.nx, ny: a3.ny },
            }
        })

        const detour = corridorOffsets(ends.map((e) => (e ? corridorOf(e.p0, e.p3) : null)))

        edgeList.forEach((edge, i) => {
            const end = ends[i]
            if (!end) return

            const { fromIndex, toIndex, anchors, a0, a3, p0, p3 } = end
            const route = orthogonalPoints(p0, p3, EDGE_STUB, detour[i])
            const d = roundedPath(route)

            // A wide invisible target, underneath the stroke. A 2px line is
            // nearly impossible to hit with a mouse, and it was the only way to
            // grab a link at all: the whole SVG carries `pointer-events: none`
            // and only the pill reopened events — on a link with no protocol
            // the empty pill was the single way in, and it only appears on
            // hover now.
            if (editable) drawEdgeHit(d, i)

            const path = document.createElementNS(SVG_NS, 'path')
            path.setAttribute('class', 'ak-viz-edge' + (anchors.dashed ? ' is-dashed' : ''))
            path.setAttribute('d', d)
            // Which block is on each end, for the selection highlight.
            path.dataset.from = String(fromIndex)
            path.dataset.to = String(toIndex)
            const arrow = edge.arrow || '->'
            if (arrow === '->' || arrow === '<->') path.setAttribute('marker-end', `url(#${markerEnd.id})`)
            if (arrow === '<-' || arrow === '<->') path.setAttribute('marker-start', `url(#${markerStart.id})`)
            path.dataset.edgeIndex = i // permite re-localizar este <path> por índice — ver startPresentAnimation()
            edges.appendChild(path)

            // The protocol pill — always visible when the link has one
            // defined; when it has none, a dashed "+ protocolo" pill is drawn
            // for whoever may edit (a viewer sees nothing, as before).
            const proto = edge.protocol
            if (proto || editable) drawProtocolPill(labelAnchor(route), i, proto)

            if (editable) {
                drawHandle(a0, i, 'from')
                drawHandle(a3, i, 'to')
            }
        })

        // Nothing to reorder: the targets are drawn into their own layer, which
        // sits under everything. This used to walk the edges SVG moving each
        // target to the front, because a target created in the loop landed
        // after the previous link's written pill and swallowed clicks on it
        // where two routes crossed. A layer answers that case and the lane
        // handles at once, instead of ordering siblings against one of them.

        spreadProtocolPills()

        if (drag?.type === 'handle' && nodes[drag.targetNode]) drawAnchorDots(drag.targetNode, edgeAnchors[drag.edge][drag.end])
        // The preview is also drawn while the quick-add is open (an arrow
        // dropped on empty canvas), not only during the drag itself, for visual
        // continuity: "this new block will link there" stays clear while the
        // person picks the kind or the Solution in the panel.
        if (drag?.type === 'connect' || quickAddOrigin) drawConnectPreview()
        inlineProtocolReposition?.()
        // `draw()` recreates every path, so the selection and hover highlights
        // have to be repainted — otherwise they vanish on the first drag.
        highlightLinkedEdges(selectedIndex)
        setHoveredEdge(hoveredEdge)
    }

    // ── presentation mode ────────────────────────────────────────────
    // Turned on and off by `presentToggleBtn` (bottom bar) or Esc. It disables
    // every edit by reusing the SAME gate that already guards each interaction
    // in this file (`editable`): forcing `editable = false` removes node and
    // lane drags, ports, handles and the protocol pill for free, without
    // touching any of them individually. `refreshEditableUI()` is where the
    // visibility toggles live, instead of scattered through
    // `render()`/`showEmpty()`.
    function refreshEditableUI() {
        root.toggleAttribute('data-editable', editable)
        root.toggleAttribute('data-presenting', presenting)
        saveBtn?.classList.toggle('!hidden', !editable)
        saveSep?.classList.toggle('hidden', !editable)
        addNodeBtn?.classList.toggle('!hidden', !editable)
        lanesBtn?.classList.toggle('!hidden', !editable)
        notesBtn?.classList.toggle('!hidden', !editable)
        presentSpeedWrap?.classList.toggle('hidden', !presenting)
        presentSpeedWrap?.classList.toggle('flex', presenting)
        presentIconStart?.classList.toggle('hidden', presenting)
        presentIconStop?.classList.toggle('hidden', !presenting)
        presentToggleBtn?.setAttribute('title', presenting ? 'Sair da apresentação' : 'Modo apresentação')
    }

    // One dot per revealed node — idempotent on purpose: the safety sweep in
    // `onDotFirstLoopComplete()` calls this for EVERY node without checking
    // first what another dot has already revealed.
    function revealNode(index) {
        if (index == null || presentRevealedNodes[index]) return
        presentRevealedNodes[index] = true
        if (nodes[index]) nodes[index].el.style.opacity = '1'
    }

    function revealAllNodes() {
        nodes.forEach((n, i) => revealNode(i))
    }

    // The same idea as `revealNode()`, for an edge path and its protocol pill
    // (if it has one — `edgeLabelEls[edgeIndex]` only exists when the link has
    // a `protocol`, since `editable` is false while presenting and `draw()`
    // never draws the dashed "+ protocolo" invitation then). An arrow should
    // appear when a dot BEGINS travelling it, not when the ordinary `draw()`
    // renders it — see the calls in
    // `startPresentAnimation()`/`presentTick()`.
    function revealEdge(edgeIndex) {
        if (edgeIndex == null || presentRevealedEdges[edgeIndex]) return
        presentRevealedEdges[edgeIndex] = true
        const pathEl = edges.querySelector(`[data-edge-index="${edgeIndex}"]`)
        if (pathEl) pathEl.style.opacity = '1'
        if (edgeLabelEls[edgeIndex]) edgeLabelEls[edgeIndex].style.opacity = '1'
    }

    function revealAllEdges() {
        (graphRef.edges || []).forEach((_, i) => revealEdge(i))
    }

    // Only the FIRST lap of each dot (`dot.lap === 0`) counts towards the fade
    // in — later laps hide and reveal nothing new.
    function onDotArrivedAtSegmentEnd(dot) {
        if (dot.lap > 0) return
        const seg = dot.segments[dot.segIdx]
        const edge = graphRef.edges[seg.edgeIndex]
        revealNode(seg.reversed ? edge.from : edge.to)
    }

    // A node or edge outside every one of the ≤5 animated paths (an isolated
    // node is revealed before this, see `enterPresentation()` — this is for
    // what lies beyond the branching ceiling) would never be revealed on its
    // own. Once EVERY active dot has closed its own first lap, reveal whatever
    // is left in one go, so nothing stays invisible forever.
    function onDotFirstLoopComplete(dot) {
        if (dot.firstLoopDone) return
        dot.firstLoopDone = true
        presentFirstLoopPending -= 1
        if (presentFirstLoopPending === 0 && !presentFallbackFired) {
            presentFallbackFired = true
            revealAllNodes()
            revealAllEdges()
        }
    }

    // Places a dot's circle at the current point of its segment —
    // `getPointAtLength()` already answers in the same world space as any other
    // child of `edges`/`world`, with no conversion. `reversed` samples the path
    // back to front (the link is `<-`, see `computePresentationPaths()`).
    function positionDot(dot) {
        const seg = dot.segments[dot.segIdx]
        const lenAlong = seg.reversed ? (seg.length - dot.segDist) : dot.segDist
        const pt = seg.pathEl.getPointAtLength(Math.max(0, Math.min(seg.length, lenAlong)))
        dot.el.setAttribute('cx', pt.x)
        dot.el.setAttribute('cy', pt.y)
    }

    // Builds each dot's circle and caches the length of every segment of its
    // path (`getTotalLength()`, once) before starting the loop — rebuilding that
    // on every frame would be waste, and an edge's path does not change while
    // presenting (nothing triggers `draw()` then, see `enterPresentation()` and
    // the guard in the pasted-image listener).
    function startPresentAnimation() {
        presentDots = presentPaths.map((path) => {
            const el = document.createElementNS(SVG_NS, 'circle')
            el.setAttribute('class', 'ak-viz-dot')
            el.setAttribute('r', 4)
            el.style.fill = path.color
            el.style.fillOpacity = '0.7' // corpo translúcido — o glow (currentColor) é que fica em destaque
            el.style.color = path.color // currentColor do drop-shadow em `.ak-viz-dot` lê daqui, não de `fill`
            edges.appendChild(el) // irmão dos <path class="ak-viz-edge">, nunca removido por clearOverlays()
            // Each path's first edge was already revealed (instantly, with no
            // fade) in `enterPresentation()`, BEFORE the forced reflow of the
            // isolated-node reset. Do not repeat the call here: this point is
            // late enough (after that reflow) that it would animate by accident
            // instead of appearing at once.
            return {
                el,
                segments: path.edges.map(({ edgeIndex, reversed }) => {
                    const pathEl = edges.querySelector(`[data-edge-index="${edgeIndex}"]`)
                    return { edgeIndex, reversed, pathEl, length: pathEl.getTotalLength() }
                }),
                segIdx: 0,
                segDist: 0,
                lap: 0,
                firstLoopDone: false,
            }
        })

        presentFirstLoopPending = presentDots.length
        presentFallbackFired = false
        if (!presentDots.length) { revealAllNodes(); revealAllEdges(); return } // nada pra animar — não deixa o resto escondido pra sempre

        presentDots.forEach((dot) => positionDot(dot))
        presentLastTs = null
        presentRafId = requestAnimationFrame(presentTick)
    }

    function presentTick(ts) {
        if (!presenting) return
        if (presentLastTs === null) presentLastTs = ts
        // Clamp: a background tab pauses rAF, and when focus comes back the
        // first `ts` can arrive with an enormous jump — without this the dot
        // would "teleport" through several laps at once.
        const dt = Math.min((ts - presentLastTs) / 1000, 0.1)
        presentLastTs = ts
        const step = PRESENT_BASE_SPEED * presentSpeedMultiplier * dt

        presentDots.forEach((dot) => {
            let remaining = step
            while (remaining > 0) {
                const seg = dot.segments[dot.segIdx]
                const segRemaining = seg.length - dot.segDist
                if (remaining < segRemaining) {
                    dot.segDist += remaining
                    remaining = 0
                } else {
                    remaining -= segRemaining
                    dot.segDist = 0
                    onDotArrivedAtSegmentEnd(dot)
                    dot.segIdx += 1
                    if (dot.segIdx >= dot.segments.length) {
                        dot.segIdx = 0
                        if (dot.lap === 0) onDotFirstLoopComplete(dot)
                        dot.lap += 1
                    }
                    revealEdge(dot.segments[dot.segIdx].edgeIndex) // a bolinha está começando a viajar por essa aresta agora — idempotente, então voltas seguintes são no-op
                }
            }
            positionDot(dot)
        })

        presentRafId = requestAnimationFrame(presentTick)
    }

    function stopPresentAnimation() {
        if (presentRafId !== null) cancelAnimationFrame(presentRafId)
        presentRafId = null
        presentLastTs = null
        presentDots.forEach((d) => d.el.remove())
        presentDots = []
    }

    function enterPresentation() {
        if (presenting || !graphRef || !nodes.length) return
        selectNode(null)
        closeComment()
        closeAddEditor()
        closeLaneToolbar()

        presenting = true
        savedEditableBeforePresenting = editable
        editable = false
        refreshEditableUI()
        draw() // reconstrói arestas/pills/alças já sem editable — tira listener de pill obsoleto

        presentSpeedMultiplier = 1
        if (presentSpeedSelect) presentSpeedSelect.value = '1'

        presentPaths = computePresentationPaths(graphRef)
        presentRevealedNodes = nodes.map(() => false)
        presentRevealedEdges = (graphRef.edges || []).map(() => false)
        presentFallbackFired = false
        nodes.forEach((n) => { n.el.style.opacity = '0' })
        // Every arrow (and its protocol pill, if it has one) starts invisible:
        // it appears when a dot actually begins travelling it (`revealEdge()`,
        // called from `startPresentAnimation()`/`presentTick()`), not merely
        // because it exists in the graph.
        edges.querySelectorAll('.ak-viz-edge').forEach((el) => { el.style.opacity = '0' })
        edgeLabelEls.forEach((el) => { if (el) el.style.opacity = '0' })
        presentPaths.forEach((p) => revealNode(p.startNode)) // nó de partida aparece na hora, nunca é "alcançado"
        presentPaths.forEach((p) => revealEdge(p.edges[0].edgeIndex)) // idem pra 1ª aresta de cada caminho — precisa estar ANTES do reflow forçado do isolado abaixo, senão esse reflow "comita" o opacity:0 acima como checkpoint de verdade e a revelação MAIS TARDE (em startPresentAnimation()) passa a animar por acidente

        // An isolated node (degree zero) has no dot that will ever reach it,
        // so there is no sense in it waiting for the end-of-first-lap sweep.
        // BUT revealing it too soon after the `opacity='0'` above INTERRUPTS
        // that same transition halfway — and since the `ease` curve leaves
        // almost at rest, interrupting it a few ms in (tried with one and with
        // two consecutive `requestAnimationFrame`s: neither gave enough real
        // time) returns the value to near where it already was, with no visible
        // fade at all. Rather than fight a transition in flight, it zeroes with
        // `transition: none` plus a forced reflow (with NO transition running)
        // and only then restores `transition` — the switch to '1' that follows
        // fires a clean, complete 0.5s transition from a real 0, exactly like
        // any node a dot genuinely reveals.
        const isolated = computeIsolatedNodes(graphRef)
        if (isolated.length) {
            const isolatedEls = isolated.map((i) => nodes[i]?.el).filter(Boolean)
            isolatedEls.forEach((el) => { el.style.transition = 'none'; el.style.opacity = '0' })
            void root.offsetHeight // commita o "0 sem transição" acima antes de reativar
            isolatedEls.forEach((el) => { el.style.transition = '' })
            isolated.forEach((i) => revealNode(i))
        }

        startPresentAnimation()
    }

    function exitPresentation() {
        if (!presenting) return
        stopPresentAnimation()
        presenting = false
        editable = savedEditableBeforePresenting
        // Removes [data-presenting] BEFORE resetting the opacity: the fade-in
        // transition only exists under that attribute (see the CSS), so the
        // snap back to fully visible is instant and nothing re-animates
        // backwards.
        refreshEditableUI()
        nodes.forEach((n) => { n.el.style.opacity = '' })
        draw() // reconstrói arestas/pills do zero, todas com opacity padrão (visível) — nada a resetar nelas aqui
    }

    // ── Export (PNG / GIF) ────────────────────────────────────────────
    // Crops `#world` around `contentBBox()`, never to the shape of the viewport
    // the browser happens to be showing — see the paragraph at the top of the
    // .blade for the full reasoning. `toCanvas()` clones `world` (it never
    // touches the real DOM), so NONE of this flickers on screen; the one real
    // exception is entering and leaving presentation mode itself, which changes
    // the screen on purpose, as it always has.
    // `fontEmbedCSS`, when given, skips `toCanvas()`'s own font detection
    // (`getFontEmbedCSS()` already ran once — see `exportVideo()`). Node
    // labels are set in 'Space Grotesk' (`.ak-viz-node`'s own rule) — a REAL
    // loaded webfont, not a `system-ui` fallback — so skipping font embed
    // entirely (this function used to pass `skipFonts: true`) silently
    // substituted a wider fallback typeface in the exported PNG/GIF, enough
    // to wrap "SAP S/4HANA" onto 2 lines where the live page shows 1 (real
    // bug, reported 2026-08-03). Detecting fonts from scratch on every one of
    // a GIF's ~40 frames is too slow to just always do it live, though —
    // hence precomputing once and passing it back in for every frame after.
    //
    // No `preset`/theme parameter here on purpose: `applyTheme()` already
    // keeps `world`/`edges`'s `data-viz-preset` attribute in sync with
    // `currentTheme` continuously (it's a live, standing canvas state now,
    // not something toggled on only for the instant of a capture) — this
    // function just captures whatever the live DOM already shows, same as
    // any other export.
    async function captureDiagramCanvas(longSide = EXPORT_LONG_SIDE, fontEmbedCSS = null) {
        const bbox = contentBBox()
        if (!bbox) return null
        const cw = bbox.maxX - bbox.minX + EXPORT_PAD * 2
        const ch = bbox.maxY - bbox.minY + EXPORT_PAD * 2
        const wide = cw >= ch
        const targetW = Math.max(1, Math.round(wide ? longSide : (longSide * cw) / ch))
        const targetH = Math.max(1, Math.round(wide ? (longSide * ch) / cw : longSide))
        const scale = targetW / cw
        const tx = Math.round((EXPORT_PAD - bbox.minX) * scale)
        const ty = Math.round((EXPORT_PAD - bbox.minY) * scale)
        const conf = EXPORT_PRESETS[currentTheme] || EXPORT_PRESETS.original

        return toCanvas(world, {
            width: targetW,
            height: targetH,
            pixelRatio: 1,
            backgroundColor: conf.bg,
            imagePlaceholder: EXPORT_IMAGE_PLACEHOLDER,
            ...(fontEmbedCSS ? { fontEmbedCSS } : {}),
            style: { transform: `translate(${tx}px, ${ty}px) scale(${scale})`, transformOrigin: '0 0' },
        })
    }

    function exportFileBase() {
        return (slug || 'diagrama').replace(/[^a-z0-9-]+/gi, '-')
    }

    function downloadBlob(blob, filename) {
        const url = URL.createObjectURL(blob)
        const a = document.createElement('a')
        a.href = url
        a.download = filename
        document.body.appendChild(a)
        a.click()
        a.remove()
        setTimeout(() => URL.revokeObjectURL(url), 4000)
    }

    async function exportImage() {
        if (!graphRef || !nodes.length) { Toast.show('Nada para exportar ainda.', 'warning'); return }
        setButtonLoading(exportPngBtn, true)
        if (exportGifBtn) exportGifBtn.disabled = true
        try {
            const canvas = await captureDiagramCanvas()
            const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'))
            if (!blob) throw new Error('toBlob returned null')
            downloadBlob(blob, `diagrama-${exportFileBase()}.png`)
            Toast.show('Imagem exportada.')
        } catch (err) {
            console.error('exportImage failed', err)
            Toast.show('Não foi possível gerar a imagem.', 'error')
        } finally {
            setButtonLoading(exportPngBtn, false)
            if (exportGifBtn) exportGifBtn.disabled = false
        }
    }

    // Runs presentation mode's own animation (entering it if it is not already
    // running, and leaving again at the end only if this function is what
    // entered — it never interrupts a presentation the user opened themselves)
    // and takes real `captureDiagramCanvas()` photographs along the way. Each
    // frame's delay in the finished GIF is the real time elapsed between one
    // photograph and the next (`now - lastTs`), not a fixed value: every
    // capture is a full clone, serialize and rasterize of the DOM, so the time
    // per frame varies while the GIF's playback SPEED stays faithful to what
    // actually happened.
    async function exportVideo() {
        if (!graphRef || !nodes.length) { Toast.show('Nada para exportar ainda.', 'warning'); return }
        setButtonLoading(exportGifBtn, true)
        if (exportPngBtn) exportPngBtn.disabled = true
        if (exportStatus) { exportStatus.textContent = 'Gravando apresentação…'; exportStatus.classList.remove('hidden') }

        const wasPresenting = presenting
        if (!wasPresenting) enterPresentation()

        try {
            // Detecting/embedding fonts from scratch (`toCanvas()`'s default
            // behavior, needed so 'Space Grotesk' node labels don't silently
            // fall back to a wider typeface — see captureDiagramCanvas()'s
            // comment) is real work: scans every stylesheet on the page for
            // @font-face rules, then fetches each font file. Fine once; far
            // too slow repeated ~40 times over one GIF. `getFontEmbedCSS()`
            // does that work exactly once, up front, and every frame below
            // reuses the same already-embedded CSS string instead of redoing it.
            const fontEmbedCSS = await getFontEmbedCSS(world)

            const first = await captureDiagramCanvas(EXPORT_GIF_LONG_SIDE, fontEmbedCSS)
            if (!first) return
            const gif = new GIF({
                workers: 2,
                quality: 15, // um pouco mais rápido pra codificar que o padrão (10) — mais frames pesa mais aqui embaixo
                workerScript: await resolveGifWorkerUrl(),
                width: first.width,
                height: first.height,
                background: (EXPORT_PRESETS[currentTheme] || EXPORT_PRESETS.original).bg,
            })

            // No artificial wait between frames — one capture is chained
            // straight behind the last; the real capture time (far longer than
            // any wait worth imposing) is itself what becomes each frame's
            // delay in the finished GIF.
            let lastTs = performance.now()
            gif.addFrame(first, { delay: 80, copy: true }) // só o 1º frame não tem um "tempo decorrido" real anterior pra usar

            const start = lastTs
            while (performance.now() - start < EXPORT_GIF_SECONDS * 1000) {
                const canvas = await captureDiagramCanvas(EXPORT_GIF_LONG_SIDE, fontEmbedCSS)
                if (!canvas) break
                const now = performance.now()
                gif.addFrame(canvas, { delay: now - lastTs, copy: true })
                lastTs = now
            }

            const blob = await new Promise((resolve, reject) => {
                gif.once('finished', resolve)
                gif.once('abort', () => reject(new Error('gif encoding aborted')))
                gif.render()
            })
            downloadBlob(blob, `diagrama-${exportFileBase()}.gif`)
            Toast.show('Vídeo (GIF) exportado.')
        } catch (err) {
            console.error('exportVideo failed', err)
            Toast.show('Não foi possível gerar o vídeo.', 'error')
        } finally {
            if (!wasPresenting) exitPresentation()
            setButtonLoading(exportGifBtn, false)
            if (exportPngBtn) exportPngBtn.disabled = false
            if (exportStatus) { exportStatus.textContent = ''; exportStatus.classList.add('hidden') }
        }
    }

    // `proto` is `{value,label}` (a step with a protocol) or `null` (none yet
    // — which only reaches here when `editable`, see `draw()`). Clickable only
    // when `editable`: it opens the segment's protocol editor (`selectEdge()`),
    // the same spirit as a node's title pencil.
    function drawProtocolPill(spot, edgeIndex, proto) {
        if (!spot) return

        const isEmpty = !proto
        const text = proto ? proto.label : '+ protocolo'
        const w = text.length * 6.6 + 14
        const [mx, my] = [spot.x, spot.y]

        const g = document.createElementNS(SVG_NS, 'g')
        g.setAttribute('class', 'ak-viz-plabel' + (isEmpty ? ' is-empty' : '') + (editable ? ' is-editable' : ''))
        // Which link this pill belongs to — the selection highlight lights the
        // two together, or a lit arrow would keep a dimmed label.
        g.dataset.edgeIndex = String(edgeIndex)
        // Which way the run it landed on goes: `spreadProtocolPills()` pushes
        // two stacked labels apart PERPENDICULAR to their own line.
        g.dataset.labelAxis = spot.horiz ? 'h' : 'v'

        const rect = document.createElementNS(SVG_NS, 'rect')
        rect.setAttribute('class', 'ak-viz-plabel-box')
        rect.setAttribute('x', mx - w / 2)
        rect.setAttribute('y', my - 9)
        rect.setAttribute('width', w)
        rect.setAttribute('height', 18)
        rect.setAttribute('rx', 5)

        const label = document.createElementNS(SVG_NS, 'text')
        label.setAttribute('class', 'ak-viz-plabel-text')
        label.setAttribute('x', mx)
        label.setAttribute('y', my + 1)
        label.textContent = text
        // Redrawn (`draw()`) while this SAME segment is being edited inline
        // (`startInlineProtocolEdit()`): the floating `<input>` already covers
        // this area, so the static text stays invisible underneath it instead
        // of showing up twice.
        if (inlineProtocolEditIndex === edgeIndex) label.style.opacity = '0'

        g.appendChild(rect)
        g.appendChild(label)

        if (editable && !isEmpty) {
            g.addEventListener('pointerenter', () => setHoveredEdge(edgeIndex))
            g.addEventListener('pointerleave', () => setHoveredEdge(null))
            g.addEventListener('pointerdown', (e) => e.stopPropagation())
            g.addEventListener('click', (e) => {
                e.stopPropagation()
                selectEdge(edgeIndex)
            })
            // A double click edits the protocol ON the label itself — the same
            // pattern as `startInlineLabelEdit()` on a block's text.
            g.addEventListener('dblclick', (e) => {
                e.stopPropagation()
                startInlineProtocolEdit(edgeIndex)
            })
        }

        edgeLabelEls[edgeIndex] = g
        edges.appendChild(g)
    }

    /**
     * Two labels that landed on top of each other step apart.
     *
     * Only the WRITTEN ones take part: an empty pill is an invitation rather
     * than content, and only one shows at a time (on the link's hover) —
     * letting it push a real label would move the drawing because of something
     * invisible.
     */
    function spreadProtocolPills() {
        const hits = (a, b) => a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h
        const placed = []

        edgeLabelEls.forEach((g) => {
            if (!g || g.classList.contains('is-empty')) return

            const rect = g.querySelector('.ak-viz-plabel-box')
            const label = g.querySelector('.ak-viz-plabel-text')
            const [w, h] = [Number(rect.getAttribute('width')), Number(rect.getAttribute('height'))]
            const [x0, y0] = [Number(rect.getAttribute('x')), Number(rect.getAttribute('y'))]
            let box = { x: x0, y: y0, w, h }

            // Step perpendicular to the line, alternating sides: a label
            // pushed along its own line would still sit in front of whoever was
            // already there, and further from the run it names.
            for (let step = 1; step <= 4 && placed.some((other) => hits(other, box)); step++) {
                const shift = (step % 2 ? 1 : -1) * Math.ceil(step / 2) * (h + 6)
                box = g.dataset.labelAxis === 'v'
                    ? { x: x0 + shift * 1.8, y: y0, w, h }
                    : { x: x0, y: y0 + shift, w, h }
            }

            if (box.x !== x0 || box.y !== y0) {
                rect.setAttribute('x', box.x)
                rect.setAttribute('y', box.y)
                label.setAttribute('x', box.x + w / 2)
                label.setAttribute('y', box.y + h / 2 + 1)
            }

            placed.push(box)
        })
    }

    /**
     * A wide invisible target over a link — its hover, click and double click
     * all arrive through here.
     *
     * The edges svg carries `pointer-events: none` on purpose (it must not eat
     * the click that pans), so nothing in it was clickable but the pill. On a
     * link with no protocol that pill was the dashed "+ protocolo", which only
     * appears on hover now — without this target there would be no way to grab
     * one.
     */
    function drawEdgeHit(d, edgeIndex) {
        const hit = document.createElementNS(SVG_NS, 'path')
        hit.setAttribute('class', 'ak-viz-edge-hit')
        hit.setAttribute('d', d)
        // Presentation ATTRIBUTES, on top of the CSS rule: the rule wins in the
        // live DOM (the transparent 16px stroke that catches the pointer), and
        // in an export clone — which arrives with no stylesheet at all — these
        // are what is left, instead of the UA's default `fill: black`, which
        // once painted a blob along every arrow.
        hit.setAttribute('fill', 'none')
        hit.setAttribute('stroke', 'none')
        hit.dataset.edgeIndex = String(edgeIndex)

        let downAt = null

        hit.addEventListener('pointerenter', () => setHoveredEdge(edgeIndex))
        hit.addEventListener('pointerleave', () => setHoveredEdge(null))
        // It does NOT swallow the pointerdown: a drag starting here still has
        // to pan, and the target is wide enough to fall under the pointer by
        // accident. That is why the click confirms the pointer stayed put —
        // otherwise every pan begun near a line would select the link.
        hit.addEventListener('pointerdown', (e) => { downAt = { x: e.clientX, y: e.clientY } })
        hit.addEventListener('click', (e) => {
            if (!downAt || Math.hypot(e.clientX - downAt.x, e.clientY - downAt.y) > 4) return
            e.stopPropagation()
            selectEdge(edgeIndex)
        })
        hit.addEventListener('dblclick', (e) => {
            e.stopPropagation()
            startInlineProtocolEdit(edgeIndex)
        })

        hits.appendChild(hit)
    }

    /** Lights the link under the pointer and reveals its empty pill. */
    function setHoveredEdge(index) {
        hoveredEdge = index
        edges.querySelectorAll('path.ak-viz-edge, .ak-viz-plabel').forEach((el) => {
            el.classList.toggle('is-hovered', index !== null && el.dataset.edgeIndex === String(index))
        })
    }

    function drawHandle(point, edgeIndex, end) {
        const h = document.createElement('div')
        h.className = 'ak-viz-handle'
        h.title = 'Arraste para reposicionar a ponta da seta — solte sobre outro bloco pra religar'
        h.style.left = point.x + 'px'
        h.style.top = point.y + 'px'
        if (drag?.type === 'handle' && drag.edge === edgeIndex && drag.end === end) h.classList.add('is-dragging')
        h.addEventListener('pointerdown', (e) => startHandleDrag(e, edgeIndex, end))
        world.appendChild(h)
    }

    function drawAnchorDots(nodeIndex, activeKey) {
        const node = nodes[nodeIndex]
        ANCHOR_KEYS.forEach((key) => {
            const p = anchorPoint(node, key)
            const dot = document.createElement('div')
            dot.className = 'ak-viz-anchor' + (key === activeKey ? ' is-near' : '')
            dot.style.left = p.x + 'px'
            dot.style.top = p.y + 'px'
            world.appendChild(dot)
        })
    }

    // ── selection + contextual toolbar ───────────────────────────────
    /**
     * Lights the selected block's links and dims the rest.
     *
     * It is the cheap answer to "which line is this one" in a crowded drawing:
     * instead of colouring every arrow all the time — which costs the blocks
     * their contrast and, with the category palette, paints exactly the three
     * most-crossed blocks the same blue — the drawing answers when somebody
     * asks, by clicking.
     */
    function highlightLinkedEdges(index) {
        edges.classList.toggle('has-selection', index !== null)
        const linkedEdges = new Set()

        edges.querySelectorAll('path.ak-viz-edge').forEach((path) => {
            const linked = index !== null
                && (path.dataset.from === String(index) || path.dataset.to === String(index))
            path.classList.toggle('is-linked', linked)
            if (linked) linkedEdges.add(path.dataset.edgeIndex)
        })

        edges.querySelectorAll('.ak-viz-plabel').forEach((pill) => {
            pill.classList.toggle('is-linked', linkedEdges.has(pill.dataset.edgeIndex))
        })
    }

    function selectNode(index) {
        closeProtocolEditor()
        closeAddEditor()
        closeLaneToolbar()
        if (selectedIndex !== null && nodes[selectedIndex]) nodes[selectedIndex].el.classList.remove('is-selected')
        selectedIndex = index
        highlightLinkedEdges(index)

        if (index !== null && nodes[index]) {
            nodes[index].el.classList.add('is-selected')
            toolbar?.classList.remove('hidden')
            toolbar?.classList.add('flex')
            // A light coloured border only makes sense on a pasted image (the
            // other shapes already have a fill and an outline of their own).
            toolbarImageBorderWrap?.classList.toggle('hidden', !editable || nodes[index].kind !== 'image')
            toolbarImageBorderWrap?.classList.toggle('flex', editable && nodes[index].kind === 'image')
            // "Logo only" makes sense only on a system block with a registered
            // Solution AND a logo — free text, a decision, an actor and a
            // system with no logo have no image to show on its own.
            {
                const canLogoOnly = nodes[index].kind === 'system' && !!nodes[index].solution && !!nodes[index].logo
                toolbarLogoOnlyWrap?.classList.toggle('hidden', !editable || !canLogoOnly)
                toolbarLogoOnlyWrap?.classList.toggle('flex', editable && canLogoOnly)
            }
            // The block's kind: never on the root node (index 0) and never on
            // a pasted image — there is no kind, Solution or text to change
            // there, only the picture (see `ChainNodeKind::pickable()`).
            {
                const kindEditable = editable && index !== 0 && nodes[index].kind !== 'image'
                toolbarKindRow?.classList.toggle('hidden', !kindEditable)
                toolbarKindRow?.classList.toggle('flex', kindEditable)
                if (kindEditable) refreshKindRow(index)
                // "Renomear" opens exactly the same inline editor the double
                // click does, and that editor's guard
                // (`startInlineLabelEdit()`) is this same condition — hence
                // sharing the boolean instead of repeating the rule and letting
                // the two drift apart later.
                toolbarRenameBtn?.classList.toggle('!hidden', !kindEditable)
            }
            // The trash follows the kind's rule: the root node does not go
            // (the server refuses index 0 anyway).
            toolbarRemoveBtn?.classList.toggle('!hidden', !editable || index === 0)
            toolbarRemoveSep?.classList.toggle('hidden', !editable || index === 0)
            if (editable) {
                buildSwatches()
                refreshToolbarControls()
            }
        } else {
            toolbar?.classList.add('hidden')
            toolbar?.classList.remove('flex')
        }
    }

    // ── block colour / text colour / font — the selected block only ──
    function buildSwatches() {
        if (!toolbarSwatches) return
        const current = nodes[selectedIndex]?.color
        toolbarSwatches.innerHTML = ''
        PALETTE.forEach((color) => {
            const sw = document.createElement('button')
            sw.type = 'button'
            sw.className = 'size-[22px] shrink-0 cursor-pointer rounded-md border border-black/10 transition-transform hover:scale-110'
            sw.style.background = color
            sw.title = color
            sw.style.boxShadow = current && current.toLowerCase() === color.toLowerCase()
                ? '0 0 0 2px var(--viz-bg), 0 0 0 3.5px var(--viz-select)'
                : ''
            sw.addEventListener('click', () => setNodeColor(color))
            toolbarSwatches.appendChild(sw)
        })
    }

    function refreshToolbarControls() {
        const n = nodes[selectedIndex]
        if (!n) return
        if (toolbarCustomColor) toolbarCustomColor.value = isHex(n.color) ? n.color : '#4A90D9'
        const effectiveTextColor = n.textColor || (n.color ? textColorFor(n.color) : '#1A1A2E')
        if (toolbarTextColor) toolbarTextColor.value = isHex(effectiveTextColor) ? effectiveTextColor : '#1A1A2E'
        if (toolbarTextColorWrap) toolbarTextColorWrap.style.color = effectiveTextColor
        if (toolbarFont) toolbarFont.value = n.font || 'sans'
        if (toolbarFontSize) toolbarFontSize.value = n.fontSize || 'sm'
        if (toolbarDashedBtn) {
            toolbarDashedBtn.classList.toggle('border-dashed', !!n.dashed)
            toolbarDashedBtn.classList.toggle('!bg-accent-soft', !!n.dashed)
        }
        // The input's colour mirrors the current border, or white (the default
        // suggested before anybody has turned the border on) — never the black
        // a colour input assumes by itself with no explicit value.
        if (toolbarImageBorderColor) toolbarImageBorderColor.value = isHex(n.imageBorderColor) ? n.imageBorderColor : '#FFFFFF'
        if (toolbarImageBorderToggle) {
            toolbarImageBorderToggle.classList.toggle('!bg-accent-soft', !!n.imageBorderColor)
            toolbarImageBorderToggle.setAttribute('aria-pressed', String(!!n.imageBorderColor))
        }
        if (toolbarLogoOnlyToggle) {
            toolbarLogoOnlyToggle.classList.toggle('!bg-accent-soft', !!n.logoOnly)
            toolbarLogoOnlyToggle.setAttribute('aria-pressed', String(!!n.logoOnly))
        }
    }

    // The selected block's dashed border — purely visual
    // (`viz_layout.nodes[i].dashed`), independent of colour, font and shape.
    toolbarDashedBtn?.addEventListener('click', () => {
        if (!editable || selectedIndex === null || !nodes[selectedIndex]) return
        nodes[selectedIndex].dashed = !nodes[selectedIndex].dashed
        applyNodeStyle(nodes[selectedIndex])
        refreshToolbarControls()
        setDirty(true)
    })

    // The image's light border — on and off (white by default the first time).
    // It only does anything when the selected block is an image (the wrapper is
    // already hidden for the other kinds, but the guard here catches any stray
    // click while the panel moves between blocks).
    toolbarImageBorderToggle?.addEventListener('click', () => {
        if (!editable || selectedIndex === null || !nodes[selectedIndex] || nodes[selectedIndex].kind !== 'image') return
        const n = nodes[selectedIndex]
        n.imageBorderColor = n.imageBorderColor ? null : (toolbarImageBorderColor?.value || '#FFFFFF')
        applyNodeStyle(n)
        refreshToolbarControls()
        setDirty(true)
    })
    // Changing the colour always implies "on" — there is no "off but holding a
    // colour" state for anyone to be confused by.
    toolbarImageBorderColor?.addEventListener('input', (e) => {
        if (!editable || selectedIndex === null || !nodes[selectedIndex] || nodes[selectedIndex].kind !== 'image') return
        nodes[selectedIndex].imageBorderColor = e.target.value
        applyNodeStyle(nodes[selectedIndex])
        refreshToolbarControls()
        setDirty(true)
    })

    function setNodeColor(color) {
        if (!editable || selectedIndex === null || !nodes[selectedIndex]) return
        nodes[selectedIndex].color = color
        applyNodeStyle(nodes[selectedIndex])
        buildSwatches()
        refreshToolbarControls()
        setDirty(true)
    }

    function setNodeTextColor(color) {
        if (!editable || selectedIndex === null || !nodes[selectedIndex]) return
        nodes[selectedIndex].textColor = color
        applyNodeStyle(nodes[selectedIndex])
        refreshToolbarControls()
        setDirty(true)
    }

    function setNodeFont(font) {
        if (!editable || selectedIndex === null || !nodes[selectedIndex] || !FONTS[font]) return
        nodes[selectedIndex].font = font
        applyNodeStyle(nodes[selectedIndex])
        setDirty(true)
    }

    function setNodeFontSize(size) {
        if (!editable || selectedIndex === null || !nodes[selectedIndex] || !FONT_SIZES[size]) return
        nodes[selectedIndex].fontSize = size
        applyNodeStyle(nodes[selectedIndex])
        setDirty(true)
    }

    toolbarCustomColor?.addEventListener('input', (e) => setNodeColor(e.target.value))
    toolbarTextColor?.addEventListener('input', (e) => setNodeTextColor(e.target.value))
    toolbarFont?.addEventListener('change', (e) => setNodeFont(e.target.value))
    toolbarFontSize?.addEventListener('change', (e) => setNodeFontSize(e.target.value))

    // "Logo only" changes the block's content structurally (a repaint through
    // `paintNode()`, not just an inline style) — hence re-measuring w/h and
    // redrawing the arrows right after, the same pattern as `applyNodeData()`.
    toolbarLogoOnlyToggle?.addEventListener('click', () => {
        if (!editable || selectedIndex === null || !nodes[selectedIndex]) return
        const n = nodes[selectedIndex]
        if (n.kind !== 'system' || !n.solution || !n.logo) return
        n.logoOnly = !n.logoOnly
        paintNode(n.el, n)
        n.w = n.el.offsetWidth
        n.h = n.el.offsetHeight
        applyNodeStyle(n)
        refreshToolbarControls()
        draw()
        setDirty(true)
    })

    // ── "Adicionar bloco": one icon per kind, created on the spot ──────────
    // A single horizontal row of icons (`getNodeKindsList()`, the same list
    // `refreshKindRow()` uses but with no persistent selection — each click is
    // a new action, not a toggle). Clicking one creates the block outright
    // (`createNodeFromKind()`), with no Solution or free text to fill in here:
    // that becomes `startInlineLabelEdit()` on the block just created, straight
    // on the canvas, the same gesture as renaming an existing one.
    function buildAddKindIcons() {
        if (!addKindIcons) return
        addKindIcons.innerHTML = ''
        getNodeKindsList().forEach((k) => {
            const btn = document.createElement('button')
            btn.type = 'button'
            btn.title = k.label
            btn.className = 'flex size-[30px] shrink-0 items-center justify-center rounded-md border border-line text-ink transition-colors hover:bg-accent-soft/60'
            if (k.icon) {
                const icon = document.createElement('span')
                icon.className = 'flex size-4 shrink-0 items-center justify-center [&>svg]:size-4'
                icon.innerHTML = k.icon
                btn.appendChild(icon)
            }
            btn.addEventListener('click', () => createNodeFromKind(k.value))
            addKindIcons.appendChild(btn)
        })
    }

    // A plain POST — no Solution or free text has been chosen yet, so it sends
    // the kind's own name ("Sistema", "Decisão", …) as the initial text
    // (`start`/`end` send `null`: the server fills "Início"/"Fim" in by itself,
    // `ChainNodeKind::defaultLabel()`). `startInlineLabelEdit()` right after
    // selects that whole text (the usual `input.select()`), so the person types
    // over it without having to delete anything — and on a `system` block,
    // typing there is the usual Solution search.
    async function createNodeFromKind(kindValue) {
        if (!editable || !graphRef?.nodeAddUrl) return
        const kind = nodeKind(kindValue)
        const payload = { kind: kind.value, solution_id: null, label: kind.optionalLabel ? null : kind.label }

        // Captured BEFORE the request: `closeAddEditor()` clears `quickAddPos`
        // and `quickAddOrigin`, so reading them afterwards would be too late.
        const pos = quickAddPos
        const origin = quickAddOrigin

        try {
            const res = await fetch(graphRef.nodeAddUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(payload),
            })
            const data = await res.json().catch(() => null)
            if (!res.ok) throw new Error(data?.message || 'Não foi possível adicionar o bloco.')

            appendNode(data.node, pos)
            patchRowGraphAppend(slug, data.node, data.summary)
            const newIndex = nodes.length - 1
            closeAddEditor()
            // This came from dropping an arrow on empty canvas
            // (`openQuickAddEditor()`) — complete the link with the block just
            // created, the same POST dropping it on an existing block uses.
            if (origin) createEdgeFrom(origin.index, newIndex, origin.side, 'l')
            startInlineLabelEdit(newIndex)
        } catch (err) {
            window.Toast?.show?.(err.message || 'Não foi possível adicionar o bloco.', 'error')
        }
    }

    // ── the block's editor: kind + registered Solutions + free text ──
    // Applies the resolved fields the server answers with (the same shape as
    // `graph.nodes[i]`) onto a node already drawn, without redrawing the whole
    // graph. The block's size can change (new text), so w/h is recomputed and
    // the edges redrawn right after.
    function applyNodeData(index, data) {
        const n = nodes[index]
        if (!n) return
        Object.assign(n, {
            label: data.label,
            kind: data.kind || 'system',
            icon: data.icon ?? null,
            solution: data.solution,
            solutionId: data.solutionId ?? null,
            logo: data.logo,
            comment: data.comment ?? null,
        })
        paintNode(n.el, n)
        n.w = n.el.offsetWidth
        n.h = n.el.offsetHeight
        n.el.style.left = n.x + 'px'
        n.el.style.top = n.y + 'px'
        applyNodeStyle(n)
        draw()
    }

    // Keeps the row consistent without re-selecting the drawing: it updates
    // that row's `data-ak-chain-graph` cache and its summary text, without
    // replacing the whole slot, which would drop the selection highlight (see
    // chain-select.js).
    function patchRowGraph(slugArg, index, nodeData, summary) {
        if (!slugArg) return
        const row = document.querySelector(`[data-ak-chain-select="${CSS.escape(slugArg)}"]`)
        if (!row) return

        const raw = row.getAttribute('data-ak-chain-graph')
        if (raw) {
            try {
                const g = JSON.parse(raw)
                if (g?.nodes?.[index]) {
                    g.nodes[index] = { ...g.nodes[index], ...nodeData }
                    row.setAttribute('data-ak-chain-graph', JSON.stringify(g))
                }
            } catch {
                // malformed cache — ignore it; the next full selection reloads from the server
            }
        }
        if (typeof summary === 'string') {
            row.querySelector('[data-ak-diagram-summary]')?.replaceChildren(document.createTextNode(summary))
        }
    }

    // Replaces the row's cached graph wholesale instead of patching one node
    // or edge: that is what deleting a block needs, since the indices of EVERY
    // node above the removed one have moved. The usual reason not to use
    // `updateSlots()` here — swapping the whole slot clears `aria-pressed` and
    // drops the user's selection (see `chain-select.js`).
    function patchRowGraphReplace(slugArg, graph, summary) {
        if (!slugArg || !graph) return
        const row = document.querySelector(`[data-ak-chain-select="${CSS.escape(slugArg)}"]`)
        if (!row) return

        row.setAttribute('data-ak-chain-graph', JSON.stringify(graph))
        if (typeof summary === 'string') {
            row.querySelector('[data-ak-diagram-summary]')?.replaceChildren(document.createTextNode(summary))
        }
    }

    // The same idea as `patchRowGraph()`, for `chain.edges[i]` (protocol and/or
    // direction) — the protocol is not part of the row's written summary
    // (`ChainLabeler::label()` uses the direction, not the protocol), so only
    // the cached graph has to be updated.
    function patchRowEdge(slugArg, index, protocolData, arrow) {
        if (!slugArg) return
        const row = document.querySelector(`[data-ak-chain-select="${CSS.escape(slugArg)}"]`)
        if (!row) return

        const raw = row.getAttribute('data-ak-chain-graph')
        if (!raw) return
        try {
            const g = JSON.parse(raw)
            if (g?.edges?.[index]) {
                g.edges[index].protocol = protocolData
                if (arrow) g.edges[index].arrow = arrow
                row.setAttribute('data-ak-chain-graph', JSON.stringify(g))
            }
        } catch {
            // malformed cache — ignore it; the next full selection reloads from the server
        }
    }

    // Appends (rather than replaces) a new link to the cache
    // (`createEdgeFrom()`), which touches no node, only `chain.edges`.
    function patchRowGraphAddEdge(slugArg, from, to, arrow, protocolData, summary) {
        if (!slugArg) return
        const row = document.querySelector(`[data-ak-chain-select="${CSS.escape(slugArg)}"]`)
        if (!row) return

        const raw = row.getAttribute('data-ak-chain-graph')
        if (raw) {
            try {
                const g = JSON.parse(raw)
                if (g) {
                    g.edges = g.edges || []
                    g.edges.push({ from, to, arrow, protocol: protocolData })
                    row.setAttribute('data-ak-chain-graph', JSON.stringify(g))
                }
            } catch {
                // malformed cache — ignore it; the next full selection reloads from the server
            }
        }
        if (typeof summary === 'string') {
            row.querySelector('[data-ak-diagram-summary]')?.replaceChildren(document.createTextNode(summary))
        }
    }

    // Removes a link from the cache (`protocolDelete` above) — the nodes do not move.
    function patchRowGraphRemoveEdge(slugArg, index, summary) {
        if (!slugArg) return
        const row = document.querySelector(`[data-ak-chain-select="${CSS.escape(slugArg)}"]`)
        if (!row) return

        const raw = row.getAttribute('data-ak-chain-graph')
        if (raw) {
            try {
                const g = JSON.parse(raw)
                if (g?.edges && index >= 0 && index < g.edges.length) {
                    g.edges.splice(index, 1)
                    row.setAttribute('data-ak-chain-graph', JSON.stringify(g))
                }
            } catch {
                // malformed cache — ignore it; the next full selection reloads from the server
            }
        }
        if (typeof summary === 'string') {
            row.querySelector('[data-ak-diagram-summary]')?.replaceChildren(document.createTextNode(summary))
        }
    }

    // The same idea, for re-pointing one end (`from`/`to`) of an existing link
    // at another node — dragging the arrow's handle onto another block
    // (`retargetEdge()`).
    function patchRowGraphEdge(slugArg, edgeIndex, end, newNode, summary) {
        if (!slugArg) return
        const row = document.querySelector(`[data-ak-chain-select="${CSS.escape(slugArg)}"]`)
        if (!row) return

        const raw = row.getAttribute('data-ak-chain-graph')
        if (raw) {
            try {
                const g = JSON.parse(raw)
                if (g?.edges?.[edgeIndex]) {
                    g.edges[edgeIndex][end] = newNode
                    row.setAttribute('data-ak-chain-graph', JSON.stringify(g))
                }
            } catch {
                // malformed cache — ignore it; the next full selection reloads from the server
            }
        }
        if (typeof summary === 'string') {
            row.querySelector('[data-ak-diagram-summary]')?.replaceChildren(document.createTextNode(summary))
        }
    }

    // One PATCH to `graphRef.nodeUpdateUrl` at a time — changing the kind,
    // changing the Solution and editing the label inline all come through here.
    // A failure (network or validation) repaints the block from the last known
    // state (`n`, still intact — only the server confirms a change) instead of
    // leaving the DOM showing the unsaved value the person was looking at.
    let nodeFieldSaving = false
    async function patchNode(index, payload) {
        const n = nodes[index]
        const url = graphRef?.nodeUpdateUrl?.replace('NODE_INDEX', String(index))
        if (nodeFieldSaving || !n || !url) return

        nodeFieldSaving = true
        try {
            const res = await fetch(url, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(payload),
            })
            const data = await res.json().catch(() => null)
            if (!res.ok) throw new Error(data?.message || 'Não foi possível atualizar o bloco.')

            applyNodeData(index, data.node)
            patchRowGraph(slug, index, data.node, data.summary)
            // Re-evaluates the whole toolbar rather than only the kind row:
            // changing the kind or the Solution can enable or disable "logo
            // only" and the image's light border, and `selectNode()` already
            // decides all of that in one place.
            if (selectedIndex === index) selectNode(index)
            window.Toast?.show?.(data.message || 'Bloco atualizado.')
        } catch (err) {
            window.Toast?.show?.(err.message || 'Não foi possível atualizar o bloco.', 'error')
            paintNode(n.el, n)
            n.w = n.el.offsetWidth
            n.h = n.el.offsetHeight
            applyNodeStyle(n)
            draw()
        } finally {
            nodeFieldSaving = false
        }
    }

    // The payload of a KIND change alone — it preserves what is already there:
    // a block becoming `system` keeps the Solution already linked (or, with
    // none, its current text as free text), and a block that stops being
    // `system` carries its current resolved text (the Solution's name or the
    // free text) over as the new free text, since a decision, an actor and the
    // two terminals never reference a Solution.
    function buildKindSwitchPayload(n, newKindValue) {
        const kind = nodeKind(newKindValue)
        if (kind.system) {
            return n.solutionId
                ? { kind: newKindValue, solution_id: n.solutionId, label: null }
                : { kind: newKindValue, solution_id: null, label: n.label || null }
        }
        const label = n.label || null
        if (!label && !kind.optionalLabel) return null
        return { kind: newKindValue, solution_id: null, label }
    }

    function changeNodeKind(newValue) {
        if (nodeFieldSaving || selectedIndex === null || !nodes[selectedIndex]) return
        const n = nodes[selectedIndex]
        if ((n.kind || 'system') === newValue) return
        const payload = buildKindSwitchPayload(n, newValue)
        if (!payload) return
        patchNode(selectedIndex, payload)
    }

    // The toolbar's second row: the kind icons only (`getNodeKindsList()`, the
    // same list and the same styling as `buildAddKindIcons()` in the
    // "Adicionar bloco" panel — the difference is that here there is a CURRENT
    // selection to highlight, while there each click is a new action). A
    // `system` block's Solution has no control of its own here: it is linked
    // and changed straight from the inline editor's autocomplete
    // (`startInlineLabelEdit()`), which is more intuitive than a separate
    // select.
    function refreshKindRow(index) {
        const n = nodes[index]
        if (!toolbarKindIcons || !n) return
        const currentKind = n.kind || 'system'

        toolbarKindIcons.innerHTML = ''
        getNodeKindsList().forEach((k) => {
            const active = k.value === currentKind
            const btn = document.createElement('button')
            btn.type = 'button'
            btn.title = k.label
            btn.setAttribute('aria-pressed', String(active))
            btn.className = 'flex size-[26px] shrink-0 items-center justify-center rounded-md border transition-colors ' +
                (active
                    ? 'border-accent bg-accent-soft text-accent'
                    : 'border-line text-ink hover:bg-accent-soft/60')
            if (k.icon) {
                const icon = document.createElement('span')
                icon.className = 'flex size-4 shrink-0 items-center justify-center [&>svg]:size-4'
                icon.innerHTML = k.icon
                btn.appendChild(icon)
            }
            btn.addEventListener('click', () => changeNodeKind(k.value))
            toolbarKindIcons.appendChild(btn)
        })
    }

    // ── editing the label inline, on the shape itself ────────────────────
    // A double click on the block's text swaps the static `<span>` (or
    // `.ak-viz-node-endcap-label`) for an `<input>` in its place — no separate
    // popup. On a `system` block (`isSystem`), typing also filters
    // `getSolutionsList()` into a dropdown anchored to the block itself
    // (`.ak-viz-inline-suggest`, a child of `n.el` — so it inherits the
    // canvas's pan/zoom transform for free, with no position maths of its own).
    // Picking a suggestion (a click, or Enter with one highlighted) links the
    // Solution; if the typed text matches a Solution's name EXACTLY
    // (case-insensitively) on confirm, it links too, even with no suggestion
    // clicked; any other text becomes free text. An old `solution_id` is never
    // kept beside a new text (see `ChainLabeler::nodeLabel()`: a Solution's
    // name always beats free text, so the two never coexist). A decision, an
    // actor and the two terminals have no Solution to search for — no dropdown,
    // just the text.
    function startInlineLabelEdit(index) {
        const n = nodes[index]
        if (!n || !editable || index === 0 || n.kind === 'image') return
        const textEl = n.el.querySelector('.ak-viz-node-text, .ak-viz-node-endcap-label')
        if (!textEl) return
        selectNode(index)

        const isSystem = (n.kind || 'system') === 'system'
        const input = document.createElement('input')
        input.type = 'text'
        input.className = textEl.className + ' ak-viz-node-text-input'
        input.autocomplete = 'off'
        input.spellcheck = false
        input.value = textEl.textContent
        textEl.replaceWith(input)
        input.focus()
        input.select()

        // The `<input>` and the dropdown live INSIDE `n.el`, which carries the
        // pointerdown that drags the block (`startNodePointer()`) — and that
        // one calls `preventDefault()`, which besides starting a drag in place
        // of the click also STOPS the compatibility `mousedown`/`click` from
        // ever firing. Without these two guards nothing in here is clickable:
        // neither the text (to place the caret) nor a suggestion. See the
        // canvas's pointer-events rule.
        //
        // On the input, `stopPropagation()` only: a `preventDefault()` here
        // would kill caret placement and selecting text with the mouse.
        input.addEventListener('pointerdown', (e) => e.stopPropagation())

        const suggestBox = isSystem ? document.createElement('div') : null
        if (suggestBox) {
            suggestBox.className = 'ak-viz-inline-suggest hidden'
            // On the dropdown, `preventDefault()` AS WELL: that is what keeps
            // the input focused (a pointerdown's default is to move focus), so
            // `blur` does not resolve the edit out from under the click.
            suggestBox.addEventListener('pointerdown', (e) => { e.stopPropagation(); e.preventDefault() })
            n.el.appendChild(suggestBox)
        }
        let matches = []
        let highlighted = -1

        function autosize() {
            input.size = Math.max(4, input.value.length + 1)
        }

        function paintHighlight() {
            Array.from(suggestBox.children).forEach((el, i) => el.classList.toggle('is-active', i === highlighted))
        }

        function renderMatches() {
            if (!suggestBox) return
            const term = fold(input.value.trim())
            matches = term ? getSolutionsList().filter((s) => fold(s.name).includes(term)).slice(0, 8) : []
            // The first suggestion is born highlighted: it is the one Enter
            // applies, and the highlight is what SAYS so before anybody types.
            // Without it, Enter fell through to the free-text path and replaced
            // the block's Solution with whatever had been typed ("Access"
            // instead of "AccessOne (IAM)") — the suggestion on screen could
            // not be applied at all. Free text is still reachable: a term that
            // matches nothing opens no dropdown.
            highlighted = matches.length ? 0 : -1
            suggestBox.innerHTML = ''
            suggestBox.classList.toggle('hidden', !matches.length)
            matches.forEach((s, i) => {
                const item = document.createElement('button')
                item.type = 'button'
                item.className = 'ak-viz-inline-suggest-item'
                item.textContent = s.name
                // `pointerdown`, like every gesture on this canvas (see the
                // rule): `stopPropagation()` stops the block dragging
                // underneath and `preventDefault()` keeps the input focused, so
                // `blur` does not resolve the edit before the click. A
                // `mousedown` here NEVER fires — `startNodePointer()` cancels
                // the pointerdown that would generate it — and neither does a
                // `click`; that is exactly why clicking a suggestion used to do
                // nothing at all.
                item.addEventListener('pointerdown', (e) => { e.stopPropagation(); e.preventDefault(); resolve(s) })
                suggestBox.appendChild(item)
            })
            paintHighlight()
        }

        function cleanup() {
            input.removeEventListener('input', onInput)
            input.removeEventListener('keydown', onKeydown)
            input.removeEventListener('blur', onBlur)
            suggestBox?.remove()
        }

        // An explicit `solution` means a click or Enter on a suggestion. Null
        // means Enter or blur with typed text alone — try the exact match
        // before giving up and becoming free text.
        function resolve(solution) {
            cleanup()
            if (!solution && isSystem) {
                // Folded on both sides, like the dropdown above it: somebody
                // who typed "sap cpi (integracao)" and pressed Enter meant the
                // Solution, not a free-text label that happens to read like one.
                const typed = fold(input.value.trim())
                solution = getSolutionsList().find((s) => fold(s.name) === typed) || null
            }
            if (solution) {
                if (isSystem && n.solutionId === solution.id) { paintNode(n.el, n); applyNodeStyle(n); return }
                patchNode(index, { kind: 'system', solution_id: solution.id, label: null })
                return
            }
            const typed = input.value.trim()
            if (typed === (n.label || '')) { paintNode(n.el, n); applyNodeStyle(n); return }
            commitInlineLabel(index, typed)
        }

        function cancel() {
            cleanup()
            paintNode(n.el, n)
            applyNodeStyle(n)
        }

        const onInput = () => { autosize(); renderMatches() }
        const onKeydown = (e) => {
            if (e.key === 'Escape') { e.preventDefault(); cancel(); return }
            if (e.key === 'Enter') {
                e.preventDefault()
                resolve(highlighted >= 0 ? matches[highlighted] : null)
                return
            }
            if (suggestBox && matches.length && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
                e.preventDefault()
                highlighted = e.key === 'ArrowDown'
                    ? Math.min(highlighted + 1, matches.length - 1)
                    : Math.max(highlighted - 1, 0)
                paintHighlight()
            }
        }
        // Clicking away confirms the same thing Enter does — the highlighted
        // suggestion, if there is one. One rule: what is highlighted on screen
        // is what gets applied, whether by keyboard, by clicking the row, or by
        // clicking away.
        const onBlur = () => resolve(highlighted >= 0 ? matches[highlighted] : null)

        autosize()
        renderMatches()
        input.addEventListener('input', onInput)
        input.addEventListener('keydown', onKeydown)
        input.addEventListener('blur', onBlur)
    }

    // The "free text" path of the inline editor (`startInlineLabelEdit()`) —
    // always with `solution_id: null`. Even on a block that WAS linked to a
    // Solution, confirming here is the person saying "this is not that Solution
    // any more", never "keep the Solution and change only the text" (which, by
    // `ChainLabeler`'s rule, the server would ignore anyway).
    function commitInlineLabel(index, newLabel) {
        const n = nodes[index]
        if (!n) return
        const kind = nodeKind(n.kind || 'system')
        if (!newLabel && !kind.optionalLabel) {
            window.Toast?.show?.('Informe o texto do bloco.', 'warning')
            paintNode(n.el, n)
            applyNodeStyle(n)
            return
        }
        patchNode(index, { kind: n.kind || 'system', solution_id: null, label: newLabel || null })
    }

    // ── adding a block (bare, with no link) ────────────────────────
    // A panel pinned to the canvas's corner (excalidraw.com style — the same
    // corner the block, lane and protocol toolbars use, and mutually exclusive
    // with them), carrying the block editor's own fields (kind + Solution or
    // free text) and nothing else: no arrow, no protocol. The block is born
    // loose and gets linked afterwards, by dragging an arrow from any block's
    // port onto it.
    function openAddEditor() {
        if (!editable || !graphRef) return
        selectNode(null)
        closeProtocolEditor()
        quickAddOrigin = null
        quickAddPos = null
        // With no drop point it goes back to the class's fixed `left-3 top-3`
        // corner: clearing the inline style is what hands the position back.
        resetAddEditorPosition()
        if (addHint) addHint.textContent = 'O bloco nasce solto — depois arraste uma seta de qualquer bloco até ele.'

        addEditor?.classList.remove('hidden')
        addEditor?.classList.add('flex')
        buildAddKindIcons()
    }

    function resetAddEditorPosition() {
        if (!addEditor) return
        addEditor.style.left = ''
        addEditor.style.top = ''
    }

    /**
     * Places the "Adicionar bloco" panel beside a WORLD POINT — the tip of the
     * arrow just dropped on empty canvas. Choosing the kind continues that
     * gesture, so the card appears where the gesture ended rather than in a
     * corner 1000px away (which was the old behaviour, and it meant crossing
     * the screen to pick "Sistema" and crossing back).
     *
     * The panel does NOT live inside `world`, so the conversion is manual (the
     * same maths as `screenToWorld()`, the other way round) and the result is
     * clamped inside the stage — near the right or bottom edge the card folds
     * inwards instead of spilling off screen.
     */
    function positionAddEditorAt(wx, wy) {
        if (!addEditor) return
        const sr = stage.getBoundingClientRect()
        const vr = viewport.getBoundingClientRect()
        const GAP = 14
        const pw = addEditor.offsetWidth || 224
        const ph = addEditor.offsetHeight || 120

        const x = (vr.left - sr.left) + wx * view.scale + view.x + GAP
        const y = (vr.top - sr.top) + wy * view.scale + view.y + GAP

        addEditor.style.left = Math.round(Math.max(12, Math.min(x, sr.width - pw - 12))) + 'px'
        addEditor.style.top = Math.round(Math.max(12, Math.min(y, sr.height - ph - 12))) + 'px'
    }

    // The SAME panel, opened by dropping an arrow pulled from a port
    // (`drag.type === 'connect'`) on empty canvas instead of by the "+" button
    // — see the `drag.type === 'connect'` pointerup further down.
    // `fromIndex`/`fromSide` is the origin port (to link once the block is
    // created, see `createNodeFromKind()` above); `wx`/`wy` is the WORLD point
    // of the drop, which serves TWO purposes: the new block is born exactly
    // there (not to the right of the last one) and the panel opens beside it
    // (`positionAddEditorAt()`), at the tip of the arrow just dropped —
    // choosing the kind continues that gesture. Only the topbar's "+", which
    // has no point to anchor to, opens in the fixed corner.
    function openQuickAddEditor(fromIndex, fromSide, wx, wy) {
        if (!editable || !graphRef) return
        selectNode(null)
        closeProtocolEditor()
        quickAddOrigin = { index: fromIndex, side: fromSide }
        quickAddPos = { x: wx, y: wy }

        addEditor?.classList.remove('hidden')
        addEditor?.classList.add('flex')
        buildAddKindIcons()
        if (addHint) addHint.textContent = 'A seta que você soltou já liga o bloco novo aqui.'
        // After showing it (and after building the icons): while hidden,
        // `offsetWidth`/`offsetHeight` are 0 and the edge clamp would have
        // nothing to measure.
        positionAddEditorAt(wx, wy)
    }

    function closeAddEditor() {
        // The pending link's dashed preview (`drawConnectPreview()`) exists
        // only while `quickAddOrigin` is set — without this redraw, cancelling
        // the quick-add would leave that dashed line hanging on screen until
        // the next `draw()` happened for some other reason.
        const hadQuickAdd = !!quickAddOrigin
        quickAddOrigin = null
        quickAddPos = null
        if (hadQuickAdd) draw()
        if (!addEditor || addEditor.classList.contains('hidden')) return
        addEditor.classList.add('hidden')
        addEditor.classList.remove('flex')
        resetAddEditorPosition()
    }

    addNodeBtn?.addEventListener('click', () => {
        if (addEditor?.classList.contains('hidden')) openAddEditor()
        else closeAddEditor()
    })
    addEditor?.addEventListener('pointerdown', (e) => e.stopPropagation())

    // Draws the new block and selects it, without redrawing the whole graph.
    // It is born with NO LINK at all: linking is the port drag (or "modo
    // ligar", or re-pointing an existing arrow at it) — EXCEPT when it comes
    // from `openQuickAddEditor()` (an arrow dropped on empty canvas), which
    // links right after (see `createNodeFromKind()` above). It leaves the
    // drawing dirty (the position is not in `viz_layout` yet), the same spirit
    // as `organize()`. `pos` (a WORLD point) centres the block there — it only
    // arrives from that drop flow; without it the block is born to the right of
    // the last one (the default layout's own spacing), which is what the
    // topbar's "+" has always done.
    //
    // The zoom does NOT change here (see `panIntoView()` at the end): only
    // "Organizar", "Centralizar" and the initial load re-frame.
    function appendNode(data, pos) {
        const index = nodes.length
        const el = document.createElement('div')
        el.className = 'ak-viz-node'
        paintNode(el, data)
        el.addEventListener('pointerdown', (e) => startNodePointer(e, index))
        el.addEventListener('dblclick', () => startInlineLabelEdit(index))
        world.appendChild(el)

        const prev = nodes[index - 1]
        const entry = { ...data, el, w: 0, h: 0, x: 0, y: 0, color: null, textColor: null, font: 'sans', fontSize: 'sm', imageBorderColor: null }
        nodes.push(entry)
        entry.w = el.offsetWidth
        entry.h = el.offsetHeight
        if (pos) {
            entry.x = pos.x - entry.w / 2
            entry.y = pos.y - entry.h / 2
        } else {
            entry.x = prev ? prev.x + prev.w + LEVEL_GAP : 0
            entry.y = prev ? prev.y + prev.h / 2 - entry.h / 2 : 0
        }
        el.style.left = entry.x + 'px'
        el.style.top = entry.y + 'px'
        applyNodeStyle(entry)

        if (graphRef) {
            graphRef.nodes = graphRef.nodes || []
            graphRef.nodes.push(data)
        }

        // A pasted image's <img> loads asynchronously (a real request to
        // /files/{id}) — `entry.w`/`h` above can still be 0×0 at this point,
        // so `anchorPoint()` divides every side down to the same point and
        // any arrow dragged to/from this node right after pasting resolves
        // to the same degenerate anchor no matter which side was actually
        // pulled from. Remeasure once it actually loads and redraw —
        // recentering on `pos` too, since it was centered using the wrong
        // (zero) size the first time.
        const img = el.querySelector('img')
        if (img && !img.complete) {
            img.addEventListener('load', () => {
                const newW = el.offsetWidth
                const newH = el.offsetHeight
                if (pos) {
                    entry.x -= (newW - entry.w) / 2
                    entry.y -= (newH - entry.h) / 2
                    el.style.left = entry.x + 'px'
                    el.style.top = entry.y + 'px'
                }
                entry.w = newW
                entry.h = newH
                if (!presenting) draw() // ver o mesmo guard/motivo no listener de imagem em render()
            }, { once: true })
        }

        draw()
        setDirty(true)
        selectNode(index)
        // NOT `fit()`: framing recomputes `view.scale`, so every new block
        // re-adjusted the whole canvas's zoom — drawing a ten-block flow meant
        // ten scale jumps, and the zoom the person had chosen to work at was
        // thrown away on every click of the "+". This only brings the new block
        // into view, PRESERVING the scale, and only when it was born outside
        // it.
        panIntoView(entry)
    }

    // The minimum pan that brings a block into the viewport, with `view.scale`
    // untouched. If it is already visible (with a margin) nothing moves — a
    // camera that moves when it need not is as disorienting as one that changes
    // zoom.
    //
    // NOT to be confused with `revealNode()` far below: that one belongs to
    // presentation mode and makes a block APPEAR (fade in). This one moves the
    // camera. The two names collided in the first version of this — two
    // function declarations in one scope, the second wins — and presentation
    // mode stopped revealing anything, with no console error at all.
    function panIntoView(n) {
        const pad = 40
        const vw = viewport.clientWidth
        const vh = viewport.clientHeight

        // The block's corners in SCREEN coordinates (what the viewport crops).
        const left = n.x * view.scale + view.x
        const top = n.y * view.scale + view.y
        const right = left + n.w * view.scale
        const bottom = top + n.h * view.scale

        let dx = 0
        let dy = 0
        if (right > vw - pad) dx = vw - pad - right
        if (left + dx < pad) dx = pad - left
        if (bottom > vh - pad) dy = vh - pad - bottom
        if (top + dy < pad) dy = pad - top

        if (dx === 0 && dy === 0) return

        view.x += dx
        view.y += dy
        applyView()
    }

    // The same idea as `patchRowGraph()`, but appending a node rather than
    // replacing one — it keeps the row consistent without re-selecting the
    // drawing. It does not touch `edges`: the block is born loose.
    function patchRowGraphAppend(slugArg, nodeData, summary) {
        if (!slugArg) return
        const row = document.querySelector(`[data-ak-chain-select="${CSS.escape(slugArg)}"]`)
        if (!row) return

        const raw = row.getAttribute('data-ak-chain-graph')
        if (raw) {
            try {
                const g = JSON.parse(raw)
                if (g) {
                    g.nodes = g.nodes || []
                    g.nodes.push(nodeData)
                    row.setAttribute('data-ak-chain-graph', JSON.stringify(g))
                }
            } catch {
                // malformed cache — ignore it; the next full selection reloads from the server
            }
        }
        if (typeof summary === 'string') {
            row.querySelector('[data-ak-diagram-summary]')?.replaceChildren(document.createTextNode(summary))
        }
    }

    // ── a link: direction + a protocol from the Protocol enum ─────────────
    // A panel pinned to the canvas's corner
    // (`selectEdge()`/`openProtocolEditor()`), in the same style as the block's
    // and the lane's toolbars and never anchored to the segment's pill.
    // Direction and dashed apply IMMEDIATELY (no "Salvar"/"Cancelar"), the same
    // spirit as those two. The protocol itself has no field here: it is edited
    // straight on the arrow's label, on the canvas
    // (`startInlineProtocolEdit()` below — a double click on the pill, the same
    // pattern as `startInlineLabelEdit()` on a block's text). Unlike a node,
    // there is no protected "root" segment: every edge's protocol and direction
    // can be edited, including the ones with no protocol yet (the dashed
    // "+ protocolo" pill drawn in `drawProtocolPill()`).

    // ── the link's direction: two independent toggle buttons ───────────
    // `left`/`right` mirror whether each arrowhead is active:
    // `refreshArrowButtons()` only paints the current state, `setArrowUI()`
    // receives it ready (when the editor opens), and `toggleArrowSide()`
    // answers the click AND fires the PATCH. `currentArrowValue()` is the one
    // read `patchEdgeFields()`/`createEdgeFrom()` make — they never read
    // `arrowState` directly.
    function refreshArrowButtons() {
        ;[[protocolArrowLeft, arrowState.left], [protocolArrowRight, arrowState.right]].forEach(([btn, active]) => {
            if (!btn) return
            btn.classList.toggle('border-accent', active)
            btn.classList.toggle('bg-accent-soft', active)
            btn.classList.toggle('text-accent', active)
            btn.classList.toggle('border-line', !active)
            btn.classList.toggle('text-ink', !active)
            btn.setAttribute('aria-pressed', String(active))
        })
    }

    function setArrowUI(arrow) {
        arrowState = { left: arrow === '<-' || arrow === '<->', right: arrow === '->' || arrow === '<->' }
        refreshArrowButtons()
    }

    // Ignores the click that would turn off the last active head: '->', '<-'
    // and '<->' are the only valid directions, and there is no "no head at all"
    // for the server to store. It applies at once (a PATCH), painting the new
    // state optimistically and undoing it (`onError`) if the server refuses.
    function toggleArrowSide(side) {
        if (selectedEdge === null) return
        const next = { ...arrowState, [side]: !arrowState[side] }
        if (!next.left && !next.right) {
            // Silently refusing this click would just look like a broken
            // button — say why nothing moved, same spirit as the other
            // blocked-action warnings in this file (e.g. `commitInlineLabel()`).
            window.Toast?.show?.('Pelo menos um sentido precisa ficar ativo.', 'warning')
            return
        }
        const prev = arrowState
        arrowState = next
        refreshArrowButtons()
        const index = selectedEdge
        const protocol = graphRef?.edges?.[index]?.protocol?.value ?? null
        patchEdgeFields(index, { protocol, arrow: currentArrowValue() }, () => {
            arrowState = prev
            refreshArrowButtons()
        })
    }

    function currentArrowValue() {
        if (arrowState.left && arrowState.right) return '<->'
        return arrowState.left ? '<-' : '->'
    }

    protocolArrowLeft?.addEventListener('click', () => toggleArrowSide('left'))
    protocolArrowRight?.addEventListener('click', () => toggleArrowSide('right'))

    // The arrow's dashed flag — `viz_layout` only, never `chain`, the same
    // pattern as the block's `toolbarDashedBtn`: it applies locally plus
    // `setDirty(true)`, with no PATCH of its own, and only reaches the server
    // when "Salvar" runs. The button's own border (solid or dashed) mirrors the
    // current state — no checkbox.
    function refreshProtocolDashedButton(index) {
        if (!protocolDashedBtn) return
        const dashed = !!edgeAnchors[index]?.dashed
        protocolDashedBtn.classList.toggle('border-dashed', dashed)
        protocolDashedBtn.classList.toggle('!bg-accent-soft', dashed)
    }

    protocolDashedBtn?.addEventListener('click', () => {
        if (!editable || selectedEdge === null || !edgeAnchors[selectedEdge]) return
        edgeAnchors[selectedEdge].dashed = !edgeAnchors[selectedEdge].dashed
        draw()
        refreshProtocolDashedButton(selectedEdge)
        setDirty(true)
    })

    function selectEdge(index) {
        if (!editable) return
        selectNode(null) // exclusão mútua com seleção de nó/editor de título
        selectedEdge = index
        openProtocolEditor(index)
    }

    function openProtocolEditor(index) {
        if (!protocolEditor || !graphRef) return
        const edge = graphRef.edges?.[index]

        setArrowUI(edge?.arrow || '->')
        refreshProtocolDashedButton(index)

        protocolEditor.classList.remove('hidden')
        protocolEditor.classList.add('flex')
    }

    function closeProtocolEditor() {
        closeInlineProtocolEdit()
        if (!protocolEditor || protocolEditor.classList.contains('hidden')) return
        protocolEditor.classList.add('hidden')
        protocolEditor.classList.remove('flex')
        selectedEdge = null
    }

    protocolEditor?.addEventListener('pointerdown', (e) => e.stopPropagation())

    // One PATCH to `graphRef.edgeUpdateUrl` at a time — the direction (a
    // toggle) and the protocol (edited inline on the label) both come through
    // here, each applying at once with no separate "Salvar" (the same spirit as
    // `patchNode()` for a block). `onError`, when given, undoes the change
    // already painted optimistically before the PATCH came back.
    let edgeFieldSaving = false
    async function patchEdgeFields(index, payload, onError = null) {
        const url = graphRef?.edgeUpdateUrl?.replace('EDGE_INDEX', String(index))
        if (edgeFieldSaving || !graphRef || !url) return

        edgeFieldSaving = true
        try {
            const res = await fetch(url, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(payload),
            })
            const data = await res.json().catch(() => null)
            if (!res.ok) throw new Error(data?.message || 'Não foi possível atualizar a ligação.')

            if (graphRef.edges?.[index]) {
                graphRef.edges[index].protocol = data.protocol
                graphRef.edges[index].arrow = data.arrow
            }
            patchRowEdge(slug, index, data.protocol, data.arrow)
            draw()
            setDirty(true)
            window.Toast?.show?.(data.message || 'Ligação atualizada.')
            if (selectedEdge === index) setArrowUI(data.arrow)
        } catch (err) {
            window.Toast?.show?.(err.message || 'Não foi possível atualizar a ligação.', 'error')
            onError?.()
            draw()
        } finally {
            edgeFieldSaving = false
        }
    }

    // ── editing the protocol inline, on the arrow's own label ────────────
    // A double click on the pill (`drawProtocolPill()`) swaps the static SVG
    // text for a floating `<input>` laid over it — the same idea as
    // `startInlineLabelEdit()` on a block, adapted because the pill lives
    // inside the edges svg (an ordinary HTML child cannot go into a `<g>`
    // without a `<foreignObject>`): the input and its suggestion list are
    // children of `stage` (SCREEN space), re-anchored on every
    // `applyView()`/`draw()` through `inlineProtocolReposition` — the same
    // convention the protocol toolbar uses. The suggestions come from
    // `getProtocolsList()`, and the field is free text: anything typed is
    // accepted, the list only suggests.
    function startInlineProtocolEdit(index) {
        if (!editable || !graphRef || !graphRef.edges?.[index]) return
        closeInlineProtocolEdit()
        selectEdge(index) // mantém o painel de sentido/tracejado/desligar aberto também

        inlineProtocolEditIndex = index
        draw() // esconde o texto estático desta pill (ver `drawProtocolPill()`)

        const input = document.createElement('input')
        input.type = 'text'
        input.autocomplete = 'off'
        input.spellcheck = false
        input.className = 'ak-viz-plabel-input'
        input.value = graphRef.edges[index]?.protocol?.value ?? ''
        // The same guards as `startInlineLabelEdit()`, for a similar reason:
        // these elements sit in `stage`, and a pointerdown that reaches
        // `viewport` calls `startPanning()` → `selectNode(null)` →
        // `closeProtocolEditor()` — so the editor is TORN DOWN mid-click and
        // the suggestion is never applied.
        input.addEventListener('pointerdown', (e) => e.stopPropagation())
        stage.appendChild(input)

        const suggestBox = document.createElement('div')
        suggestBox.className = 'ak-viz-plabel-suggest hidden'
        suggestBox.addEventListener('pointerdown', (e) => { e.stopPropagation(); e.preventDefault() })
        stage.appendChild(suggestBox)

        let matches = []
        let highlighted = -1

        function position() {
            const g = edgeLabelEls[index]
            if (!g) return
            const rect = g.getBoundingClientRect()
            const stageRect = stage.getBoundingClientRect()
            const left = rect.left - stageRect.left
            const top = rect.top - stageRect.top
            input.style.left = left + 'px'
            input.style.top = top + 'px'
            input.style.width = rect.width + 'px'
            input.style.height = rect.height + 'px'
            suggestBox.style.left = left + 'px'
            suggestBox.style.top = (top + rect.height + 4) + 'px'
        }

        function paintHighlight() {
            Array.from(suggestBox.children).forEach((el, i) => el.classList.toggle('is-active', i === highlighted))
        }

        function renderMatches() {
            const term = fold(input.value.trim())
            const all = getProtocolsList()
            matches = (term ? all.filter((p) => fold(p.label).includes(term)) : all).slice(0, 8)
            // The first row is highlighted only when something has been
            // TYPED: with the field empty the list shows the whole enum, and a
            // highlight would make Enter apply the first protocol in it instead
            // of clearing the protocol, which is what an empty field means.
            highlighted = term && matches.length ? 0 : -1
            suggestBox.innerHTML = ''
            suggestBox.classList.toggle('hidden', !matches.length)
            matches.forEach((p, i) => {
                const item = document.createElement('button')
                item.type = 'button'
                item.className = 'ak-viz-plabel-suggest-item'
                item.textContent = p.label
                // `pointerdown` + `stopPropagation()` + `preventDefault()`,
                // for the same reason as in `startInlineLabelEdit()`: a
                // `mousedown` here did fire (panning does not cancel the
                // pointerdown), but only AFTER `selectNode(null)` had closed
                // this editor, so the suggestion that was clicked was lost.
                item.addEventListener('pointerdown', (e) => { e.stopPropagation(); e.preventDefault(); resolve(p.label) })
                suggestBox.appendChild(item)
            })
            paintHighlight()
        }

        // It only tears down the `<input>` and the suggestions and restores
        // the pill's static text (with no full `draw()`) — `patchEdgeFields()`,
        // if `resolve()` called it, does its OWN `draw()` once the server
        // confirms, with the value already updated.
        function cleanup() {
            input.removeEventListener('input', onInput)
            input.removeEventListener('keydown', onKeydown)
            input.removeEventListener('blur', onBlur)
            input.remove()
            suggestBox.remove()
            inlineProtocolInput = null
            inlineProtocolReposition = null
            inlineProtocolEditIndex = null
            const text = edgeLabelEls[index]?.querySelector('.ak-viz-plabel-text')
            if (text) text.style.opacity = ''
        }

        function resolve(text) {
            const typed = (text ?? input.value).trim()
            cleanup()
            const current = graphRef.edges?.[index]?.protocol?.value ?? ''
            if (typed === current) return
            const arrow = graphRef.edges?.[index]?.arrow || '->'
            patchEdgeFields(index, { protocol: typed || null, arrow })
        }

        function cancel() {
            cleanup()
        }

        const onInput = () => renderMatches()
        const onKeydown = (e) => {
            if (e.key === 'Escape') { e.preventDefault(); cancel(); return }
            if (e.key === 'Enter') {
                e.preventDefault()
                resolve(highlighted >= 0 ? matches[highlighted].label : null)
                return
            }
            if (matches.length && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
                e.preventDefault()
                highlighted = e.key === 'ArrowDown'
                    ? Math.min(highlighted + 1, matches.length - 1)
                    : Math.max(highlighted - 1, 0)
                paintHighlight()
            }
        }
        const onBlur = () => resolve(highlighted >= 0 ? matches[highlighted].label : null)

        inlineProtocolInput = input
        inlineProtocolReposition = position
        position()
        renderMatches()
        input.addEventListener('input', onInput)
        input.addEventListener('keydown', onKeydown)
        input.addEventListener('blur', onBlur)
        input.focus()
        input.select()
    }

    // Closes an inline edit in progress (if there is one) by forcing the
    // input's `blur` — reusing the same confirm/PATCH path a natural Enter or
    // blur takes (`resolve()` above) instead of duplicating the logic here.
    function closeInlineProtocolEdit() {
        inlineProtocolInput?.blur()
    }

    // Appends a link just created (`createEdgeFrom()`) to the local graph AT
    // THE INDEX THE SERVER gave it (`data.index`). Everything else in the link
    // editor (protocol, retarget, disconnect) addresses an edge BY INDEX, so
    // inferring the index from the local insertion order silently misaligns all
    // of it when two POSTs are in flight and the answers come back out of
    // order. The callers' `creatingEdge` prevents that; the length check here
    // is the assertion of it — and it also covers somebody else having edited
    // the same drawing while this tab was open. Better not to draw (and ask for
    // a reload) than to draw at the wrong index.
    function appendEdgeLocally(data, fromSide, toSide, dashed = false) {
        graphRef.edges = graphRef.edges || []

        if (data.index !== graphRef.edges.length) {
            window.Toast?.show?.('A ligação foi criada, mas este desenho está defasado — recarregue a página.', 'warning')
            return false
        }

        graphRef.edges.push({ from: data.from, to: data.to, arrow: data.arrow, protocol: data.protocol })
        edgeAnchors.push({ from: fromSide, to: toSide, dashed })
        return true
    }

    // Removes the link being edited — a DELETE to `graphRef.edgeRemoveUrl`.
    // The nodes go on existing; if that was a block's only link, it simply
    // appears isolated in the graph from then on.
    protocolDelete?.addEventListener('click', async () => {
        if (selectedEdge === null) return
        if (!window.confirm('Desligar esta ligação? Os blocos continuam existindo.')) return

        const index = selectedEdge
        const url = graphRef?.edgeRemoveUrl?.replace('EDGE_INDEX', String(index))
        if (!url) return

        protocolDelete.disabled = true
        try {
            const res = await fetch(url, {
                method: 'DELETE',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
            })
            const data = await res.json().catch(() => null)
            if (!res.ok) throw new Error(data?.message || 'Não foi possível desligar a ligação.')

            graphRef.edges?.splice(index, 1)
            edgeAnchors.splice(index, 1)
            patchRowGraphRemoveEdge(slug, index, data.summary)
            closeProtocolEditor()
            draw()
            window.Toast?.show?.(data.message || 'Ligação removida.')
        } catch (err) {
            window.Toast?.show?.(err.message || 'Não foi possível desligar a ligação.', 'error')
        } finally {
            protocolDelete.disabled = false
        }
    })

    /**
     * The LIVE layout, in the shape `SaveChainLayoutRequest` validates.
     *
     * Extracted from "Salvar" because DELETING a block needs it too: that
     * redraws the canvas from the graph the server answers with, and without
     * this everything not yet saved — a dragged position, the theme, a block's
     * colour, a lane, a note — went back to whatever was stored. A payload
     * built in two places would be two lists of fields to forget to update,
     * which is how `rounded`/`opacity`/`orientation` fell out of it once
     * already.
     */
    function layoutPayload() {
        return {
            nodes: nodes.map((n) => ({
                x: Math.round(n.x),
                y: Math.round(n.y),
                color: n.color || null,
                textColor: n.textColor || null,
                font: n.font || 'sans',
                fontSize: n.fontSize || 'sm',
                dashed: !!n.dashed,
                imageBorderColor: n.imageBorderColor || null,
                logoOnly: !!n.logoOnly,
                // Only a lifeline stores a height; for the others this goes
                // null and the server accepts it (nullable) without recording
                // any size — the block stays as big as what is written in it.
                height: n.kind === 'lifeline' && Number.isFinite(n.height) ? Math.round(n.height) : null,
            })),
            edges: edgeAnchors.map((a) => ({
                from: a.from,
                to: a.to,
                dashed: !!a.dashed,
                fromT: Number.isFinite(a.fromT) ? a.fromT : null,
                toT: Number.isFinite(a.toT) ? a.toT : null,
            })),
            comments: nodes.map((n) => n.comment || null),
            // `rounded`/`dashed`/`opacity`/`orientation`/`showTitle`/`fontSize`
            // were silently missing from this payload before — editable live
            // in the lane toolbar and validated server-side, but never
            // actually reaching "Salvar" (a viewer reloading the page always
            // saw them reset to default). Sending the full style now that
            // `headerColor`/`fontSize` need it too.
            lanes: lanes.map((l) => ({
                label: l.label,
                color: l.color,
                headerColor: l.headerColor || null,
                x: l.x,
                y: l.y,
                width: l.width,
                height: l.height,
                rounded: !!l.rounded,
                dashed: !!l.dashed,
                opacity: l.opacity,
                orientation: l.orientation || 'horizontal',
                showTitle: l.showTitle !== false,
                fontSize: l.fontSize || 'sm',
            })),
            notes: notes.map((n) => ({ x: Math.round(n.x), y: Math.round(n.y), text: n.text || '' })),
            theme: currentTheme,
        }
    }

    // ── deleting a block ──────────────────────────────────────────────
    // Unlike everything else that edits the chain, no local patch is possible
    // here: removing a node reindexes `chain.nodes`, and with it every
    // `from`/`to` in `chain.edges` above the removed index — plus the
    // positions, comments and anchors in `viz_layout`. The server does that
    // reindex and answers with the WHOLE graph already resolved (the same shape
    // as the initial `data-ak-chain-graph`), so the honest path is to redraw
    // with `render()` rather than to patch the local arrays. See
    // `DiagramController::removeNode()`.
    toolbarRemoveBtn?.addEventListener('click', async () => {
        if (!editable || selectedIndex === null || selectedIndex === 0) return

        const index = selectedIndex
        const label = nodes[index]?.label || 'este bloco'
        // Which links go with it: the COUNT is what warns the user, and the
        // INDICES reindex the live layout after the delete (see below).
        const linkedEdges = (graphRef?.edges || [])
            .map((e, i) => (e.from === index || e.to === index ? i : -1))
            .filter((i) => i >= 0)
        const linked = linkedEdges.length
        const wasDirty = dirty
        const warning = linked
            ? `\n\n${linked} ${linked === 1 ? 'ligação será removida' : 'ligações serão removidas'} junto.`
            : ''
        if (!window.confirm(`Excluir "${label}"?${warning}`)) return

        const url = graphRef?.nodeRemoveUrl?.replace('NODE_INDEX', String(index))
        if (!url) return

        toolbarRemoveBtn.disabled = true
        try {
            const res = await fetch(url, {
                method: 'DELETE',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
            })
            const data = await res.json().catch(() => null)
            if (!res.ok) throw new Error(data?.message || 'Não foi possível excluir o bloco.')

            // `render()` redraws from the server's graph, and the layout it
            // re-applies is whatever is in `savedLayouts` — which is only
            // written by "Salvar". Throwing that entry away (what this used to
            // do) meant losing EVERYTHING not yet saved: a dragged position,
            // the chosen theme, a block's colour, a lane. Deleting one block
            // undid the whole drawing.
            //
            // Instead, the live state is reindexed here the same way the server
            // reindexed its own: minus the removed node, minus the anchors of
            // the links that died with it. The indices are known locally —
            // `linkedEdges` was computed BEFORE the fetch, against the graph
            // that still had the node.
            const carried = layoutPayload()
            carried.nodes.splice(index, 1)
            carried.comments.splice(index, 1)
            carried.edges = carried.edges.filter((_, i) => !linkedEdges.includes(i))
            savedLayouts.set(slug, carried)

            patchRowGraphReplace(slug, data.graph, data.summary)
            render(data.graph, currentName, slug)
            // The drawing on screen stopped matching what is stored the moment
            // we carried the live state across, so there is still something to
            // save.
            if (wasDirty) setDirty(true)
            window.Toast?.show?.(data.message || 'Bloco excluído.')
        } catch (err) {
            window.Toast?.show?.(err.message || 'Não foi possível excluir o bloco.', 'error')
        } finally {
            toolbarRemoveBtn.disabled = false
        }
    })

    // ── the comment sidebar (markdown) ───────────────────────────
    function isSidebarOpen() {
        return !!sidebar && !sidebar.classList.contains('translate-x-full')
    }

    // Shifts the zoom footer so it does not sit under the open sidebar (the
    // same adjustment the reference mind map makes with #zoomctl).
    function positionBottomBar() {
        if (!bottomBar) return
        const shift = isSidebarOpen() ? sidebar.offsetWidth / 2 : 0
        bottomBar.style.transform = shift ? `translateX(calc(-50% - ${shift}px))` : ''
    }

    function openComment(index) {
        const n = nodes[index]
        if (!n || !sidebar) return
        commentIndex = index
        if (sidebarNode) sidebarNode.textContent = n.label ?? ''
        if (sidebarInput) {
            sidebarInput.value = n.comment || ''
            sidebarInput.readOnly = !editable
        }
        renderCommentPreview()
        sidebar.classList.remove('translate-x-full')
        positionBottomBar()
    }

    function closeComment() {
        if (!sidebar) return
        sidebar.classList.add('translate-x-full')
        commentIndex = null
        positionBottomBar()
    }

    function renderCommentPreview() {
        if (sidebarPreview) sidebarPreview.innerHTML = renderMarkdown(sidebarInput?.value ?? '')
    }

    sidebarInput?.addEventListener('input', () => {
        renderCommentPreview()
        if (commentIndex !== null && nodes[commentIndex] && editable) {
            const value = sidebarInput.value
            nodes[commentIndex].comment = value
            nodes[commentIndex].el.classList.toggle('has-comment', !!value.trim())
            setDirty(true)
        }
    })
    sidebar?.addEventListener('pointerdown', (e) => e.stopPropagation())
    sidebarClose?.addEventListener('click', closeComment)

    toolbar?.addEventListener('pointerdown', (e) => e.stopPropagation())
    toolbarComment?.addEventListener('click', () => { if (selectedIndex !== null) openComment(selectedIndex) })
    toolbarRenameBtn?.addEventListener('click', () => { if (selectedIndex !== null) startInlineLabelEdit(selectedIndex) })

    // ── pulling an arrow out of a block's port (a new link) ────────
    // Available on EVERY block, the root included: the link does not exist yet
    // while the pointer is down — only the dashed preview
    // (`drawConnectPreview()`). Dropping on another block creates it
    // (`createEdgeFrom()`); dropping outside every block, or on the origin
    // block itself, cancels with no effect at all.
    function startPortDrag(e, index, side) {
        if (e.button !== 0) return // same guard as startHandleDrag (the caller has one too)
        const w = screenToWorld(e.clientX, e.clientY)
        selectNode(null)
        drag = { type: 'connect', from: index, side, wx: w.x, wy: w.y, targetNode: null, toSide: 'l' }
        draw()
    }

    // Highlights the block under the pointer during a drag (the link's target).
    function setLinkTarget(index) {
        nodes.forEach((n, i) => n.el.classList.toggle('is-link-target', i === index))
    }

    // Where the preview comes from: the drag itself
    // (`drag.type === 'connect'`) OR, after a drop on empty canvas, the
    // quick-add still open (`quickAddOrigin`/`quickAddPos` — `targetNode` is
    // always `null` there, since no block sits under the point the arrow was
    // dropped on).
    function drawConnectPreview() {
        const src = drag?.type === 'connect'
            ? drag
            : { from: quickAddOrigin.index, side: quickAddOrigin.side, wx: quickAddPos.x, wy: quickAddPos.y, targetNode: null, toSide: 'l' }
        const from = nodes[src.from]
        if (!from) return

        const a0 = anchorPoint(from, src.side)
        const p0 = { x: a0.x + a0.nx * EDGE_GAP, y: a0.y + a0.ny * EDGE_GAP, nx: a0.nx, ny: a0.ny }
        let p1 = { x: src.wx, y: src.wy, nx: 0, ny: 0 }
        // Over a block, the preview sticks to the anchor the arrow will be
        // born on rather than to the pointer — exactly what `viz_layout` will
        // store.
        if (src.targetNode !== null && nodes[src.targetNode]) {
            const a1 = anchorPoint(nodes[src.targetNode], src.toSide)
            p1 = { x: a1.x + a1.nx * EDGE_GAP, y: a1.y + a1.ny * EDGE_GAP, nx: a1.nx, ny: a1.ny }
        }

        const path = document.createElementNS(SVG_NS, 'path')
        path.setAttribute('class', 'ak-viz-edge is-preview')
        // Snapped to a block, the preview already shows the route that will be
        // drawn; loose in empty space it stays a straight line to the pointer,
        // which is what the gesture is saying at that moment.
        path.setAttribute('d', src.targetNode !== null && nodes[src.targetNode]
            ? roundedPath(orthogonalPoints(p0, p1))
            : `M ${p0.x} ${p0.y} L ${p1.x} ${p1.y}`)
        path.setAttribute('marker-end', `url(#${markerEnd.id})`)
        edges.appendChild(path)
    }

    // The new link's POST, born `->` and with no protocol — no dialog in the
    // way: direction and protocol are adjusted afterwards on the arrow's pill.
    // The link enters the local graph only once the server says OK (see
    // `appendEdgeLocally()`). The anchors come from the gesture itself (the
    // origin port and the side it was dropped on), and since an anchor is
    // visual (`viz_layout`), that leaves the layout pending a save.
    async function createEdgeFrom(from, to, fromSide, toSide) {
        // One link POST at a time: the gesture is quick enough for two drags
        // to overlap, and it is the order of the ANSWERS that decides the
        // edge's local index (see `appendEdgeLocally()`).
        if (!graphRef?.edgeAddUrl || creatingEdge) return
        creatingEdge = true

        try {
            const res = await fetch(graphRef.edgeAddUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ from, to, arrow: '->', protocol: null }),
            })
            const data = await res.json().catch(() => null)
            if (!res.ok) throw new Error(data?.message || 'Não foi possível criar a ligação.')

            if (appendEdgeLocally(data, fromSide, toSide)) {
                patchRowGraphAddEdge(slug, data.from, data.to, data.arrow, data.protocol, data.summary)
                draw()
                setDirty(true)
                window.Toast?.show?.(data.message || 'Ligação criada.')
                // Opens the new link's compact menu right away (the
                // direction/dashed/disconnect icons) — the protocol itself is
                // set afterwards, straight on the label (see the panel's
                // comment in the blade).
                selectEdge(data.index)
            }
        } catch (err) {
            window.Toast?.show?.(err.message || 'Não foi possível criar a ligação.', 'error')
        } finally {
            creatingEdge = false
        }
    }

    // ── dragging an arrow's end (moving its anchor OR re-pointing it) ──
    function startHandleDrag(e, edgeIndex, end) {
        if (e.button !== 0) return
        e.stopPropagation()
        e.preventDefault()
        const edge = graphRef.edges[edgeIndex]
        const origNode = end === 'from' ? edge.from : edge.to
        const otherNode = end === 'from' ? edge.to : edge.from
        drag = { type: 'handle', edge: edgeIndex, end, origNode, otherNode, targetNode: origNode }
        draw()
    }

    // The node whose rectangle contains the given world point, or null — read
    // during a handle drag to know whether the pointer is over a block other
    // than that end's original node (a retarget) or not (just a new anchor).
    function nodeAtPoint(wx, wy) {
        for (let i = 0; i < nodes.length; i++) {
            const n = nodes[i]
            if (wx >= n.x && wx <= n.x + n.w && wy >= n.y && wy <= n.y + n.h) return i
        }
        return null
    }

    // The PATCH that re-points link `edgeIndex`'s `end` at node `newNode`.
    // Already applied OPTIMISTICALLY to `graphRef.edges` before this fetch (on
    // pointerup, see below), which keeps the link from visually "springing
    // back" while the request is in flight; this only confirms it in the row's
    // cache or undoes the optimistic write if the server refuses.
    async function retargetEdge(edgeIndex, end, newNode, origNode) {
        const url = graphRef?.edgeRetargetUrl?.replace('EDGE_INDEX', String(edgeIndex))
        if (!url) return

        try {
            const res = await fetch(url, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ end, node: newNode }),
            })
            const data = await res.json().catch(() => null)
            if (!res.ok) throw new Error(data?.message || 'Não foi possível religar o bloco.')

            patchRowGraphEdge(slug, edgeIndex, end, newNode, data.summary)
            window.Toast?.show?.(data.message || 'Ligação atualizada.')
        } catch (err) {
            if (graphRef.edges?.[edgeIndex]) graphRef.edges[edgeIndex][end] = origNode
            if (edgeAnchors[edgeIndex]) edgeAnchors[edgeIndex][end] = end === 'from' ? 'r' : 'l'
            draw()
            window.Toast?.show?.(err.message || 'Não foi possível religar o bloco.', 'error')
        }
    }

    // ── clicking and dragging a block ───────────────────────────────
    // It always intercepts, even without `editable`, so that a click with no
    // drag selects the node instead of bubbling up to the canvas pan. It only
    // actually moves the block when `editable`.
    function startNodePointer(e, index) {
        if (e.button !== 0) return
        e.stopPropagation()
        e.preventDefault()
        // A connection port: instead of moving the block, this starts pulling
        // an arrow from it towards another block (`startPortDrag()`). The ports
        // only exist when editable (CSS), but the check lives here too.
        const port = editable ? e.target.closest?.('[data-viz-port]') : null
        if (port) {
            startPortDrag(e, index, port.getAttribute('data-viz-port'))
            return
        }
        const w = screenToWorld(e.clientX, e.clientY)
        drag = { type: 'node', index, startWX: w.x, startWY: w.y, origX: nodes[index].x, origY: nodes[index].y, moved: false }
        if (editable) nodes[index].el.classList.add('is-dragging')
    }

    function nearestAnchor(node, wx, wy) {
        let best = 'r'
        let bestDist = Infinity
        ANCHOR_KEYS.forEach((key) => {
            const p = anchorPoint(node, key)
            const dd = (p.x - wx) ** 2 + (p.y - wy) ** 2
            if (dd < bestDist) {
                bestDist = dd
                best = key
            }
        })
        return best
    }

    function fit() {
        const bbox = nodesBBox()
        if (!bbox) {
            applyView()
            return
        }
        const vw = viewport.clientWidth
        const vh = viewport.clientHeight
        const cw = bbox.maxX - bbox.minX + FIT_PAD * 2
        const ch = bbox.maxY - bbox.minY + FIT_PAD * 2
        view.scale = Math.min(MAX_SCALE, Math.max(MIN_SCALE, Math.min(vw / cw, vh / ch)))
        view.x = (vw - (bbox.maxX + bbox.minX) * view.scale) / 2
        view.y = (vh - (bbox.maxY + bbox.minY) * view.scale) / 2
        applyView()
    }

    function zoomAt(factor, clientX, clientY) {
        const r = viewport.getBoundingClientRect()
        const px = (clientX ?? r.left + r.width / 2) - r.left
        const py = (clientY ?? r.top + r.height / 2) - r.top
        const wx = (px - view.x) / view.scale
        const wy = (py - view.y) / view.scale
        view.scale = Math.min(MAX_SCALE, Math.max(MIN_SCALE, view.scale * factor))
        view.x = px - wx * view.scale
        view.y = py - wy * view.scale
        applyView()
    }

    async function save() {
        if (!editable || !saveUrl || !dirty) return
        const payload = layoutPayload()
        saveBtn.disabled = true
        if (saveLabel) saveLabel.textContent = 'Salvando…'
        try {
            const res = await fetch(saveUrl, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(payload),
            })
            if (!res.ok) throw new Error('save failed')
            savedLayouts.set(slug, payload)
            if (saveLabel) saveLabel.textContent = 'Salvo'
            setDirty(false)
            window.Toast?.show?.('Layout salvo.')
            publishDiagram()
            setTimeout(() => { if (saveLabel && !dirty) saveLabel.textContent = 'Salvar' }, 1500)
        } catch {
            if (saveLabel) saveLabel.textContent = 'Salvar'
            saveBtn.disabled = false
            window.Toast?.show?.('Não foi possível salvar o layout.', 'error')
        }
    }

    /**
     * Publishes the canvas's picture after a successful "Salvar".
     *
     * It is a DERIVED copy — the topology is still the `chain` and the
     * positions are still `viz_layout`. It exists so a CATI deck can show the
     * architecture without a browser in the loop: the deck embeds this picture
     * and links back to the canvas, which keeps the canvas the one place a
     * diagram is edited.
     *
     * Deliberately not awaited and with no visible error handling: capturing an
     * image is expensive and can fail (a font, a pasted image that never
     * loaded), and none of that may turn a save that WORKED into an error in
     * somebody's face. Failed? The deck uses the previous picture, and the next
     * "Salvar" tries again.
     */
    function publishDiagram() {
        if (!diagramUrl || !editable) return

        ;(async () => {
            try {
                const canvas = await captureDiagramCanvas()
                if (!canvas) return

                const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'))
                if (!blob) return

                const body = new FormData()
                body.append('image', blob, `${exportFileBase()}.png`)

                await fetch(diagramUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body,
                })
            } catch {
                // silent, on purpose — see the docblock above
            }
        })()
    }

    // ── pan (canvas) + zoom (roda) ────────────────────────────────
    let panning = false
    let sx = 0
    let sy = 0
    let ox = 0
    let oy = 0

    // The last known pointer position over the canvas — read by the topbar's
    // +/- buttons to anchor the zoom there instead of at the viewport's centre
    // (see `zoomAt()` below), exactly as the mouse wheel already does. `null`
    // until the pointer enters the canvas for the first time (`zoomAt` falls
    // back to the centre then, through `clientX ?? ...`). It is deliberately
    // not cleared on leaving the canvas: people typically move the mouse TO the
    // +/- button, outside the viewport, before clicking, so "forgetting" the
    // last position inside would defeat the purpose — like every canvas editor
    // (Figma, Miro), the zoom buttons should stay anchored around where the
    // person was looking rather than jump to the centre because the pointer
    // left.
    let lastPointerX = null
    let lastPointerY = null
    viewport.addEventListener('pointermove', (e) => {
        lastPointerX = e.clientX
        lastPointerY = e.clientY
    })

    function startPanning(e) {
        selectNode(null)
        panning = true
        sx = e.clientX
        sy = e.clientY
        ox = view.x
        oy = view.y
        viewport.classList.add('is-panning')
    }

    // Ctrl+click forces the pan even with the pointer over a block, a port, a
    // lane, a handle or a protocol pill — all of them live inside `viewport`
    // (through `world`), so a CAPTURE listener here runs before those elements'
    // own pointerdown (which would start a drag or a selection instead of
    // moving the canvas), and `stopPropagation()` in that phase keeps them from
    // running at all. Without it, moving the canvas means hitting an empty
    // piece of the ground — impossible once the chain fills the viewport.
    viewport.addEventListener('pointerdown', (e) => {
        if (e.button !== 0 || !e.ctrlKey || drag) return
        e.stopPropagation()
        e.preventDefault()
        startPanning(e)
    }, true)

    viewport.addEventListener('pointerdown', (e) => {
        if (e.button !== 0 || drag) return
        startPanning(e)
    })
    window.addEventListener('pointermove', (e) => {
        if (drag?.type === 'node') {
            const w = screenToWorld(e.clientX, e.clientY)
            const dx = w.x - drag.startWX
            const dy = w.y - drag.startWY
            if (Math.abs(dx) > MOVE_TOLERANCE || Math.abs(dy) > MOVE_TOLERANCE) drag.moved = true
            if (editable) {
                const n = nodes[drag.index]
                n.x = drag.origX + dx
                n.y = drag.origY + dy
                n.el.style.left = n.x + 'px'
                n.el.style.top = n.y + 'px'
                draw()
            }
            return
        }
        if (drag?.type === 'connect') {
            const w = screenToWorld(e.clientX, e.clientY)
            drag.wx = w.x
            drag.wy = w.y
            const hover = nodeAtPoint(w.x, w.y)
            // Only another block counts as a target — a block cannot link to
            // itself (the server refuses it too, see `to.different`).
            drag.targetNode = (hover !== null && hover !== drag.from) ? hover : null
            drag.toSide = drag.targetNode !== null ? nearestAnchor(nodes[drag.targetNode], w.x, w.y) : 'l'
            setLinkTarget(drag.targetNode)
            draw()
            return
        }
        if (drag?.type === 'handle') {
            const w = screenToWorld(e.clientX, e.clientY)
            // Over another block (not the same link's opposite end, which
            // would be a block linked to itself): preview the retarget onto
            // that block. Outside every block, or over the opposite end: back
            // to the original node, with only the anchor changing.
            const hover = nodeAtPoint(w.x, w.y)
            drag.targetNode = (hover !== null && hover !== drag.otherNode) ? hover : drag.origNode
            edgeAnchors[drag.edge][drag.end] = nearestAnchor(nodes[drag.targetNode], w.x, w.y)
            draw()
            return
        }
        if (drag?.type === 'lane-resize') {
            // A SCREEN delta converted to WORLD (`/ view.scale`), the same
            // reasoning as `screenToWorld()`: dragging 10 screen px should
            // change the size by less "world" the further you are zoomed in, or
            // a lane would grow and shrink far too fast at high zoom. `dir`
            // decides which axes move: 'e' width only, 's' height only, 'se'
            // both (one lane, one drag).
            const lane = lanes[drag.index]
            const entry = laneEls[drag.index]
            if (!lane || !entry) return
            if (drag.dir.includes('e')) {
                const dx = (e.clientX - drag.startClientX) / view.scale
                lane.width = Math.round(Math.max(LANE_MIN_SIZE, Math.min(LANE_MAX_SIZE, drag.startW + dx)))
                entry.wrap.style.width = lane.width + 'px'
            }
            if (drag.dir.includes('s')) {
                const dy = (e.clientY - drag.startClientY) / view.scale
                lane.height = Math.round(Math.max(LANE_MIN_SIZE, Math.min(LANE_MAX_SIZE, drag.startH + dy)))
                entry.wrap.style.height = lane.height + 'px'
            }
            return
        }
        if (drag?.type === 'lane-move') {
            const lane = lanes[drag.index]
            const entry = laneEls[drag.index]
            if (!lane || !entry) return
            const dx = (e.clientX - drag.startClientX) / view.scale
            const dy = (e.clientY - drag.startClientY) / view.scale
            // The same click-versus-drag distinction a block makes
            // (`MOVE_TOLERANCE`, see `startNodePointer()`) — it decides on
            // pointerup whether this becomes "select the lane" (opening the
            // toolbar) or "confirm the drag".
            if (Math.abs(dx) > MOVE_TOLERANCE || Math.abs(dy) > MOVE_TOLERANCE) drag.moved = true
            lane.x = Math.round(drag.startX + dx)
            lane.y = Math.round(drag.startY + dy)
            entry.wrap.style.left = lane.x + 'px'
            entry.wrap.style.top = lane.y + 'px'
            return
        }
        if (drag?.type === 'note-move') {
            const note = notes[drag.index]
            const entry = noteEls[drag.index]
            if (!note || !entry) return
            const dx = (e.clientX - drag.startClientX) / view.scale
            const dy = (e.clientY - drag.startClientY) / view.scale
            if (Math.abs(dx) > MOVE_TOLERANCE || Math.abs(dy) > MOVE_TOLERANCE) drag.moved = true
            note.x = Math.round(drag.startX + dx)
            note.y = Math.round(drag.startY + dy)
            entry.wrap.style.left = note.x + 'px'
            entry.wrap.style.top = note.y + 'px'
            return
        }
        if (!panning) return
        view.x = ox + (e.clientX - sx)
        view.y = oy + (e.clientY - sy)
        applyView()
    })
    /**
     * The end of a gesture. `cancelled` tells `pointercancel` from `pointerup`:
     * a TOUCH pointer can be cancelled by the browser (a system gesture, a
     * second finger, the element leaving the DOM) without ever firing
     * `pointerup` — and since every drag lives in the `drag` object until an
     * end event clears it, ignoring that would leave the canvas stuck mid-drag,
     * with no way out but a reload.
     *
     * On a cancel only the ACTIONS are abandoned — completing a link,
     * re-pointing an arrow's end, selecting by click-without-drag. Whatever has
     * already moved on screen (a block, a lane, a note) keeps its new position
     * and is marked dirty: the gesture was interrupted, but the person is
     * looking at its result, so undoing it silently would be more surprising
     * than keeping it.
     */
    function endPointer(cancelled = false) {
        if (drag) {
            if (drag.type === 'node') {
                nodes[drag.index]?.el.classList.remove('is-dragging')
                if (!drag.moved) { if (! cancelled) selectNode(drag.index) }
                else if (editable) setDirty(true)
            } else if (drag.type === 'handle') {
                if (cancelled) {
                    // The `draw()` at the end redraws from `graphRef`, so the
                    // dragged end returns to where it started by itself.
                } else if (drag.targetNode !== drag.origNode) {
                    // Applied optimistically before the PATCH — see `retargetEdge()`.
                    graphRef.edges[drag.edge][drag.end] = drag.targetNode
                    retargetEdge(drag.edge, drag.end, drag.targetNode, drag.origNode)
                } else {
                    setDirty(true)
                }
            } else if (drag.type === 'connect') {
                // Dropped on another block: create the link. Dropped on empty
                // canvas: open "Adicionar bloco" — one click on a kind's icon
                // creates the block AT THAT POINT and completes the link (see
                // `openQuickAddEditor()`/`createNodeFromKind()`). Pulling an
                // arrow into empty space and letting go is how you get a new
                // block already linked, rather than simply a cancel.
                setLinkTarget(null)
                if (cancelled) {
                    // Give up: neither create the link nor open "Adicionar bloco".
                } else if (drag.targetNode !== null) createEdgeFrom(drag.from, drag.targetNode, drag.side, drag.toSide)
                else openQuickAddEditor(drag.from, drag.side, drag.wx, drag.wy)
            } else if (drag.type === 'lane-resize') {
                laneEls[drag.index]?.handles[drag.dir]?.classList.remove('is-resizing')
                setDirty(true)
            } else if (drag.type === 'lane-move') {
                // A click with no drag ON THE LABEL selects the lane (opening
                // the colour/name/remove/style toolbar). A click with no drag
                // anywhere else on the body does nothing — only the label is a
                // selection target, EXCEPT when the lane has no title
                // (`showTitle === false`, where `rebuildLanes()` already sets
                // `onLabel` true for the whole body), since there is no
                // separate strip to reserve then. A real drag, from any part of
                // the body, only confirms the new position — the same
                // distinction `drag.type === 'node'` makes above.
                if (!drag.moved) { if (drag.onLabel && ! cancelled) selectLane(drag.index) }
                else setDirty(true)
            } else if (drag.type === 'note-move') {
                // No toolbar or selection to open — a note has nothing but a
                // position and its text (and the text is always edited straight
                // in the body). A click with no drag on the strip does nothing;
                // only a real drag marks the position dirty.
                if (drag.moved) setDirty(true)
            }
            drag = null
            draw()
        }
        panning = false
        viewport.classList.remove('is-panning')
    }
    window.addEventListener('pointerup', () => endPointer(false))
    window.addEventListener('pointercancel', () => endPointer(true))

    viewport.addEventListener('wheel', (e) => {
        e.preventDefault()
        zoomAt(e.deltaY < 0 ? 1.08 : 1 / 1.08, e.clientX, e.clientY)
    }, { passive: false })

    // One-finger touch panning already comes free from the POINTER listeners
    // above (`pointerdown`/`pointermove`/`pointerup` fire for touch, pen and
    // mouse alike, and `.ak-viz-viewport` sets `touch-action: none`, so the
    // browser does not steal the gesture to scroll the page).
    //
    // A dedicated `touchstart`/`touchmove`/`touchend` trio used to live here for
    // that pan. It was REMOVED rather than ported: touch events are a separate
    // stream from mouse events, so a touch on a block bubbled to the viewport
    // without passing through the block's `stopPropagation()` (which only
    // existed on `mousedown`) — dragging a block with a finger moved the CANVAS
    // instead of the block. Keeping it alongside the pointer listeners would
    // only trade that bug for another: both streams would fire on one gesture
    // and the pan would run together with the block's drag.

    // ── controls ────────────────────────────────────────────────
    // Anchored on the pointer's last position over the canvas
    // (`lastPointerX/Y`) rather than on the viewport's centre: clicking + over
    // and over used to push any block away from the centre (say the leftmost of
    // a long chain) towards the edge of the screen with each click, even though
    // the zoom was mathematically correct — verified, the formula matched "zoom
    // anchored at the viewport's centre" exactly at every step. It simply was
    // not what somebody expects after looking at one particular block and then
    // zooming in.
    root.querySelector('[data-viz-zoom-in]')?.addEventListener('click', () => zoomAt(1.12, lastPointerX, lastPointerY))
    root.querySelector('[data-viz-zoom-out]')?.addEventListener('click', () => zoomAt(1 / 1.12, lastPointerX, lastPointerY))
    root.querySelector('[data-viz-fit]')?.addEventListener('click', fit)
    organizeBtn?.addEventListener('click', organize)
    saveBtn?.addEventListener('click', save)

    presentToggleBtn?.addEventListener('click', () => { presenting ? exitPresentation() : enterPresentation() })
    presentSpeedSelect?.addEventListener('change', () => {
        const v = Number(presentSpeedSelect.value)
        if (v > 0) presentSpeedMultiplier = v
    })
    exportPngBtn?.addEventListener('click', exportImage)
    exportGifBtn?.addEventListener('click', exportVideo)
    themeSelect?.addEventListener('change', () => applyTheme(themeSelect.value))

    // ── the browser's own full screen (bottom-bar button) ──
    const fsOpen = root.querySelector('[data-viz-fs-open]')
    const fsClose = root.querySelector('[data-viz-fs-close]')
    function toggleFullscreen() {
        if (document.fullscreenElement === root) document.exitFullscreen?.()
        else root.requestFullscreen?.()
    }
    root.querySelector('[data-viz-fullscreen]')?.addEventListener('click', toggleFullscreen)
    document.addEventListener('fullscreenchange', () => {
        const isFs = document.fullscreenElement === root
        fsOpen?.classList.toggle('hidden', isFs)
        fsClose?.classList.toggle('hidden', !isFs)
        requestAnimationFrame(() => requestAnimationFrame(() => fit()))
    })

    window.addEventListener('resize', () => {
        if (nodes.length) fit()
        positionBottomBar()
    })

    // `render()` measures every node's w/h via offsetWidth/offsetHeight right
    // after creating its <div> (see below) — but this canvas lives inside the
    // unified Documentação/Diagrama tabs, and chain-select.js
    // auto-selects the (only) diagram on page load via a microtask
    // REGARDLESS of which tab is active. When "Documentação" is the one
    // shown first, `render()` runs while this whole root sits under a
    // `hidden` (display:none) tab panel, so every node measures 0×0 and that
    // measurement is never retaken — anchorPoint()/nodeAtPoint() then divide
    // every side (`node.w * fx`, `node.h * fy`) down to 0, so EVERY arrow
    // renders at each node's raw top-left corner instead of its real side,
    // and dragging an edge's handle can never detect hovering a block (its
    // w×h hit-box is degenerate). Worse, when there's no saved `viz_layout`
    // yet, `layoutDefault()` also ran on that same stale 0×0 — every block a
    // flat `LEVEL_GAP` (90px) apart from its neighbor's LEFT edge, regardless
    // of the real (nonzero) width it turns out to have — so once the tab
    // becomes visible and blocks paint at their real size, adjacent ones
    // visibly overlap (confirmed with a scripted 2-node chain: the second
    // block rendered fully inside the first one's box). A ResizeObserver on
    // the viewport reliably fires the moment the tab panel is unhidden
    // (content box goes from 0×0 to its real size, unlike `window`'s
    // 'resize', which never fires for a display:none→block toggle) —
    // re-measure then, redo `layoutDefault()` too (only when no saved layout
    // owns the positions — `usedCustomLayout`, set by the `render()` that
    // just ran on the stale zero) so the overlap is fixed instead of just the
    // anchor math, and redraw/refit so a stale zero is never load-bearing again.
    const viewportResizeObserver = new ResizeObserver((entries) => {
        const entry = entries[entries.length - 1]
        if (!entry || entry.contentRect.width === 0 || entry.contentRect.height === 0 || !nodes.length) return
        const wasZeroSized = nodes.every((n) => n.w === 0 && n.h === 0)
        nodes.forEach((n) => {
            n.w = n.el.offsetWidth
            n.h = n.el.offsetHeight
        })
        if (wasZeroSized) {
            if (!usedCustomLayout) layoutDefault()
            nodes.forEach((n) => {
                n.el.style.left = n.x + 'px'
                n.el.style.top = n.y + 'px'
            })
        }
        draw()
        if (wasZeroSized) fit()
    })
    viewportResizeObserver.observe(viewport)

    // Esc closes a selected lane's toolbar, closes the comment sidebar, or —
    // as a fallback — closes any other popover still open. `selectNode(null)`
    // is the same call a pointerdown on empty canvas already makes, and it
    // internally closes the title editor, the protocol editor, the "Adicionar
    // bloco" panel and the selected block's toolbar, so Esc closes everything
    // clicking away already closed.
    window.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return
        if (presenting) { exitPresentation(); return }
        if (selectedLane !== null) { closeLaneToolbar(); return }
        if (isSidebarOpen()) { closeComment(); return }
        selectNode(null)
    })

    // Ctrl+V (or Cmd+V) pastes an image straight onto the canvas — it becomes
    // an `image` block like any other (ports, arrows, comments), and it is the
    // only way to create one (see `ChainNodeKind::pickable()`). `paste` is a
    // document event (the canvas itself is not a text field), so this only
    // reacts when no VISIBLE text field has focus: `offsetParent`, rather than
    // the tag alone, rules out an input from a panel that has just closed
    // (`display:none`) while still being `document.activeElement`, which would
    // otherwise swallow the Ctrl+V as if an edit were still open.
    document.addEventListener('paste', (e) => {
        if (!editable || !graphRef?.imageAddUrl) return
        const active = document.activeElement
        const typing = active && (active.tagName === 'TEXTAREA' || active.tagName === 'INPUT' || active.isContentEditable) && active.offsetParent !== null
        if (typing) return

        const items = e.clipboardData?.items
        if (!items) return
        const imageItem = Array.from(items).find((item) => item.kind === 'file' && item.type.startsWith('image/'))
        if (!imageItem) return

        e.preventDefault()
        const file = imageItem.getAsFile()
        if (file) handlePasteImage(file)
    })

    // One at a time — pasting again before the first upload finishes is
    // dropped silently (see `pastingImage`) rather than firing two concurrent
    // POSTs whose answers would come back in an unpredictable order.
    async function handlePasteImage(file) {
        if (pastingImage) return
        pastingImage = true
        try {
            const formData = new FormData()
            formData.append('image', file)
            const res = await fetch(graphRef.imageAddUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: formData,
            })
            const data = await res.json().catch(() => null)
            if (!res.ok) throw new Error(data?.message || 'Não foi possível colar a imagem.')

            // Born near where the person is looking (the pointer's last point
            // over the canvas), as a block dropped in empty space would be when
            // an arrow is dragged there — without it, this falls back to
            // `appendNode()`'s default, to the right of the last block.
            const pos = (lastPointerX !== null && lastPointerY !== null) ? screenToWorld(lastPointerX, lastPointerY) : null
            appendNode(data.node, pos)
            patchRowGraphAppend(slug, data.node, data.summary)
            window.Toast?.show?.(data.message || 'Imagem adicionada.')
        } catch (err) {
            window.Toast?.show?.(err.message || 'Não foi possível colar a imagem.', 'error')
        } finally {
            pastingImage = false
        }
    }

    root.__akVizRender = render
    setDirty(false)
    applyView()
}
