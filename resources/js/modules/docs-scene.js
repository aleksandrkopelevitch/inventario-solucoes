// docs-scene.js — draws an animated SCENE inside a documentation page: the
// "fluxo em etapas" (`{% scene type="steps" %}`), the "antes → depois"
// (`{% scene type="before-after" %}`) and the "árvore" (`{% scene type="tree"
// %}`), one renderer per type (RENDERERS) over one shared figure builder
// (`figure()`).
//
// The ONE renderer of the picture, used in two places: the reader (/docs, the
// magic link, the editor's read-only views), where GitbookRenderer emits the
// steps as an ordered list plus the same data as JSON on the figure
// (`data-ak-scene`), and the editor's block (docs-tools/scene.js), which calls
// `mountScene()` for its live preview. A second copy in PHP would have been
// the same picture drifting apart; here the text can also be MEASURED, which is
// what lets a label wrap inside its card instead of being guessed at.
//
// Nothing about placement comes from the data. The model (or the author)
// chooses the steps; this file chooses columns, sizes, colours and timing, so a
// scene can never come out crooked or off-brand.
//
// How it animates is pending-drawing.js's idea: every mark gets its own
// @keyframes over ONE shared cycle, so the loop stays in step forever
// (`animation-delay` would only offset the first pass). The cards appear in
// order, the arrows draw between them with a lime marker riding the line, and
// once everything is on screen the data keeps flowing along the arrows until
// the cycle clears. Under prefers-reduced-motion the finished figure is shown
// still. Colours are the app's own tokens (CSS variables), so the figure
// follows the theme it sits in.

const CARD_MIN = 168
const MAX_COLUMNS = 4
const GAP_X = 44
const GAP_Y = 52
const PAD = 14
const BADGE = 24
const EDGE = 3 // room for strokes and the highlight halo at the svg's edges

const TITLE = {size: 13.5, weight: 600, line: 18, maxLines: 3}
const DETAIL = {size: 12.5, weight: 400, line: 17, maxLines: 4}

// Seconds. A card lands, then the arrow to the next one draws.
const LEAD = 0.35
const CARD_IN = 0.45
const LINE_IN = 0.6
const HOLD = 2.6
const FADE = 0.5
const BLANK = 0.35

let sequence = 0

/* ------------------------------------------------------------- measure -- */

let canvas = null

function measurer(fontFamily) {
    canvas ??= document.createElement('canvas')
    const ctx = canvas.getContext('2d')

    return (text, {size, weight}) => {
        ctx.font = `${weight} ${size}px ${fontFamily}`

        return ctx.measureText(text).width
    }
}

/** Greedy word wrap, ellipsised on the last line it is allowed. */
function wrap(text, spec, width, measure) {
    const words = String(text || '').split(/\s+/).filter(Boolean)
    const lines = []
    let line = ''

    for (const word of words) {
        const attempt = line ? `${line} ${word}` : word
        if (measure(attempt, spec) <= width || !line) {
            line = attempt
        } else {
            lines.push(line)
            line = word
        }
    }
    if (line) lines.push(line)

    if (lines.length <= spec.maxLines) return lines

    const kept = lines.slice(0, spec.maxLines)
    let last = kept[kept.length - 1]
    while (last && measure(`${last}…`, spec) > width) last = last.slice(0, -1).trimEnd()
    kept[kept.length - 1] = `${last}…`

    return kept
}

/* -------------------------------------------------------------- layout -- */

/** Columns that fit, then rebalanced so the rows come out as even as they can. */
function columnsFor(count, width) {
    const fit = Math.max(1, Math.floor((width + GAP_X) / (CARD_MIN + GAP_X)))
    const max = Math.min(count, fit, MAX_COLUMNS)
    const rows = Math.ceil(count / max)

    return Math.ceil(count / rows)
}

function layout(steps, width, measure) {
    const cols = columnsFor(steps.length, width)
    const cardW = (width - EDGE * 2 - (cols - 1) * GAP_X) / cols
    const textW = cardW - PAD * 2

    const texts = steps.map((step) => ({
        title: wrap(step.title, TITLE, textW, measure),
        detail: wrap(step.detail, DETAIL, textW, measure),
    }))

    // One height for every card — a row of cards of different heights stops
    // reading as a sequence of equals.
    const cardH = Math.max(...texts.map(({title, detail}) =>
        PAD + BADGE + 10 + title.length * TITLE.line + (detail.length ? 6 + detail.length * DETAIL.line : 0) + PAD - 4,
    ))

    const cards = steps.map((step, i) => {
        const row = Math.floor(i / cols)
        const col = i % cols

        return {
            ...step,
            ...texts[i],
            n: i + 1,
            x: EDGE + col * (cardW + GAP_X),
            y: EDGE + row * (cardH + GAP_Y),
            w: cardW,
            h: cardH,
            row,
        }
    })

    // Orthogonal connectors, like the canvas: across within a row; down, over
    // and down again into the first card of the next row.
    const links = cards.slice(0, -1).map((a, i) => {
        const b = cards[i + 1]

        if (a.row === b.row) {
            const y = a.y + a.h / 2

            return [[a.x + a.w, y], [b.x - 4, y]]
        }

        const ax = a.x + a.w / 2
        const bx = b.x + b.w / 2
        const mid = a.y + a.h + GAP_Y / 2

        return Math.abs(ax - bx) < 1
            ? [[ax, a.y + a.h], [bx, b.y - 4]]
            : [[ax, a.y + a.h], [ax, mid], [bx, mid], [bx, b.y - 4]]
    })

    const rows = Math.ceil(steps.length / cols)

    return {cards, links, height: EDGE * 2 + rows * cardH + (rows - 1) * GAP_Y}
}

