/*------------------------------------------------
    UML diagrams written into a documentation page

    `{% uml type="…" title="…" %}{JSON}{% enduml %}` is validated by
    App\Support\Documentation\UmlDiagram and emitted by GitbookRenderer as a
    figure carrying the diagram as JSON (`data-ak-uml`), plus its text. This
    module draws the picture over it: the AUTHOR decided what is in the diagram
    and, for a structural one, the grid cell of every element; this file
    decides every shape, arrowhead, dash and label, in UML notation, and
    nothing in it can be moved by the reader.

    Two layouts:
    - SEQUENCE — participants are columns in the order given, messages are
      rows in the order given. Column spacing grows until every message label
      fits between its two lifelines; activations are derived from sync calls
      and their returns; fragments (alt/opt/loop/par/break/critical) frame the
      lifelines their messages touch.
    - GRID — every other type. Each element sits in its `at: [col, row]`; a
      column is as wide as its widest element and a row as tall as its
      tallest. Relations are straight lines clipped at each shape's outline,
      with the UML end (triangle, diamond, open arrow) at the right end.

    `renderUml(diagram, measure)` is pure — it returns the SVG markup and its
    natural size — so the editor's preview, the reader and a node script can
    all call it. The figure gets one button: "Tela cheia".
--------------------------------------------------*/

const FONT = {size: 12.5, weight: 400}
const BOLD = {size: 13, weight: 600}
const SMALL = {size: 11.5, weight: 400}
const LINE = 16

const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
const r1 = (v) => Math.round(v * 10) / 10

/* ------------------------------------------------------------- measure -- */

let canvas = null

/** Text width in px, measured with the page's font where there is a DOM. */
export function measurer(fontFamily) {
    if (typeof document === 'undefined') {
        // A rough average for Inter — only scripts outside a browser use it.
        return (text, spec) => String(text).length * spec.size * (spec.weight >= 600 ? 0.6 : 0.55)
    }

    canvas ??= document.createElement('canvas')
    const ctx = canvas.getContext('2d')

    return (text, spec) => {
        ctx.font = `${spec.weight} ${spec.size}px ${fontFamily}`

        return ctx.measureText(String(text)).width
    }
}

/** Greedy word wrap to `width`. */
function wrap(text, spec, width, measure) {
    const words = String(text || '').split(/\s+/).filter(Boolean)
    const lines = []
    let line = ''

    for (const word of words) {
        const next = line ? `${line} ${word}` : word
        if (line && measure(next, spec) > width) {
            lines.push(line)
            line = word
        } else {
            line = next
        }
    }
    if (line) lines.push(line)

    return lines.length ? lines : ['']
}

/* ---------------------------------------------------------- primitives -- */

const STYLE = `<style>
.uml-line{fill:none;stroke:#3f3f46;stroke-width:1.2}
.uml-dash{stroke-dasharray:6 4}
.uml-life{fill:none;stroke:#71717a;stroke-width:1;stroke-dasharray:5 4}
.uml-box{fill:url(#uml-fill);stroke:#3f3f46;stroke-width:1.2}
.uml-head{fill:url(#uml-head);stroke:#3f3f46;stroke-width:1.2}
.uml-plain{fill:#ffffff;stroke:#3f3f46;stroke-width:1.2}
.uml-bar{fill:#f4f4f5;stroke:#3f3f46;stroke-width:1}
.uml-ink{fill:#27272a;stroke:#27272a}
.uml-note{fill:url(#uml-note);stroke:#7aa2d6;stroke-width:1.1}
.uml-frame{fill:none;stroke:#52525b;stroke-width:1.1}
.uml-tag{fill:#f4f4f5;stroke:#52525b;stroke-width:1.1}
.uml-group{fill:#fafafa;fill-opacity:.55;stroke:#71717a;stroke-width:1.1}
.uml-t{fill:#18181b;font-size:${FONT.size}px}
.uml-b{fill:#18181b;font-size:${BOLD.size}px;font-weight:600}
.uml-s{fill:#3f3f46;font-size:${SMALL.size}px}
.uml-halo{paint-order:stroke;stroke:#ffffff;stroke-width:4px;stroke-linejoin:round}
.uml-i{font-style:italic}
.uml-u{text-decoration:underline}
</style>
<defs>
<linearGradient id="uml-fill" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffffff"/><stop offset="1" stop-color="#ececef"/></linearGradient>
<linearGradient id="uml-head" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#f4f4f5"/><stop offset="1" stop-color="#d9d9de"/></linearGradient>
<linearGradient id="uml-note" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffffff"/><stop offset="1" stop-color="#e4f0fd"/></linearGradient>
</defs>`

function text(x, y, value, cls = 'uml-t', anchor = 'middle', extra = '') {
    return `<text x="${r1(x)}" y="${r1(y)}" text-anchor="${anchor}" dominant-baseline="middle" class="${cls}"${extra}>${esc(value)}</text>`
}

function line(x1, y1, x2, y2, cls = 'uml-line') {
    return `<path d="M${r1(x1)} ${r1(y1)}L${r1(x2)} ${r1(y2)}" class="${cls}"/>`
}

/** An arrow end at (x, y) pointing along the unit vector (ux, uy). */
function arrowEnd(kind, x, y, ux, uy) {
    const px = -uy
    const py = ux
    const at = (along, across) => `${r1(x - ux * along + px * across)} ${r1(y - uy * along + py * across)}`

    switch (kind) {
        case 'filled':
            return `<path d="M${at(0, 0)}L${at(11, 4.5)}L${at(11, -4.5)}Z" class="uml-ink"/>`
        case 'open':
            return `<path d="M${at(11, 5.5)}L${at(0, 0)}L${at(11, -5.5)}" class="uml-line"/>`
        case 'triangle':
            return `<path d="M${at(0, 0)}L${at(14, 7.5)}L${at(14, -7.5)}Z" class="uml-plain"/>`
        case 'diamond':
        case 'hollow-diamond':
            return `<path d="M${at(0, 0)}L${at(9, 5)}L${at(18, 0)}L${at(9, -5)}Z" class="${kind === 'diamond' ? 'uml-ink' : 'uml-plain'}"/>`
        case 'dot':
            return `<circle cx="${r1(x)}" cy="${r1(y)}" r="5" class="uml-ink"/>`
        default:
            return ''
    }
}

/** A note: a rectangle with its top-right corner folded. */
function noteShape(x, y, w, h) {
    const f = 10

    return `<path d="M${r1(x)} ${r1(y)}H${r1(x + w - f)}L${r1(x + w)} ${r1(y + f)}V${r1(y + h)}H${r1(x)}Z" class="uml-note"/>`
        + `<path d="M${r1(x + w - f)} ${r1(y)}V${r1(y + f)}H${r1(x + w)}" class="uml-note" fill="none"/>`
}