/* ------------------------------------------------------------ timeline -- */

function timeline(count) {
    const cards = []
    const links = []
    let t = LEAD

    for (let i = 0; i < count; i++) {
        cards.push(t)
        t += CARD_IN
        if (i < count - 1) {
            links.push([t, t + LINE_IN])
            t += LINE_IN
        }
    }

    const holdUntil = t + HOLD
    const goneAt = holdUntil + FADE

    return {cards, links, drawnAt: t, holdUntil, goneAt, cycle: goneAt + BLANK}
}

/* ------------------------------------------------------------- drawing -- */

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
const r1 = (v) => Math.round(v * 10) / 10

function pathOf(points) {
    return 'M' + points.map(([x, y]) => `${r1(x)} ${r1(y)}`).join(' L')
}

function head([x, y], [px, py]) {
    const angle = Math.atan2(y - py, x - px)
    const len = 9
    const spread = 0.45
    const a = [x - len * Math.cos(angle - spread), y - len * Math.sin(angle - spread)]
    const b = [x - len * Math.cos(angle + spread), y - len * Math.sin(angle + spread)]

    return `<polygon class="head" points="${r1(x)},${r1(y)} ${r1(a[0])},${r1(a[1])} ${r1(b[0])},${r1(b[1])}"/>`
}

function card(c) {
    const hl = c.highlight ? ' hl' : ''
    const bx = c.x + PAD + BADGE / 2
    const by = c.y + PAD + BADGE / 2
    let ty = c.y + PAD + BADGE + 10 + TITLE.size

    let svg = ''
    if (c.highlight) {
        svg += `<rect class="glow" x="${r1(c.x - 3)}" y="${r1(c.y - 3)}" width="${r1(c.w + 6)}" height="${r1(c.h + 6)}" rx="14"/>`
    }
    svg += `<rect class="card${hl}" x="${r1(c.x)}" y="${r1(c.y)}" width="${r1(c.w)}" height="${r1(c.h)}" rx="11"/>`
    svg += `<circle class="badge${hl}" cx="${r1(bx)}" cy="${r1(by)}" r="${BADGE / 2}"/>`
    svg += `<text class="num${hl}" x="${r1(bx)}" y="${r1(by + 4.2)}" text-anchor="middle">${c.n}</text>`

    if (c.highlight) {
        svg += `<rect class="tag" x="${r1(bx + BADGE / 2 + 8)}" y="${r1(by - 9)}" width="74" height="18" rx="9"/>`
            + `<text class="tag-text" x="${r1(bx + BADGE / 2 + 45)}" y="${r1(by + 3.6)}" text-anchor="middle">Ponto-chave</text>`
    }

    c.title.forEach((line) => {
        svg += `<text class="title" x="${r1(c.x + PAD)}" y="${r1(ty)}">${esc(line)}</text>`
        ty += TITLE.line
    })

    if (c.detail.length) {
        ty += 6 - TITLE.line + DETAIL.line
        c.detail.forEach((line) => {
            svg += `<text class="detail" x="${r1(c.x + PAD)}" y="${r1(ty)}">${esc(line)}</text>`
            ty += DETAIL.line
        })
    }

    return svg
}

/** The marker riding each arrow as it draws, invisible between arrows. */
function tokenKeyframes(links, times, pct) {
    const frames = []
    const at = ([x, y]) => `transform:translate(${r1(x)}px,${r1(y)}px)`

    links.forEach((points, i) => {
        const [start, end] = times[i]
        const lengths = points.slice(1).map(([x, y], k) => Math.hypot(x - points[k][0], y - points[k][1]))
        const total = lengths.reduce((a, b) => a + b, 0) || 1

        frames.push(`${pct(Math.max(start - 0.02, 0))}%{${at(points[0])};opacity:0}`)
        frames.push(`${pct(start)}%{${at(points[0])};opacity:1}`)
        let run = 0
        lengths.forEach((len, k) => {
            run += len
            frames.push(`${pct(start + (end - start) * (run / total))}%{${at(points[k + 1])};opacity:1}`)
        })
        frames.push(`${pct(end + 0.18)}%{${at(points[points.length - 1])};opacity:0}`)
    })

    const last = links[links.length - 1]
    frames.push(`100%{${at(last[last.length - 1])};opacity:0}`)

    return frames.join('')
}

/**
 * What every scene's SVG is made of: one cycle, keyframes named per figure
 * (keyframes are document-global, so two figures on a page must not share a
 * name), the arrows with their riding marker and the data that keeps flowing
 * along them once everything is drawn, and the shared stylesheet.
 */
function figure(time) {
    const uid = `aks${++sequence}`
    const pct = (s) => r1((s / time.cycle) * 100)
    const hold = pct(time.holdUntil)
    const gone = pct(time.goneAt)
    const css = []
    const back = [] // drawn first: arrows sit under the cards
    const body = []
    let mark = 0

    const named = (frames, timing = 'cubic-bezier(.3,.7,.3,1)') => {
        const id = `${uid}m${mark++}`
        css.push(`@keyframes ${id}{${frames}}.${uid} .${id}{animation:${id} ${r1(time.cycle)}s ${timing} infinite}`)

        return id
    }

    /** A mark that arrives between `start` and `end`, holds, and clears with the cycle. */
    const keyframes = (from, to, start, end, extra = '') =>
        named(`0%,${pct(start)}%{${from}}${extra}${pct(end)}%,${hold}%{${to}}${gone}%,100%{${to};opacity:0}`)

    const pop = (start) => keyframes('opacity:0;transform:scale(.86)', 'opacity:1;transform:scale(1)', start, start + CARD_IN)

    /**
     * Arrows along `links`, each drawn during its window in `times` (which
     * must be in time order — the riding marker walks them one after the
     * other). A tree passes `heads: false, flow: false`: its lines say
     * "contains", not "goes to", and data streaming down a hierarchy would say
     * something it does not mean.
     */
    const arrows = (links, times, {heads = true, flow = true} = {}) => {
        if (!links.length) return

        links.forEach((points, i) => {
            const [start, end] = times[i]
            // The draw trick (`pathLength="1"` + dash offset); hidden outright
            // until its turn, since at offset 1 a round cap still leaves a dot.
            const line = keyframes('opacity:0;stroke-dashoffset:1', 'opacity:1;stroke-dashoffset:0', start, end, `${pct(start + 0.01)}%{opacity:1;stroke-dashoffset:1}`)
            back.push(`<path class="mk line ${line}" d="${pathOf(points)}" pathLength="1" stroke-dasharray="1 1"/>`)
            if (heads) {
                const tip = keyframes('opacity:0', 'opacity:1', end - 0.05, end + 0.05)
                body.push(`<g class="mk ${tip}">${head(points[points.length - 1], points[points.length - 2])}</g>`)
            }
        })

        const token = named(tokenKeyframes(links, times, pct), 'linear')
        body.push(`<g class="fx ${token}"><circle class="halo" r="9"/><circle class="token" r="4.5"/></g>`)

        if (!flow) return

        // Once everything is drawn, data keeps travelling along the arrows.
        // SMIL, with NEGATIVE begins so the dots are already under way: a dot
        // whose motion has not started sits at the svg's top-left corner.
        const streaming = keyframes('opacity:0', 'opacity:1', time.drawnAt, time.drawnAt + 0.4)
        const dots = links.map((points, i) => [0, 0.5].map((offset) =>
            `<circle class="token" r="3.5"><animateMotion dur="1.4s" begin="-${r1(i * 0.27 + offset * 1.4)}s" repeatCount="indefinite" path="${pathOf(points)}"/></circle>`,
        ).join('')).join('')
        body.push(`<g class="fx ${streaming}">${dots}</g>`)
    }

    const svg = (width, height, label) => {
        const u = `.${uid}`
        const style = [
            `${u}{display:block;overflow:visible;font-family:inherit}`,
            `${u} g,${u} path{transform-box:fill-box;transform-origin:center}`,
            `${u} .card{fill:var(--color-surface,#fff);stroke:var(--color-line-2,#cbd6cd);stroke-width:1.5}`,
            `${u} .card.hl{stroke:var(--color-lime,#aadb1e);stroke-width:2.5}`,
            `${u} .card.old{fill:var(--color-raised,#e3e9e4);stroke:var(--color-line,#d9e1da)}`,
            `${u} .card.new{stroke:var(--color-accent,#1b4d2e)}`,
            `${u} .card.new.hl{stroke:var(--color-lime,#aadb1e)}`,
            // Filled, faintly: the board's dot grid showing through made the
            // italic "deixa de existir" read as "deixa.de.existir".
            `${u} .empty{fill:var(--color-surface,#fff);fill-opacity:.6;stroke:var(--color-line-2,#cbd6cd);stroke-width:1.5;stroke-dasharray:5 5}`,
            `${u} .none{fill:var(--color-faint,#9aa89c);font-size:12px;font-style:italic}`,
            `${u} .glow{fill:var(--color-lime-soft,#f3f9db)}`,
            `${u} .flash{fill:none;stroke:var(--color-lime,#aadb1e);stroke-width:3}`,
            `${u} .badge{fill:var(--color-accent,#1b4d2e)}`,
            `${u} .badge.hl{fill:var(--color-lime,#aadb1e)}`,
            `${u} .num{fill:#fff;font-size:12px;font-weight:700}`,
            `${u} .num.hl{fill:var(--color-lime-ink,#3f5708)}`,
            `${u} .tag{fill:var(--color-lime-soft,#f3f9db);stroke:var(--color-lime-line,#e2efb0)}`,
            `${u} .tag-text{fill:var(--color-lime-ink,#3f5708);font-size:10.5px;font-weight:700}`,
            `${u} .tag.gone{fill:var(--color-crit-soft,#f7ecec);stroke:var(--color-crit-line,#eccccc)}`,
            `${u} .tag-text.gone{fill:var(--color-crit,#b23b3b)}`,
            `${u} .pill{fill:var(--color-raised,#e3e9e4)}`,
            `${u} .pill.to{fill:var(--color-accent,#1b4d2e)}`,
            `${u} .pill-text{fill:var(--color-muted,#5c7563);font-size:12px;font-weight:700}`,
            `${u} .pill-text.to{fill:#fff}`,
            `${u} .aspect{fill:var(--color-ink,#0b0d10);font-size:12px;font-weight:700}`,
            `${u} .side{fill:var(--color-muted,#5c7563);font-size:10.5px;font-weight:700}`,
            `${u} .title{fill:var(--color-ink,#0b0d10);font-size:${TITLE.size}px;font-weight:${TITLE.weight}}`,
            `${u} .detail{fill:var(--color-muted,#5c7563);font-size:${DETAIL.size}px}`,
            `${u} .text{fill:var(--color-ink,#0b0d10);font-size:${SIDE.size}px}`,
            `${u} .text.old{fill:var(--color-muted,#5c7563)}`,
            `${u} .node-root{fill:var(--color-accent,#1b4d2e)}`,
            `${u} .node-root.hl{stroke:var(--color-lime,#aadb1e);stroke-width:3}`,
            `${u} .bar{fill:var(--color-accent,#1b4d2e)}`,
            `${u} .label-root{fill:#fff;font-size:${TREE_TEXT[0].label.size}px;font-weight:${TREE_TEXT[0].label.weight}}`,
            `${u} .detail-root{fill:#fff;fill-opacity:.78;font-size:${TREE_TEXT[0].detail.size}px}`,
            `${u} .label-1{fill:var(--color-ink,#0b0d10);font-size:${TREE_TEXT[1].label.size}px;font-weight:${TREE_TEXT[1].label.weight}}`,
            `${u} .label-2{fill:var(--color-ink,#0b0d10);font-size:${TREE_TEXT[2].label.size}px;font-weight:${TREE_TEXT[2].label.weight}}`,
            `${u} .detail-1{fill:var(--color-muted,#5c7563);font-size:${TREE_TEXT[1].detail.size}px}`,
            `${u} .detail-2{fill:var(--color-muted,#5c7563);font-size:${TREE_TEXT[2].detail.size}px}`,
            `${u} .line{fill:none;stroke:var(--color-muted,#5c7563);stroke-opacity:.55;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}`,
            `${u} .head{fill:var(--color-muted,#5c7563);fill-opacity:.75}`,
            `${u} .token{fill:var(--color-lime,#aadb1e);stroke:var(--color-accent,#1b4d2e);stroke-width:1.5}`,
            `${u} .halo{fill:var(--color-lime,#aadb1e);opacity:.35}`,
            ...css,
            // Paused (the figure's own button) and reduced motion show the same
            // thing: the finished figure, still.
            `${u}.is-paused .mk{animation:none!important;opacity:1!important;transform:none!important;stroke-dashoffset:0!important}${u}.is-paused .fx{display:none!important}`,
            `@media (prefers-reduced-motion:reduce){${u} .mk{animation:none!important;opacity:1!important;transform:none!important;stroke-dashoffset:0!important}${u} .fx{display:none!important}}`,
        ].join('')

        // Hidden from assistive technology: the same words are in the document
        // as a list or a table (GitbookRenderer), which is what a screen
        // reader should read. `data-label` keeps a one-line summary for tests
        // and for anyone inspecting the figure.
        return `<svg class="${uid}" xmlns="http://www.w3.org/2000/svg" width="${r1(width)}" height="${r1(height)}" viewBox="0 0 ${r1(width)} ${r1(height)}" aria-hidden="true" focusable="false" data-label="${esc(label)}">`
            + `<style>${style}</style>${back.join('')}${body.join('')}</svg>`
    }

    return {pct, named, keyframes, pop, arrows, body, svg}
}