/** The pentagon tag in the top-left corner of a frame ("alt", "ref", "loop"…). */
function frameTag(x, y, label, measure) {
    const w = measure(label, BOLD) + 18

    return {
        width: w,
        svg: `<path d="M${r1(x)} ${r1(y)}H${r1(x + w)}V${r1(y + 13)}L${r1(x + w - 7)} ${r1(y + 20)}H${r1(x)}Z" class="uml-tag"/>`
            + text(x + 7, y + 10.5, label, 'uml-b', 'start'),
    }
}

function stickFigure(cx, top, h = 40) {
    const head = h * 0.18
    const neck = top + head * 2
    const hip = top + h * 0.62

    return `<circle cx="${r1(cx)}" cy="${r1(top + head)}" r="${r1(head)}" class="uml-plain"/>`
        + `<path d="M${r1(cx)} ${r1(neck)}V${r1(hip)}M${r1(cx - h * 0.3)} ${r1(neck + h * 0.12)}H${r1(cx + h * 0.3)}`
        + `M${r1(cx - h * 0.25)} ${r1(top + h)}L${r1(cx)} ${r1(hip)}L${r1(cx + h * 0.25)} ${r1(top + h)}" class="uml-line"/>`
}

function cylinder(x, y, w, h) {
    const ry = Math.min(8, h / 6)

    return `<path d="M${r1(x)} ${r1(y + ry)}V${r1(y + h - ry)}A${r1(w / 2)} ${r1(ry)} 0 0 0 ${r1(x + w)} ${r1(y + h - ry)}V${r1(y + ry)}`
        + `A${r1(w / 2)} ${r1(ry)} 0 0 0 ${r1(x)} ${r1(y + ry)}Z" class="uml-box"/>`
        + `<path d="M${r1(x)} ${r1(y + ry)}A${r1(w / 2)} ${r1(ry)} 0 0 0 ${r1(x + w)} ${r1(y + ry)}" class="uml-line"/>`
}

/** The component icon: the two small tabs on a component's left edge. */
function componentTabs(x, y, h) {
    return `<rect x="${r1(x - 6)}" y="${r1(y + h * 0.22)}" width="12" height="7" class="uml-plain"/>`
        + `<rect x="${r1(x - 6)}" y="${r1(y + h * 0.22 + 12)}" width="12" height="7" class="uml-plain"/>`
}

/* ------------------------------------------------------------ sequence -- */

const SEQ = {
    margin: 20,
    headH: 40,
    gapMin: 36,
    row: 36,
    selfRow: 46,
    fragHead: 30,
    fragEnd: 14,
    operand: 30,
    bar: 10,
}

/** Participant head size. */
function headSize(p, measure) {
    const label = measure(p.label, BOLD)

    switch (p.kind) {
        case 'actor':
        case 'boundary':
        case 'control':
        case 'entity':
            return {w: Math.max(label + 12, 70), h: 40 + LINE + 4, labelBelow: true}
        case 'database':
            return {w: Math.max(label + 26, 80), h: SEQ.headH + 8}
        default:
            return {w: Math.max(label + 26, 80), h: SEQ.headH}
    }
}

function drawHead(p, cx, top, size) {
    const {w, h} = size
    const icon = top + 2

    switch (p.kind) {
        case 'actor':
            return stickFigure(cx, icon, 36) + text(cx, top + h - 8, p.label, 'uml-b')
        case 'boundary':
            return `<circle cx="${r1(cx + 6)}" cy="${r1(icon + 18)}" r="15" class="uml-box"/>`
                + line(cx - 17, icon + 3, cx - 17, icon + 33) + line(cx - 17, icon + 18, cx - 9, icon + 18)
                + text(cx, top + h - 8, p.label, 'uml-b')
        case 'control':
            return `<circle cx="${r1(cx)}" cy="${r1(icon + 19)}" r="15" class="uml-box"/>`
                + `<path d="M${r1(cx + 4)} ${r1(icon - 1)}L${r1(cx - 3)} ${r1(icon + 4)}L${r1(cx + 4)} ${r1(icon + 9)}" class="uml-line"/>`
                + text(cx, top + h - 8, p.label, 'uml-b')
        case 'entity':
            return `<circle cx="${r1(cx)}" cy="${r1(icon + 17)}" r="15" class="uml-box"/>`
                + line(cx - 17, icon + 33, cx + 17, icon + 33)
                + text(cx, top + h - 8, p.label, 'uml-b')
        case 'database':
            return cylinder(cx - w / 2, top, w, h) + text(cx, top + h / 2 + 4, p.label, 'uml-b')
        case 'component':
            return `<rect x="${r1(cx - w / 2)}" y="${r1(top)}" width="${r1(w)}" height="${h}" class="uml-box"/>`
                + componentTabs(cx - w / 2, top, h) + text(cx + 4, top + h / 2, p.label, 'uml-b')
        default:
            return `<rect x="${r1(cx - w / 2)}" y="${r1(top)}" width="${r1(w)}" height="${h}" class="uml-box"/>`
                + text(cx, top + h / 2, p.label, 'uml-b')
    }
}

/** Every participant a list of items touches, fragments included. */
function touched(items, into = new Set()) {
    for (const item of items || []) {
        if (item.fragment) item.operands.forEach((o) => touched(o.messages, into))
        else if (item.over) item.over.forEach((id) => into.add(id))
        else {
            if (item.from) into.add(item.from)
            if (item.to) into.add(item.to)
        }
    }

    return into
}

function renderSequence(d, measure) {
    const parts = d.participants || []
    const index = new Map(parts.map((p, i) => [p.id, i]))
    const sizes = parts.map((p) => headSize(p, measure))
    const n = parts.length

    // Number the messages in reading order when asked to.
    let counter = 0
    const label = (m) => {
        if (m.kind === 'create' && !m.label) return '«create»'
        if (m.kind === 'destroy' && !m.label) return '«destroy»'
        const base = m.label || ''
        if (!d.numbered) return base
        counter++

        return base ? `${counter}: ${base}` : `${counter}`
    }

    // Collect messages flat (with their label) to size the columns.
    const flat = []
    const collect = (items) => (items || []).forEach((item) => {
        if (item.fragment) item.operands.forEach((o) => collect(o.messages))
        else if (!item.over && item.divider === undefined) {
            item._label = label(item)
            flat.push(item)
        }
    })
    collect(d.messages)

    // Column spacing: centre-to-centre gaps that fit every label.
    const gaps = Array.from({length: Math.max(0, n - 1)}, (_, i) => sizes[i].w / 2 + sizes[i + 1].w / 2 + SEQ.gapMin)
    const spans = flat
        .filter((m) => m.from && m.to && m.from !== m.to)
        .map((m) => {
            const a = index.get(m.from)
            const b = index.get(m.to)

            return {lo: Math.min(a, b), hi: Math.max(a, b), need: measure(m._label, FONT) + 34}
        })
        .sort((x, y) => (x.hi - x.lo) - (y.hi - y.lo))
    for (const {lo, hi, need} of spans) {
        let have = 0
        for (let i = lo; i < hi; i++) have += gaps[i]
        if (have < need) for (let i = lo; i < hi; i++) gaps[i] += (need - have) / (hi - lo)
    }
    // A self message's label hangs to the right of its lifeline.
    let rightRoom = 0
    flat.filter((m) => m.from && m.from === m.to).forEach((m) => {
        const i = index.get(m.from)
        const need = measure(m._label, FONT) + 56
        if (i < n - 1) gaps[i] = Math.max(gaps[i], need + sizes[i + 1].w / 2 * 0)
        else rightRoom = Math.max(rightRoom, need - sizes[i].w / 2)
    })
    // Notes over one participant: their width shares the column's room.
    const hasFound = flat.some((m) => !m.from)
    const hasLost = flat.some((m) => !m.to)

    const left = SEQ.margin + (hasFound ? 90 : 0)
    const xs = []
    let x = left + sizes[0].w / 2
    for (let i = 0; i < n; i++) {
        xs.push(x)
        x += gaps[i] ?? 0
    }
    let width = xs[n - 1] + sizes[n - 1].w / 2 + SEQ.margin + Math.max(rightRoom, 0) + (hasLost ? 90 : 0)

    const created = new Map()
    flat.filter((m) => m.kind === 'create').forEach((m) => created.set(m.to, true))
    const headTop = SEQ.margin
    const headBottom = headTop + Math.max(...parts.map((p, i) => (created.has(p.id) ? 0 : sizes[i].h)))

    const back = []   // fragments, notes: behind the lifelines' activations
    const front = []  // messages, heads drawn late
    const bars = []
    const lifeEnd = new Map()
    const lifeStart = new Map(parts.map((p, i) => [p.id, headTop + sizes[i].h]))
    const stacks = new Map(parts.map((p) => [p.id, []]))
    // Where each lifeline was last used: an activation nobody returns from
    // ends there, rather than running to the bottom of the diagram.
    const lastSeen = new Map()
    // How far notes, frames and found/lost messages reach past the lifelines:
    // the drawing is shifted and widened to hold them, never clipped.
    let lo = Infinity
    let hi = -Infinity
    const reach = (a, b) => { lo = Math.min(lo, a, b); hi = Math.max(hi, a, b) }
    const seen = (id, at) => id && lastSeen.set(id, at)
    let y = headBottom + 24

    const depthOf = (id) => stacks.get(id)?.length || 0
    const open = (id, at) => stacks.get(id).push(at)
    const close = (id, at) => {
        const start = stacks.get(id).pop()
        if (start !== undefined) bars.push({id, y1: start, y2: Math.max(at, start + 12), depth: stacks.get(id).length})
    }
    // Where a message leaves or reaches a lifeline: the edge of the bar.
    const edge = (id, toward) => xs[index.get(id)] + Math.sign(toward) * (depthOf(id) ? SEQ.bar / 2 + (depthOf(id) - 1) * 5 : 0)

    const drawMessage = (m) => {
        const text1 = m._label
        seen(m.from, y)
        seen(m.to, y)
        if (m.from && m.from === m.to) {
            const cx = xs[index.get(m.from)]
            const x0 = cx + (depthOf(m.from) ? SEQ.bar / 2 : 0)
            const y0 = y - 8
            if (m.kind === 'sync') open(m.from, y0 + 16)
            const x1 = cx + (depthOf(m.from) ? SEQ.bar / 2 + 5 * (depthOf(m.from) - 1) : 0)
            front.push(`<path d="M${r1(x0)} ${r1(y0)}H${r1(x0 + 34)}V${r1(y0 + 20)}H${r1(x1 + 1)}" class="uml-line${m.kind === 'return' ? ' uml-dash' : ''}"/>`
                + arrowEnd(m.kind === 'sync' ? 'filled' : 'open', x1, y0 + 20, -1, 0)
                + text(x0 + 6, y0 - 9, text1, 'uml-t uml-halo', 'start'))
            if (m.kind === 'sync') close(m.from, y0 + 30)
            y += SEQ.selfRow

            return
        }

        if (m.kind === 'create') {
            const i = index.get(m.to)
            const top = sizes[i].labelBelow ? y - 20 : y - sizes[i].h / 2
            lifeStart.set(m.to, top + sizes[i].h)
            front.push(drawHead(parts[i], xs[i], top, sizes[i]))
        }

        const fromX = m.from ? xs[index.get(m.from)] : null
        const toX = m.to ? xs[index.get(m.to)] : null
        const dir = fromX !== null && toX !== null ? Math.sign(toX - fromX) : (fromX === null ? 1 : 1)
        if (m.kind === 'sync' && m.from && !depthOf(m.from) && !['actor'].includes(parts[index.get(m.from)].kind)) open(m.from, y - 4)

        let x0 = m.from ? edge(m.from, dir) : toX - 90
        let x1
        if (!m.to) x1 = fromX + 90
        else if (m.kind === 'create') x1 = toX - dir * sizes[index.get(m.to)].w / 2
        else x1 = toX - dir * (depthOf(m.to) + (m.kind === 'sync' ? 1 : 0) ? SEQ.bar / 2 : 0)

        if (m.kind === 'return' && m.from) {
            close(m.from, y)
            x0 = xs[index.get(m.from)] + (depthOf(m.from) ? -dir * 0 + dir * SEQ.bar / 2 : 0) * 0 + dir * SEQ.bar / 2 * (depthOf(m.from) ? 1 : 0)
        }

        const dashed = m.kind === 'return' || m.kind === 'create'
        const head = m.kind === 'sync' ? 'filled' : 'open'
        reach(x0 - 6, x1 + 6)
        reach((x0 + x1) / 2 - measure(text1, FONT) / 2, (x0 + x1) / 2 + measure(text1, FONT) / 2)
        front.push(line(x0, y, x1, y, `uml-line${dashed ? ' uml-dash' : ''}`)
            + arrowEnd(head, x1, y, Math.sign(x1 - x0) || 1, 0)
            + (m.from ? '' : arrowEnd('dot', x0, y, 1, 0))
            + (m.to ? '' : arrowEnd('dot', x1 + 5 * Math.sign(x1 - x0), y, 1, 0))
            + text((x0 + x1) / 2, y - 9, text1, 'uml-t uml-halo'))

        if (m.kind === 'sync' && m.to) open(m.to, y)
        if (m.kind === 'destroy' && m.to) {
            const cx = xs[index.get(m.to)]
            while (depthOf(m.to)) close(m.to, y)
            lifeEnd.set(m.to, y)
            front.push(`<path d="M${r1(cx - 9)} ${r1(y - 9)}l18 18M${r1(cx + 9)} ${r1(y - 9)}l-18 18" class="uml-line" stroke-width="2"/>`)
        }
        y += m.kind === 'create' ? Math.max(SEQ.row, sizes[index.get(m.to)].h + 12) : SEQ.row
    }

    const span = (ids, pad) => {
        const cols = [...ids].map((id) => index.get(id)).filter((i) => i !== undefined)
        const lo = cols.length ? Math.min(...cols) : 0
        const hi = cols.length ? Math.max(...cols) : n - 1

        return [xs[lo] - Math.max(sizes[lo].w / 2, 50) + 6 - pad, xs[hi] + Math.max(sizes[hi].w / 2, 50) - 6 + pad]
    }

    const walk = (items, depth) => {
        for (const item of items || []) {
            if (item.fragment) {
                const [fx1, fx2] = span(touched(item.operands.flatMap((o) => o.messages)), 14 - depth * 6)
                const top = y - 8
                reach(fx1, fx2)
                const tag = frameTag(fx1, top, item.fragment, measure)
                const pieces = []
                y += SEQ.fragHead
                const guards = []
                item.operands.forEach((operand, k) => {
                    if (k > 0) {
                        const sep = y - 14
                        pieces.push(line(fx1, sep, fx2, sep, 'uml-frame uml-dash'))
                        if (operand.label) guards.push(text(fx1 + 10, sep + 12, operand.label, 'uml-t uml-i uml-halo', 'start'))
                        y += SEQ.operand
                    } else if (operand.label) {
                        guards.push(text(fx1 + tag.width + 8, top + 10.5, operand.label, 'uml-t uml-i uml-halo', 'start'))
                    }
                    walk(operand.messages, depth + 1)
                })
                const end = y - 12
                back.push(`<rect x="${r1(fx1)}" y="${r1(top)}" width="${r1(fx2 - fx1)}" height="${r1(end - top)}" class="uml-frame"/>` + tag.svg + pieces.join(''))
                front.push(...guards)
                y = end + 22
            } else if (item.ref !== undefined) {
                const [fx1, fx2] = span(new Set(item.over), 8)
                reach(fx1, fx2)
                const tag = frameTag(fx1, y - 8, 'ref', measure)
                item.over.forEach((id) => seen(id, y + 36))
                front.push(`<rect x="${r1(fx1)}" y="${r1(y - 8)}" width="${r1(fx2 - fx1)}" height="44" class="uml-plain"/>` + tag.svg
                    + text((fx1 + fx2) / 2 + tag.width / 2, y + 20, item.ref, 'uml-b'))
                y += 52
            } else if (item.note !== undefined) {
                const ids = item.over
                const [sx1, sx2] = ids.length > 1 ? span(new Set(ids), 0) : [xs[index.get(ids[0])] - 80, xs[index.get(ids[0])] + 80]
                reach(sx1, sx2)
                const lines = wrap(item.note, FONT, sx2 - sx1 - 22, measure)
                const h = lines.length * LINE + 14
                ids.forEach((id) => seen(id, y + h))
                front.push(noteShape(sx1, y - 8, sx2 - sx1, h)
                    + lines.map((l, k) => text(sx1 + 9, y + 7 + k * LINE, l, 'uml-t', 'start')).join(''))
                y += h + 10
            } else if (item.divider !== undefined) {
                front.push({divider: item.divider, y})
                y += 30
            } else {
                drawMessage(item)
            }
        }
    }
    walk(d.messages, 0)
    for (const p of parts) while (depthOf(p.id)) close(p.id, (lastSeen.get(p.id) ?? y) + 14)

    const bottom = y + 6
    const svg = []
    const shift = Number.isFinite(lo) ? Math.max(0, SEQ.margin - lo) : 0
    width = Math.max(width, Number.isFinite(hi) ? hi + SEQ.margin : 0)

    // Lifelines, then the heads that start at the top.
    parts.forEach((p, i) => {
        svg.push(line(xs[i], lifeStart.get(p.id), xs[i], lifeEnd.get(p.id) ?? bottom, 'uml-life'))
        if (!created.has(p.id)) svg.push(drawHead(p, xs[i], headTop, sizes[i]))
    })
    svg.push(...back)
    bars.forEach((b) => {
        const cx = xs[index.get(b.id)] + b.depth * 5
        svg.push(`<rect x="${r1(cx - SEQ.bar / 2)}" y="${r1(b.y1)}" width="${SEQ.bar}" height="${r1(b.y2 - b.y1)}" class="uml-bar"/>`)
    })
    front.forEach((piece) => {
        if (typeof piece === 'string') svg.push(piece)
        else {
            const w = measure(piece.divider, FONT) + 20
            const mid = (width - shift) / 2
            svg.push(line(SEQ.margin - shift, piece.y, width - SEQ.margin, piece.y, 'uml-frame uml-dash')
                + `<rect x="${r1(mid - w / 2)}" y="${r1(piece.y - 10)}" width="${r1(w)}" height="20" rx="10" class="uml-plain"/>`
                + text(mid, piece.y, piece.divider, 'uml-t'))
        }
    })

    return {
        width: Math.ceil(width + shift),
        height: Math.ceil(bottom + SEQ.margin),
        body: shift ? `<g transform="translate(${r1(shift)} 0)">${svg.join('')}</g>` : svg.join(''),
    }
}