/** A small rounded label sitting on a card's top edge, right-aligned. */
function tag(x, y, text, measure, tone = '') {
    const w = measure(text, {size: 10.5, weight: 700}) + 16

    return `<rect class="tag ${tone}" x="${r1(x - w)}" y="${r1(y - 9)}" width="${r1(w)}" height="18" rx="9"/>`
        + `<text class="tag-text ${tone}" x="${r1(x - w / 2)}" y="${r1(y + 3.6)}" text-anchor="middle">${esc(text)}</text>`
}

/* ---------------------------------------------------- fluxo em etapas -- */

function renderSteps(scene, width, measure) {
    const steps = (scene.steps || []).filter((s) => (s.title || '').trim() || (s.detail || '').trim())
    if (!steps.length) return ''

    const {cards, links, height} = layout(steps, width, measure)
    const time = timeline(cards.length)
    const f = figure(time)

    cards.forEach((c, i) => f.body.push(`<g class="mk ${f.pop(time.cards[i])}">${card(c)}</g>`))
    f.arrows(links, time.links)

    return f.svg(width, height, steps.map((s, i) => `${i + 1}. ${s.title}`).join('; '))
}

/* ------------------------------------------------------ antes → depois -- */

const SIDE = {size: 13, weight: 400, line: 18, maxLines: 5}
const BA_GAP = 64 // the arrow between the columns
const BA_WIDE = 520 // below this, before and after stack
const BA_ROW_GAP = 22
const BA_ASPECT = 22 // the row's aspect line above its cards
const BA_LABEL = 18 // the in-card column label, stacked layout only
const BA_ROW = 1.3 // seconds between one row and the next