/* ---------------------------------------------------------------- grid -- */

const GRID = {margin: 24, gapX: 96, gapY: 84, pad: 10, groupPad: 18, groupStep: 14, groupLabel: 22}

const STEREOTYPE = {interface: 'interface', enum: 'enumeration', artifact: 'artifact'}

const COMPARTMENT_KINDS = ['class', 'abstract', 'interface', 'enum', 'object']

/** An element's box, its pieces of text, and how to clip a line at its outline. */
function sizeNode(node, measure) {
    const stereo = node.stereotype || STEREOTYPE[node.kind] || ''
    const name = node.name || ''

    if (COMPARTMENT_KINDS.includes(node.kind)) {
        const attrs = node.attributes || []
        const ops = node.operations || []
        const widths = [measure(name, BOLD), stereo ? measure(`«${stereo}»`, FONT) : 0, ...attrs.map((a) => measure(a, FONT)), ...ops.map((o) => measure(o, FONT))]
        const w = Math.max(110, Math.max(...widths) + 2 * GRID.pad + 4)
        const head = (stereo ? LINE : 0) + 26
        const compartments = node.kind === 'enum' || node.kind === 'object'
            ? (attrs.length ? [attrs] : [])
            : (attrs.length || ops.length || node.kind === 'interface' ? [attrs, ops] : [])
        const h = head + compartments.reduce((sum, c) => sum + Math.max(12, c.length * LINE + 8), 0)

        return {w, h, shape: 'rect', head, compartments, stereo}
    }

    const wrapped = (max, spec = BOLD) => wrap(name, spec, max, measure)

    switch (node.kind) {
        case 'actor': {
            const w = Math.max(56, measure(name, BOLD) + 8)

            return {w, h: 46 + LINE + 2, shape: 'rect'}
        }
        case 'usecase': {
            const lines = wrapped(150)
            const tw = Math.max(...lines.map((l) => measure(l, BOLD)))

            return {w: tw + 48, h: Math.max(46, lines.length * LINE + 24), shape: 'ellipse', lines}
        }
        case 'database': {
            const lines = wrapped(150)

            return {w: Math.max(86, Math.max(...lines.map((l) => measure(l, BOLD))) + 28), h: 46 + lines.length * LINE, shape: 'rect', lines, stereo}
        }
        case 'lollipop':
            return {w: Math.max(24, measure(name, FONT) + 6), h: 22 + LINE + 2, shape: 'circle', r: 10}
        case 'note': {
            const lines = wrap(node.text || name, FONT, 190, measure)

            return {w: Math.max(...lines.map((l) => measure(l, FONT))) + 30, h: lines.length * LINE + 18, shape: 'rect', lines}
        }
        case 'decision':
            return {w: 40, h: 40, shape: 'diamond'}
        case 'initial':
            return {w: 20, h: 20, shape: 'circle', r: 10}
        case 'final':
            return {w: 24, h: 24, shape: 'circle', r: 12}
        case 'fork':
            return {w: Math.max(80, measure(name, FONT) + 20), h: 7, shape: 'rect'}
        default: {
            // component, node, artifact, package, action, state
            const lines = wrapped(190)
            const tw = Math.max(...lines.map((l) => measure(l, BOLD)), stereo ? measure(`«${stereo}»`, FONT) : 0)
            const extra = node.kind === 'component' ? 34 : node.kind === 'artifact' ? 26 : node.kind === 'node' ? 12 : 0
            const attrs = node.kind === 'state' ? node.attributes || [] : []
            const aw = Math.max(0, ...attrs.map((a) => measure(a, FONT)))
            const top = node.kind === 'package' ? 14 : 0
            const h = top + Math.max(node.kind === 'action' ? 36 : 46, lines.length * LINE + (stereo ? LINE : 0) + 22)
                + (attrs.length ? attrs.length * LINE + 10 : 0)

            return {w: Math.max(node.kind === 'action' ? 70 : 100, Math.max(tw, aw) + 28 + extra), h, shape: 'rect', lines, stereo, attrs, top}
        }
    }
}