function layoutBeforeAfter(scene, changes, width, measure) {
    const wide = width >= BA_WIDE
    const colW = wide ? (width - EDGE * 2 - BA_GAP) / 2 : width - EDGE * 2
    const textW = colW - PAD * 2
    const textH = (lines) => PAD * 2 + SIDE.size + (Math.max(lines, 1) - 1) * SIDE.line + 2
    const from = (scene.from || '').trim() || 'Antes'
    const to = (scene.to || '').trim() || 'Depois'

    let y = EDGE
    const header = wide ? {y, from, to, colW, xB: EDGE, xA: EDGE + colW + BA_GAP} : null
    if (wide) y += 26 + 16

    const rows = changes.map((change) => {
        const before = wrap(change.before, SIDE, textW, measure)
        const after = wrap(change.after, SIDE, textW, measure)
        const aspectY = y + 12
        y += BA_ASPECT

        let b
        let a
        let link

        if (wide) {
            // One height for the pair, so the arrow meets both at the middle.
            const h = textH(Math.max(before.length, after.length))
            b = {x: EDGE, y, w: colW, h}
            a = {x: EDGE + colW + BA_GAP, y, w: colW, h}
            link = [[b.x + b.w, y + h / 2], [a.x - 4, y + h / 2]]
            y += h + BA_ROW_GAP
        } else {
            const hb = before.length ? textH(before.length) + BA_LABEL : textH(1) + BA_LABEL
            b = {x: EDGE, y, w: colW, h: hb}
            y += hb + 34
            const ha = after.length ? textH(after.length) + BA_LABEL : textH(1) + BA_LABEL
            a = {x: EDGE, y, w: colW, h: ha}
            link = [[b.x + colW / 2, b.y + hb], [a.x + colW / 2, a.y - 4]]
            y += ha + BA_ROW_GAP
        }

        return {...change, before, after, aspectY, b, a, link}
    })

    return {header, rows, wide, from, to, height: y - BA_ROW_GAP + EDGE}
}

/** One side of a row: a card with its text, or the dashed slot of "nothing". */
function side(box, lines, {wide, label, empty, old}) {
    const top = box.y + (wide ? 0 : BA_LABEL)
    let svg = ''

    if (!lines.length) {
        svg += `<rect class="empty" x="${r1(box.x)}" y="${r1(box.y)}" width="${r1(box.w)}" height="${r1(box.h)}" rx="11"/>`
        if (!wide) svg += `<text class="side" x="${r1(box.x + PAD)}" y="${r1(box.y + PAD + 6)}">${esc(label)}</text>`
        svg += `<text class="none" x="${r1(box.x + box.w / 2)}" y="${r1(top + (box.h - (top - box.y)) / 2 + 4)}" text-anchor="middle">${empty}</text>`

        return svg
    }

    if (!wide) svg += `<text class="side" x="${r1(box.x + PAD)}" y="${r1(box.y + PAD + 6)}">${esc(label)}</text>`

    let ty = top + PAD + SIDE.size
    lines.forEach((line) => {
        svg += `<text class="text${old ? ' old' : ''}" x="${r1(box.x + PAD)}" y="${r1(ty)}">${esc(line)}</text>`
        ty += SIDE.line
    })

    return svg
}