function drawNode(node, box, measure) {
    const {x, y, w, h} = box
    const cx = x + w / 2
    const cy = y + h / 2
    const s = box.size
    const out = []
    const stereoLine = (sy) => (s.stereo ? text(cx, sy, `«${s.stereo}»`, 'uml-t') : '')
    const nameLines = (lines, startY, cls = 'uml-b') => lines.map((l, k) => text(cx, startY + k * LINE, l, cls)).join('')

    if (COMPARTMENT_KINDS.includes(node.kind)) {
        out.push(`<rect x="${r1(x)}" y="${r1(y)}" width="${r1(w)}" height="${r1(s.head)}" class="uml-head"/>`)
        out.push(`<rect x="${r1(x)}" y="${r1(y)}" width="${r1(w)}" height="${r1(h)}" class="uml-line"/>`)
        out.push(stereoLine(y + 12))
        const nameCls = `uml-b${node.kind === 'abstract' ? ' uml-i' : ''}${node.kind === 'object' ? ' uml-u' : ''}`
        out.push(text(cx, y + s.head - 13, node.name, nameCls))
        let cy2 = y + s.head
        s.compartments.forEach((lines) => {
            out.push(line(x, cy2, x + w, cy2))
            lines.forEach((l, k) => out.push(text(x + GRID.pad, cy2 + 12 + k * LINE, l, 'uml-t', 'start')))
            cy2 += Math.max(12, lines.length * LINE + 8)
        })

        return out.join('')
    }

    switch (node.kind) {
        case 'actor':
            return stickFigure(cx, y, 42) + text(cx, y + h - 7, node.name, 'uml-b')
        case 'usecase':
            return `<ellipse cx="${r1(cx)}" cy="${r1(cy)}" rx="${r1(w / 2)}" ry="${r1(h / 2)}" class="uml-box"/>`
                + nameLines(s.lines, cy - ((s.lines.length - 1) * LINE) / 2)
        case 'database':
            return cylinder(x, y, w, h) + stereoLine(y + 24) + nameLines(s.lines, cy + 6 + (s.stereo ? 6 : 0) - ((s.lines.length - 1) * LINE) / 2)
        case 'lollipop':
            return `<circle cx="${r1(cx)}" cy="${r1(y + 10)}" r="10" class="uml-plain"/>` + text(cx, y + h - 7, node.name, 'uml-t')
        case 'note':
            return noteShape(x, y, w, h) + s.lines.map((l, k) => text(x + 10, y + 16 + k * LINE, l, 'uml-t', 'start')).join('')
        case 'decision':
            return `<path d="M${r1(cx)} ${r1(y)}L${r1(x + w)} ${r1(cy)}L${r1(cx)} ${r1(y + h)}L${r1(x)} ${r1(cy)}Z" class="uml-box"/>`
                + (node.name ? text(x + w + 6, y - 2, node.name, 'uml-t uml-halo', 'start') : '')
        case 'initial':
            return `<circle cx="${r1(cx)}" cy="${r1(cy)}" r="10" class="uml-ink"/>`
        case 'final':
            return `<circle cx="${r1(cx)}" cy="${r1(cy)}" r="12" class="uml-plain"/><circle cx="${r1(cx)}" cy="${r1(cy)}" r="7.5" class="uml-ink"/>`
        case 'fork':
            return `<rect x="${r1(x)}" y="${r1(y)}" width="${r1(w)}" height="${h}" rx="2" class="uml-ink"/>`
                + (node.name ? text(x + w + 6, cy, node.name, 'uml-t', 'start') : '')
        default:
            break
    }

    // Boxes with a name: component, node, artifact, package, action, state.
    const top = s.top || 0
    const bodyY = y + top
    const bodyH = h - top
    if (node.kind === 'node') {
        out.push(`<path d="M${r1(x)} ${r1(y + 10)}L${r1(x + 10)} ${r1(y)}H${r1(x + w)}V${r1(y + h - 10)}L${r1(x + w - 10)} ${r1(y + h)}" class="uml-head"/>`)
        out.push(`<rect x="${r1(x)}" y="${r1(y + 10)}" width="${r1(w - 10)}" height="${r1(h - 10)}" class="uml-box"/>`)
        out.push(line(x + w - 10, y + 10, x + w, y))
    } else if (node.kind === 'package') {
        out.push(`<rect x="${r1(x)}" y="${r1(y)}" width="${r1(Math.min(w * 0.45, 70))}" height="${top + 1}" class="uml-head"/>`)
        out.push(`<rect x="${r1(x)}" y="${r1(bodyY)}" width="${r1(w)}" height="${r1(bodyH)}" class="uml-box"/>`)
    } else {
        const rx = node.kind === 'action' ? 14 : node.kind === 'state' ? 10 : 0
        out.push(`<rect x="${r1(x)}" y="${r1(y)}" width="${r1(w)}" height="${r1(h)}" rx="${rx}" class="uml-box"/>`)
    }
    if (node.kind === 'component') {
        out.push(`<rect x="${r1(x + w - 22)}" y="${r1(y + 7)}" width="14" height="16" class="uml-plain"/>`
            + `<rect x="${r1(x + w - 25)}" y="${r1(y + 10)}" width="7" height="3.5" class="uml-plain"/>`
            + `<rect x="${r1(x + w - 25)}" y="${r1(y + 16)}" width="7" height="3.5" class="uml-plain"/>`)
    }
    if (node.kind === 'artifact') {
        out.push(`<path d="M${r1(x + w - 22)} ${r1(y + 6)}h9l5 5v13h-14Z" class="uml-plain"/>`)
    }

    const attrsH = s.attrs.length ? s.attrs.length * LINE + 10 : 0
    const textH = s.lines.length * LINE + (s.stereo ? LINE : 0)
    const areaTop = (node.kind === 'node' ? y + 10 : bodyY)
    const areaH = (node.kind === 'node' ? h - 10 : bodyH) - attrsH
    const startY = areaTop + (areaH - textH) / 2 + LINE / 2
    const shift = node.kind === 'node' ? -5 : 0
    if (s.stereo) out.push(text(cx + shift, startY, `«${s.stereo}»`, 'uml-t'))
    out.push(s.lines.map((l, k) => text(cx + shift, startY + (s.stereo ? LINE : 0) + k * LINE, l, 'uml-b')).join(''))
    if (s.attrs.length) {
        const ay = areaTop + areaH
        out.push(line(x, ay, x + w, ay))
        s.attrs.forEach((a, k) => out.push(text(x + 10, ay + 13 + k * LINE, a, 'uml-t', 'start')))
    }

    return out.join('')
}

/** Where the ray from a box's centre toward (tx, ty) leaves its outline. */
function clip(box, tx, ty) {
    const cx = box.x + box.w / 2
    const cy = box.y + box.h / 2
    const dx = tx - cx
    const dy = ty - cy
    if (!dx && !dy) return [cx, cy]
    const s = box.size

    let t
    if (s.shape === 'circle') {
        t = (s.r + 1) / Math.hypot(dx, dy)

        return [cx + dx * t, (box.node.kind === 'lollipop' ? box.y + 10 : cy) + dy * t]
    }
    if (s.shape === 'ellipse') {
        t = 1 / Math.sqrt((dx * dx) / ((box.w / 2) ** 2) + (dy * dy) / ((box.h / 2) ** 2))
    } else if (s.shape === 'diamond') {
        t = 1 / (Math.abs(dx) / (box.w / 2) + Math.abs(dy) / (box.h / 2))
    } else {
        t = Math.min(dx ? (box.w / 2) / Math.abs(dx) : Infinity, dy ? (box.h / 2) / Math.abs(dy) : Infinity)
    }

    return [cx + dx * t, cy + dy * t]
}

const EDGE_STYLE = {
    association: {dash: false, to: ''},
    directed: {dash: false, to: 'open'},
    inheritance: {dash: false, to: 'triangle'},
    realization: {dash: true, to: 'triangle'},
    dependency: {dash: true, to: 'open'},
    composition: {dash: false, to: '', from: 'diamond'},
    aggregation: {dash: false, to: '', from: 'hollow-diamond'},
    flow: {dash: false, to: 'open'},
    include: {dash: true, to: 'open', stereo: 'include'},
    extend: {dash: true, to: 'open', stereo: 'extend'},
    note: {dash: true, to: ''},
}