function renderBeforeAfter(scene, width, measure) {
    const changes = (scene.changes || []).filter((c) => (c.before || '').trim() || (c.after || '').trim())
    if (!changes.length) return ''

    const {header, rows, wide, from, to, height} = layoutBeforeAfter(scene, changes, width, measure)

    // A row: the old side lands, the arrow draws, the new side lands with a
    // lime flash — and the old side settles back, so the eye stays on "depois".
    const starts = rows.map((_, i) => LEAD + 0.4 + i * BA_ROW)
    const lines = starts.map((s) => [s + CARD_IN, s + CARD_IN + 0.5])
    const drawnAt = starts[starts.length - 1] + CARD_IN + 0.5 + CARD_IN
    const time = {drawnAt, holdUntil: drawnAt + 3, goneAt: drawnAt + 3 + FADE, cycle: drawnAt + 3 + FADE + BLANK}
    const f = figure(time)
    const {pct} = f

    if (header) {
        const pill = (x, text, cls) => {
            const w = measure(text, {size: 12, weight: 700}) + 26
            const cx = x + header.colW / 2

            return `<rect class="pill ${cls}" x="${r1(cx - w / 2)}" y="${r1(header.y)}" width="${r1(w)}" height="26" rx="13"/>`
                + `<text class="pill-text ${cls}" x="${r1(cx)}" y="${r1(header.y + 17.5)}" text-anchor="middle">${esc(text)}</text>`
        }
        const id = f.keyframes('opacity:0', 'opacity:1', LEAD, LEAD + 0.4)
        f.body.push(`<g class="mk ${id}">${pill(header.xB, from, 'from')}${pill(header.xA, to, 'to')}</g>`)
    }

    rows.forEach((row, i) => {
        const s = starts[i]
        const after = s + CARD_IN + 0.5
        const isNew = !row.before.length
        const isGone = !row.after.length

        // The aspect lands with the row and stays as it is: it names the row,
        // it is not part of the old world.
        f.body.push(`<g class="mk ${f.keyframes('opacity:0', 'opacity:1', s, s + CARD_IN)}">`
            + `<text class="aspect" x="${r1(row.b.x + 2)}" y="${r1(row.aspectY)}">${esc(row.aspect)}</text></g>`)

        // The old side.
        const oldId = f.named(
            `0%,${pct(s)}%{opacity:0;transform:scale(.86)}${pct(s + CARD_IN)}%,${pct(after + 0.2)}%{opacity:1;transform:scale(1)}`
            + `${pct(after + 0.7)}%,${pct(time.holdUntil)}%{opacity:${isGone ? 1 : 0.78};transform:scale(1)}`
            + `${pct(time.goneAt)}%,100%{opacity:0;transform:scale(1)}`,
        )
        const oldCard = isNew ? '' : `<rect class="card old" x="${r1(row.b.x)}" y="${r1(row.b.y)}" width="${r1(row.b.w)}" height="${r1(row.b.h)}" rx="11"/>`
        f.body.push(`<g class="mk ${oldId}">`
            + oldCard
            + side(row.b, row.before, {wide, label: from, empty: 'não existia', old: true})
            + (isGone ? tag(row.b.x + row.b.w - 10, row.b.y, 'Sai', measure, 'gone') : '')
            + '</g>')

        // The new side.
        const hl = row.highlight ? ' hl' : ''
        let newSide = ''
        if (!isGone) {
            if (row.highlight) {
                newSide += `<rect class="glow" x="${r1(row.a.x - 3)}" y="${r1(row.a.y - 3)}" width="${r1(row.a.w + 6)}" height="${r1(row.a.h + 6)}" rx="14"/>`
            }
            newSide += `<rect class="card new${hl}" x="${r1(row.a.x)}" y="${r1(row.a.y)}" width="${r1(row.a.w)}" height="${r1(row.a.h)}" rx="11"/>`
        }
        newSide += side(row.a, row.after, {wide, label: to, empty: 'deixa de existir', old: false})
        if (row.highlight) newSide += tag(row.a.x + row.a.w - 10, row.a.y, 'Principal mudança', measure)
        else if (isNew) newSide += tag(row.a.x + row.a.w - 10, row.a.y, 'Novo', measure)
        f.body.push(`<g class="mk ${f.pop(after)}">${newSide}</g>`)

        // The flash as the new side lands.
        if (!isGone) {
            const flash = f.named(
                `0%,${pct(after)}%{opacity:0;transform:scale(1)}${pct(after + 0.08)}%{opacity:.95;transform:scale(1)}`
                + `${pct(after + 0.75)}%,100%{opacity:0;transform:scale(1.05)}`,
            )
            f.body.push(`<g class="fx ${flash}"><rect class="flash" x="${r1(row.a.x - 4)}" y="${r1(row.a.y - 4)}" width="${r1(row.a.w + 8)}" height="${r1(row.a.h + 8)}" rx="14"/></g>`)
        }
    })

    f.arrows(rows.map((row) => row.link), lines)

    return f.svg(width, height, `${from} → ${to}: ` + rows.map((row) => row.aspect).join('; '))
}

/* -------------------------------------------------------------- árvore -- */

const TREE_TEXT = {
    0: {label: {size: 14.5, weight: 700, line: 19, maxLines: 2}, detail: {size: 12, weight: 400, line: 16, maxLines: 2}},
    1: {label: {size: 13.5, weight: 600, line: 18, maxLines: 2}, detail: {size: 12, weight: 400, line: 16, maxLines: 3}},
    2: {label: {size: 12.5, weight: 600, line: 17, maxLines: 2}, detail: {size: 11.5, weight: 400, line: 15, maxLines: 2}},
}
const TREE_PAD = [13, 12, 9]
const TREE_GAP = 18 // between the columns of the chart
const TREE_INDENT = 26 // per level, in the outline
const TREE_STEP = 10 // between stacked nodes

/**
 * The outline as the reader may receive it — edited by hand, so possibly
 * ragged — made into a tree: the first item is the one root, nothing sits more
 * than one step below the item before it or past level 2 (TreeScene::
 * normalizeLevels() is the same rule on the server), and every item learns its
 * parent.
 */
function treeNodes(nodes) {
    const parents = []
    let previous = -1

    return nodes.map((node, i) => {
        const level = i === 0 ? 0 : Math.max(1, Math.min(Number(node.level) || 0, previous + 1, 2))
        previous = level
        parents[level] = i

        return {...node, level, parent: level === 0 ? null : parents[level - 1]}
    })
}

/** A node's text, wrapped for a card `w` wide, and the card's height. */
function sizeNode(node, w, measure) {
    const spec = TREE_TEXT[node.level]
    const pad = TREE_PAD[node.level]
    const inner = w - pad * 2 - (node.level === 1 ? 6 : 0)
    const label = wrap(node.label, spec.label, inner, measure)
    const detail = wrap(node.detail, spec.detail, inner, measure)

    return {
        label,
        detail,
        h: pad * 2 + spec.label.size + (label.length - 1) * spec.label.line + (detail.length ? 5 + detail.length * spec.detail.line : 0) + 2,
    }
}