function renderGrid(d, measure) {
    const nodes = d.nodes || []
    const sizes = new Map(nodes.map((node) => [node.id, sizeNode(node, measure)]))
    const cols = Math.max(0, ...nodes.map((n) => n.at?.[0] ?? 0)) + 1
    const rows = Math.max(0, ...nodes.map((n) => n.at?.[1] ?? 0)) + 1
    const colW = Array(cols).fill(0)
    const rowH = Array(rows).fill(0)
    nodes.forEach((n) => {
        const [c, r] = n.at || [0, 0]
        colW[c] = Math.max(colW[c], sizes.get(n.id).w)
        rowH[r] = Math.max(rowH[r], sizes.get(n.id).h)
    })

    // How much room frames need around the grid: the deepest nesting.
    const groups = (d.groups || []).map((g) => ({...g, set: new Set(g.nodes)}))
    const inner = (g) => {
        const kids = groups.filter((o) => o !== g && o.set.size < g.set.size && [...o.set].every((id) => g.set.has(id)))

        return kids.length ? 1 + Math.max(...kids.map(inner)) : 0
    }
    groups.forEach((g) => (g.depth = inner(g)))
    const deepest = groups.length ? Math.max(...groups.map((g) => g.depth)) + 1 : 0
    const margin = GRID.margin + deepest * (GRID.groupPad + GRID.groupStep) + (deepest ? GRID.groupLabel : 0)

    const colX = []
    let acc = margin
    colW.forEach((w) => { colX.push(acc); acc += w + GRID.gapX })
    const rowY = []
    acc = margin
    rowH.forEach((h) => { rowY.push(acc); acc += h + GRID.gapY })

    const boxes = new Map(nodes.map((node) => {
        const s = sizes.get(node.id)
        const [c, r] = node.at || [0, 0]

        return [node.id, {node, size: s, w: s.w, h: s.h, x: colX[c] + (colW[c] - s.w) / 2, y: rowY[r] + (rowH[r] - s.h) / 2}]
    }))

    // The drawing's extent, grown by everything painted; the viewBox is
    // cropped to it afterwards, so frames and loops never cost dead margin.
    const svg = []
    let minX = Infinity
    let minY = Infinity
    let maxX = -Infinity
    let maxY = -Infinity
    const grow = (x, y) => {
        minX = Math.min(minX, x); minY = Math.min(minY, y)
        maxX = Math.max(maxX, x); maxY = Math.max(maxY, y)
    }
    boxes.forEach((b) => {
        grow(b.x, b.y)
        grow(b.x + b.w, b.y + b.h)
        if (b.node.name && ['decision', 'fork'].includes(b.node.kind)) grow(b.x + b.w + 10 + measure(b.node.name, FONT), b.y - 10)
    })

    // Frames, outermost first so an inner one paints over it.
    groups.sort((a, b) => b.depth - a.depth).forEach((g) => {
        const members = g.nodes.map((id) => boxes.get(id)).filter(Boolean)
        if (!members.length) return
        const pad = GRID.groupPad + g.depth * GRID.groupStep
        const x1 = Math.min(...members.map((b) => b.x)) - pad
        const x2 = Math.max(...members.map((b) => b.x + b.w)) + pad
        const y1 = Math.min(...members.map((b) => b.y)) - pad - GRID.groupLabel
        const y2 = Math.max(...members.map((b) => b.y + b.h)) + pad
        grow(x2 + (g.kind === 'node' ? 12 : 0), y2)
        grow(x1, y1 - (g.kind === 'package' ? 20 : 12))
        const w = x2 - x1
        const h = y2 - y1
        const label = g.label || ''
        if (g.kind === 'package') {
            const tw = Math.max(60, measure(label, BOLD) + 20)
            svg.push(`<rect x="${r1(x1)}" y="${r1(y1 - 20)}" width="${r1(tw)}" height="21" class="uml-group"/>`
                + `<rect x="${r1(x1)}" y="${r1(y1)}" width="${r1(w)}" height="${r1(h)}" class="uml-group"/>`
                + text(x1 + 10, y1 - 9.5, label, 'uml-b', 'start'))
        } else if (g.kind === 'node') {
            svg.push(`<path d="M${r1(x1)} ${r1(y1)}L${r1(x1 + 12)} ${r1(y1 - 12)}H${r1(x2 + 12)}V${r1(y2 - 12)}L${r1(x2)} ${r1(y2)}" class="uml-group"/>`
                + `<rect x="${r1(x1)}" y="${r1(y1)}" width="${r1(w)}" height="${r1(h)}" class="uml-group"/>`
                + line(x2, y1, x2 + 12, y1 - 12, 'uml-frame')
                + text(x1 + 10, y1 + 13, label, 'uml-b', 'start'))
        } else if (g.kind === 'frame') {
            const tag = frameTag(x1, y1, label || 'frame', measure)
            svg.push(`<rect x="${r1(x1)}" y="${r1(y1)}" width="${r1(w)}" height="${r1(h)}" class="uml-group"/>` + tag.svg)
        } else {
            svg.push(`<rect x="${r1(x1)}" y="${r1(y1)}" width="${r1(w)}" height="${r1(h)}" rx="4" class="uml-group"/>`
                + text(x1 + w / 2, y1 + 13, label, 'uml-b'))
        }
    })

    // Relations: straight, clipped at each outline; parallels fan out.
    const pairs = new Map()
    const edges = (d.edges || []).filter((e) => boxes.has(e.from) && boxes.has(e.to))
    edges.forEach((e) => {
        const key = [e.from, e.to].sort().join('|')
        pairs.set(key, (pairs.get(key) || 0) + 1)
    })
    const seen = new Map()
    const labels = []
    edges.forEach((e) => {
        const style = EDGE_STYLE[e.kind] || EDGE_STYLE.association
        const a = boxes.get(e.from)
        const b = boxes.get(e.to)
        const dash = style.dash ? ' uml-dash' : ''

        if (a === b) {
            // A self association: a loop off the top-right corner.
            const x = a.x + a.w
            const y = a.y
            svg.push(`<path d="M${r1(x - 24)} ${r1(y)}V${r1(y - 26)}H${r1(x + 26)}V${r1(y + 24)}H${r1(x)}" class="uml-line${dash}"/>`
                + arrowEnd(style.to, x, y + 24, -1, 0))
            if (e.label) labels.push(text(x + 30, y - 30, e.label, 'uml-t uml-halo', 'start'))
            if (e.from_label) labels.push(text(x + 32, y + 14, e.from_label, 'uml-s uml-halo', 'start'))
            if (e.to_label) labels.push(text(x - 30, y - 10, e.to_label, 'uml-s uml-halo', 'end'))
            grow(x + 32 + measure(e.label || '', FONT), y - 40)

            return
        }

        const key = [e.from, e.to].sort().join('|')
        const total = pairs.get(key)
        const k = seen.get(key) || 0
        seen.set(key, k + 1)
        const ac = [a.x + a.w / 2, a.y + a.h / 2]
        const bc = [b.x + b.w / 2, b.y + b.h / 2]
        const len = Math.hypot(bc[0] - ac[0], bc[1] - ac[1]) || 1
        const ux = (bc[0] - ac[0]) / len
        const uy = (bc[1] - ac[1]) / len
        const offset = (k - (total - 1) / 2) * 14
        const ox = -uy * offset
        const oy = ux * offset
        const [x0, y0] = clip(a, bc[0] + ox, bc[1] + oy).map((v, i) => v + (i ? oy : ox) * 0.999)
        const [x1, y1] = clip(b, ac[0] + ox, ac[1] + oy).map((v, i) => v + (i ? oy : ox) * 0.999)
        const lux = (x1 - x0) / (Math.hypot(x1 - x0, y1 - y0) || 1)
        const luy = (y1 - y0) / (Math.hypot(x1 - x0, y1 - y0) || 1)

        svg.push(line(x0, y0, x1, y1, `uml-line${dash}`)
            + arrowEnd(style.to, x1, y1, lux, luy)
            + (style.from ? arrowEnd(style.from, x0, y0, -lux, -luy) : ''))

        // The label sits beside the middle of the line, off it on the side
        // the normal points up (or right, for a vertical line), anchored on
        // its near end so a diagonal never runs through the words.
        const words = [style.stereo ? `«${style.stereo}»` : '', e.label || ''].filter(Boolean).join(' ')
        if (words) {
            let nx = -luy
            let ny = lux
            if (ny > 0 || (Math.abs(ny) < 0.01 && nx < 0)) { nx = -nx; ny = -ny }
            const mx = (x0 + x1) / 2 + nx * 10
            const my = (y0 + y1) / 2 + ny * 10
            const anchor = Math.abs(nx) < 0.3 ? 'middle' : nx > 0 ? 'start' : 'end'
            const tw = measure(words, FONT)
            labels.push(text(mx, my, words, 'uml-t uml-halo', anchor))
            const left = anchor === 'start' ? mx : anchor === 'end' ? mx - tw : mx - tw / 2
            grow(left, my - 8)
            grow(left + tw, my + 8)
        }
        // End labels: multiplicity / role, near each end, off the line.
        const endLabel = (value, ex, ey, dx, dy) => {
            if (!value) return
            const px = -dy
            const py = dx
            const flip = Math.abs(dy) > 0.9 ? 1 : (py > 0 ? -1 : 1)
            const lx = ex + dx * 20 + px * 11 * flip
            const ly = ey + dy * 20 + py * 11 * flip
            labels.push(text(lx, ly, value, 'uml-s uml-halo'))
            grow(lx - measure(value, SMALL) / 2, ly - 8)
            grow(lx + measure(value, SMALL) / 2, ly + 8)
        }
        endLabel(e.from_label, x0, y0, lux, luy)
        endLabel(e.to_label, x1, y1, -lux, -luy)
    })

    boxes.forEach((box) => svg.push(drawNode(box.node, box, measure)))
    svg.push(...labels)

    const m = GRID.margin
    const dx = m - minX
    const dy = m - minY

    return {
        width: Math.ceil(maxX - minX + 2 * m),
        height: Math.ceil(maxY - minY + 2 * m),
        body: `<g transform="translate(${r1(dx)} ${r1(dy)})">${svg.join('')}</g>`,
    }
}