function layoutTree(nodes, width, measure) {
    const kids = nodes.map((n, i) => i).filter((i) => nodes[i].level === 1)
    const colW = kids.length ? (width - EDGE * 2 - (kids.length - 1) * TREE_GAP) / kids.length : 0
    // The chart needs room for every branch side by side; past four, or on a
    // narrow column, the outline reads better than a row of slivers.
    const chart = width >= 520 && kids.length >= 2 && kids.length <= 4 && colW >= 140

    const boxes = []
    const links = []

    if (chart) {
        const rootW = Math.min(Math.max(width * 0.42, 220), 360, width - EDGE * 2)
        const root = {x: (width - rootW) / 2, y: EDGE, w: rootW, ...sizeNode(nodes[0], rootW, measure)}
        boxes[0] = root
        const busY = root.y + root.h + 20
        const top = busY + 20
        const rcx = root.x + root.w / 2

        const firstRow = kids.map((i, c) => ({x: EDGE + c * (colW + TREE_GAP), y: top, w: colW, ...sizeNode(nodes[i], colW, measure)}))
        const rowH = Math.max(...firstRow.map((b) => b.h))
        let bottom = top + rowH

        kids.forEach((i, c) => {
            const box = {...firstRow[c], h: rowH}
            boxes[i] = box
            const kcx = box.x + box.w / 2
            links[i] = Math.abs(kcx - rcx) < 1
                ? [[rcx, root.y + root.h], [kcx, box.y - 2]]
                : [[rcx, root.y + root.h], [rcx, busY], [kcx, busY], [kcx, box.y - 2]]

            let y = box.y + box.h + 12
            const spine = box.x + 12
            nodes.forEach((node, j) => {
                if (node.parent !== i) return
                const child = {x: box.x + 24, y, w: box.w - 24, ...sizeNode(node, box.w - 24, measure)}
                boxes[j] = child
                links[j] = [[spine, box.y + box.h], [spine, child.y + child.h / 2], [child.x - 2, child.y + child.h / 2]]
                y += child.h + TREE_STEP
            })
            bottom = Math.max(bottom, y - TREE_STEP)
        })

        // Breadth first: the root, every branch, then what each branch holds.
        const order = [0, ...kids, ...kids.flatMap((i) => nodes.map((n, j) => j).filter((j) => nodes[j].parent === i))]

        return {boxes, links, order, chart, height: bottom + EDGE}
    }

    // The outline is a column, not a banner: on a wide screen a one-word item
    // stretched across 700px is mostly empty card, so the block is capped and
    // centred.
    const blockW = Math.min(width - EDGE * 2, 620)
    const left = (width - blockW) / 2
    let y = EDGE
    nodes.forEach((node, i) => {
        const x = left + node.level * TREE_INDENT
        const w = left + blockW - x
        const box = {x, y, w, ...sizeNode(node, w, measure)}
        boxes[i] = box
        if (node.parent !== null) {
            const parent = boxes[node.parent]
            const spine = parent.x + 14
            links[i] = [[spine, parent.y + parent.h], [spine, box.y + box.h / 2], [box.x - 2, box.y + box.h / 2]]
        }
        y += box.h + TREE_STEP
    })

    // Depth first — reading order, the way the outline is written.
    return {boxes, links, order: nodes.map((n, i) => i), chart, height: y - TREE_STEP + EDGE}
}

function treeNode(node, box, measure) {
    const spec = TREE_TEXT[node.level]
    const pad = TREE_PAD[node.level]
    const hl = node.highlight ? ' hl' : ''
    const x = box.x + pad + (node.level === 1 ? 6 : 0)
    let svg = ''

    if (node.highlight && node.level > 0) {
        svg += `<rect class="glow" x="${r1(box.x - 3)}" y="${r1(box.y - 3)}" width="${r1(box.w + 6)}" height="${r1(box.h + 6)}" rx="13"/>`
    }

    if (node.level === 0) {
        svg += `<rect class="node-root${hl}" x="${r1(box.x)}" y="${r1(box.y)}" width="${r1(box.w)}" height="${r1(box.h)}" rx="12"/>`
    } else {
        svg += `<rect class="card${hl}" x="${r1(box.x)}" y="${r1(box.y)}" width="${r1(box.w)}" height="${r1(box.h)}" rx="${node.level === 1 ? 10 : 8}"/>`
        // A branch carries a green edge on its left: the sections of the tree
        // read as sections at a glance.
        if (node.level === 1) svg += `<rect class="bar" x="${r1(box.x + 5)}" y="${r1(box.y + 9)}" width="3.5" height="${r1(box.h - 18)}" rx="1.75"/>`
    }

    const cls = node.level === 0 ? 'root' : String(node.level)
    let ty = box.y + pad + spec.label.size
    box.label.forEach((line) => {
        svg += `<text class="label-${cls}" x="${r1(x)}" y="${r1(ty)}">${esc(line)}</text>`
        ty += spec.label.line
    })
    if (box.detail.length) {
        ty += 5 - spec.label.line + spec.detail.line
        box.detail.forEach((line) => {
            svg += `<text class="detail-${cls}" x="${r1(x)}" y="${r1(ty)}">${esc(line)}</text>`
            ty += spec.detail.line
        })
    }

    if (node.highlight) svg += tag(box.x + box.w - 10, box.y, 'Destaque', measure)

    return svg
}