/* ------------------------------------------------------------- public -- */

/**
 * The AUTHOR's shape (what a page holds and the editor previews) into the
 * shape the layouts read. UmlDiagram::parse() emits the second one for the
 * reader; the editor's preview hands over the raw JSON, so the defaults and the
 * fragment operands have to be filled in here too, or a fragment the author
 * just typed would draw nothing.
 */
function normalizeItems(items) {
    return (Array.isArray(items) ? items : []).filter((item) => item && typeof item === 'object').map((item) => {
        if (item.fragment) {
            const operands = Array.isArray(item.operands)
                ? item.operands
                : [{label: item.label, messages: item.messages}, ...(Array.isArray(item.else) ? item.else : [])]

            return {fragment: item.fragment, operands: operands.map((o) => ({label: o?.label || '', messages: normalizeItems(o?.messages)}))}
        }
        if (item.over !== undefined) return {...item, over: [].concat(item.over).filter((id) => typeof id === 'string')}
        if (item.divider !== undefined) return item
        if (item.ref !== undefined || item.note !== undefined) return {...item, over: []}

        return {...item, label: item.label || '', kind: item.kind || 'sync'}
    })
}

function normalize(d) {
    if (d.type === 'sequence') {
        return {
            ...d,
            participants: (d.participants || []).filter((p) => p && p.id).map((p) => ({kind: 'object', label: p.id, ...p})),
            messages: normalizeItems(d.messages),
        }
    }

    return {
        ...d,
        nodes: (d.nodes || []).filter((n) => n && n.id).map((n) => ({kind: 'class', ...n})),
        edges: (d.edges || []).filter((e) => e && e.from && e.to).map((e) => ({kind: 'association', ...e})),
        groups: (d.groups || []).filter((g) => g && Array.isArray(g.nodes)),
    }
}

/** The diagram as SVG markup at its natural size. */
export function renderUml(diagram, measure) {
    diagram = normalize(diagram)
    const drawn = diagram.type === 'sequence' ? renderSequence(diagram, measure) : renderGrid(diagram, measure)
    const label = diagram.title || diagram.type

    return {
        width: drawn.width,
        height: drawn.height,
        svg: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${drawn.width} ${drawn.height}" width="${drawn.width}" height="${drawn.height}"`
            + ` role="img" aria-label="${esc(label)}" aria-hidden="true" class="ak-uml__svg">${STYLE}${drawn.body}</svg>`,
    }
}

const EXPAND_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15"/></svg>'
const COLLAPSE_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 9V4.5M9 9H4.5M9 9 3.75 3.75M9 15v4.5M9 15H4.5M9 15l-5.25 5.25M15 9h4.5M15 9V4.5M15 9l5.25-5.25M15 15h4.5M15 15v4.5m0-4.5 5.25 5.25"/></svg>'
const ACTUAL_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607ZM10.5 7.5v6m3-3h-6"/></svg>'

const mounted = new WeakMap()

function isExpanded(stage) {
    return document.fullscreenElement === stage || stage.classList.contains('is-expanded')
}

function syncButtons(stage, state) {
    const expanded = isExpanded(stage)
    state.expand.innerHTML = expanded ? COLLAPSE_ICON : EXPAND_ICON
    const label = expanded ? 'Sair da tela cheia' : 'Tela cheia'
    state.expand.setAttribute('aria-label', label)
    state.expand.title = label
    state.zoom.hidden = !expanded
    stage.classList.toggle('is-actual', expanded && state.actual)
    const zoomLabel = state.actual ? 'Ajustar à tela' : 'Tamanho real'
    state.zoom.setAttribute('aria-label', zoomLabel)
    state.zoom.title = zoomLabel
    state.zoom.setAttribute('aria-pressed', state.actual ? 'true' : 'false')
}

function button(className, onClick) {
    const el = document.createElement('button')
    el.type = 'button'
    el.className = className
    el.addEventListener('click', (e) => {
        // Inside the editor this sits in a block; the click is the button's.
        e.preventDefault()
        e.stopPropagation()
        onClick()
    })

    return el
}

/**
 * Draws `diagram` into `stage` (the reader's figure or the editor's preview)
 * and gives it the fullscreen button. Native fullscreen where the browser
 * offers it on an element; a fixed overlay where it does not (iPhone Safari).
 */
export function mountUml(stage, diagram) {
    let state = mounted.get(stage)

    if (!state) {
        state = {diagram: null, actual: false}
        state.expand = button('ak-uml-toggle ak-uml-toggle--expand', () => {
            if (isExpanded(stage)) {
                if (document.fullscreenElement === stage) document.exitFullscreen?.()
                stage.classList.remove('is-expanded')
            } else if (stage.requestFullscreen && document.fullscreenEnabled) {
                stage.requestFullscreen().catch(() => stage.classList.add('is-expanded'))
            } else {
                stage.classList.add('is-expanded')
            }
            state.actual = false
            setTimeout(() => syncButtons(stage, state), 0)
        })
        state.zoom = button('ak-uml-toggle ak-uml-toggle--zoom', () => {
            state.actual = !state.actual
            syncButtons(stage, state)
        })
        state.zoom.innerHTML = ACTUAL_ICON
        stage.addEventListener('fullscreenchange', () => {
            if (document.fullscreenElement !== stage) state.actual = false
            syncButtons(stage, state)
        })
        stage.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && stage.classList.contains('is-expanded')) {
                stage.classList.remove('is-expanded')
                state.actual = false
                syncButtons(stage, state)
            }
        })
        mounted.set(stage, state)

        // Widths are measured with the page font; once it has loaded, measure
        // again so a fallback font's widths never stay on screen.
        document.fonts?.ready?.then(() => state.diagram && paint(stage, state))
    }

    state.diagram = diagram
    paint(stage, state)
}

function paint(stage, state) {
    const {svg, width} = renderUml(state.diagram, measurer(getComputedStyle(stage).fontFamily))
    stage.innerHTML = svg
    // Inline it shrinks to the column, but never below 60% of its size: past
    // that the labels stop being legible, and the stage scrolls instead.
    stage.firstElementChild.style.minWidth = `${Math.round(width * 0.6)}px`
    stage.append(state.zoom, state.expand)
    syncButtons(stage, state)
}

/** Reader side: every figure GitbookRenderer emitted with its data. */
export function init() {
    document.querySelectorAll('figure[data-ak-uml]:not([data-ak-uml-ready])').forEach((figure) => {
        let diagram
        try {
            diagram = JSON.parse(figure.dataset.akUml || 'null')
        } catch {
            return
        }

        const stage = figure.querySelector('[data-ak-uml-stage]')
        if (!diagram || !stage) return

        figure.dataset.akUmlReady = '1'
        try {
            mountUml(stage, diagram)
            figure.classList.add('is-drawn')
        } catch {
            // The text stays: it is the documentation, the picture is a view of it.
            delete figure.dataset.akUmlReady
        }
    })
}