function renderTree(scene, width, measure) {
    const nodes = treeNodes((scene.nodes || []).filter((n) => (n.label || '').trim()))
    if (!nodes.length) return ''

    const {boxes, links, order, height} = layoutTree(nodes, width, measure)

    // The tree grows from the top: the root lands, then each link draws down
    // to its node and the node lands as the line arrives.
    const appear = []
    const windows = []
    let t = LEAD
    appear[0] = t
    t += CARD_IN
    order.slice(1).forEach((i) => {
        const d = nodes[i].level === 1 ? 0.42 : 0.3
        windows.push([i, t, t + d])
        appear[i] = t + d
        // Room after each line for the riding marker to fade before the next
        // one starts — its keyframes must stay in time order.
        t += d + 0.25
    })
    const drawnAt = t + CARD_IN
    const time = {drawnAt, holdUntil: drawnAt + 3, goneAt: drawnAt + 3 + FADE, cycle: drawnAt + 3 + FADE + BLANK}
    const f = figure(time)

    order.forEach((i) => f.body.push(`<g class="mk ${f.pop(appear[i])}">${treeNode(nodes[i], boxes[i], measure)}</g>`))
    f.arrows(windows.map(([i]) => links[i]), windows.map(([, start, end]) => [start, end]), {heads: false, flow: false})

    return f.svg(width, height, nodes.map((n) => '  '.repeat(n.level) + n.label).join('; '))
}

/* ------------------------------------------------------------ dispatch -- */

const RENDERERS = {
    steps: renderSteps,
    'before-after': renderBeforeAfter,
    tree: renderTree,
}

/** The whole figure as an <svg> string, for a container `width` pixels wide. */
export function renderScene(scene, width, fontFamily = 'system-ui, sans-serif') {
    const render = RENDERERS[scene?.type || 'steps']
    if (!render || width < 120) return ''

    return render(scene, width, measurer(fontFamily))
}

/* --------------------------------------------------------------- mount -- */

const mounted = new WeakMap()

const PAUSE_ICON = '<svg viewBox="0 0 16 16" aria-hidden="true"><rect x="4" y="3" width="3" height="10" rx="1"/><rect x="9" y="3" width="3" height="10" rx="1"/></svg>'
const PLAY_ICON = '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M5 3.6v8.8a.6.6 0 0 0 .92.5l6.8-4.4a.6.6 0 0 0 0-1L5.92 3.1A.6.6 0 0 0 5 3.6Z"/></svg>'

/**
 * Draws `scene` into `stage` and keeps it drawn for the stage's width: the
 * column count is a function of the width, so a resize is a re-layout rather
 * than a scale (scaling would shrink the text along with the cards). Calling
 * it again on the same stage swaps the scene — the editor's preview does that
 * on every edit — and the one observer keeps reading the CURRENT scene.
 *
 * Every figure carries its own pause button. A loop that runs for as long as
 * the page is open needs a way to stop it (WCAG 2.2.2), and somebody reading
 * the cards wants them still. Paused, the figure shows its FINISHED frame —
 * freezing mid-cycle would leave half the cards invisible — and resuming
 * starts the loop over. The state lives with the stage, so a re-layout on
 * resize (or an edit in the preview) keeps it.
 */
export function mountScene(stage, scene) {
    let state = mounted.get(stage)

    if (!state) {
        state = {width: -1, scene: null, paused: false, toggle: null}
        mounted.set(stage, state)

        if (typeof ResizeObserver !== 'undefined') {
            // Painting changes the stage's HEIGHT, which fires this again;
            // only a change of width is a reason to lay out anew.
            new ResizeObserver(() => paint(stage, state, false)).observe(stage)
        }
    }

    state.scene = scene
    paint(stage, state, true)
}

function paint(stage, state, force) {
    // The CONTENT width: `clientWidth` counts the padding, and the editor's
    // preview box has some — measuring it drew the figure 2rem wider than the
    // box and pushed the right-hand cards over its border.
    const style = getComputedStyle(stage)
    const width = stage.clientWidth - parseFloat(style.paddingLeft || 0) - parseFloat(style.paddingRight || 0)
    if (!force && Math.abs(state.width - width) < 2) return

    state.width = width
    stage.innerHTML = renderScene(state.scene, width, style.fontFamily)

    const svg = stage.querySelector('svg')
    if (!svg) return

    svg.classList.toggle('is-paused', state.paused)
    state.toggle ??= pauseToggle(stage, state)
    syncToggle(state.toggle, state.paused)
    stage.append(state.toggle)
}

function pauseToggle(stage, state) {
    const button = document.createElement('button')
    button.type = 'button'
    button.className = 'ak-scene-toggle'
    button.addEventListener('click', (e) => {
        // Inside the editor this sits in a block; the click is the button's,
        // not a gesture on the block.
        e.preventDefault()
        e.stopPropagation()
        state.paused = !state.paused
        // A repaint either way: pausing must also hide the SMIL dots, and
        // resuming should start the loop from the beginning, not mid-cycle.
        paint(stage, state, true)
        button.focus()
    })

    return button
}

function syncToggle(button, paused) {
    const label = paused ? 'Retomar animação' : 'Pausar animação'
    button.innerHTML = paused ? PLAY_ICON : PAUSE_ICON
    button.setAttribute('aria-label', label)
    button.setAttribute('aria-pressed', paused ? 'true' : 'false')
    button.title = label
}

/** Reader side: every figure GitbookRenderer emitted with its data. */
export function init() {
    document.querySelectorAll('figure[data-ak-scene]:not([data-ak-scene-ready])').forEach((figure) => {
        let scene
        try {
            scene = JSON.parse(figure.dataset.akScene || 'null')
        } catch {
            return
        }

        const stage = figure.querySelector('[data-ak-scene-stage]')
        if (!scene || !stage) return

        figure.dataset.akSceneReady = '1'
        mountScene(stage, scene)
        // The list stays in the document for screen readers, search and copy;
        // it is only taken off the screen once the picture is there.
        if (stage.firstChild) figure.classList.add('is-drawn')
    })
}
