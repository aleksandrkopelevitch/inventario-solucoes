// docs-scene.js — draws an animated SCENE inside a documentation page. Today
// one type: the "fluxo em etapas" (`{% scene type="steps" %}`).
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

/** The whole figure as an <svg> string, for a container `width` pixels wide. */
export function renderSteps(scene, width, fontFamily = 'system-ui, sans-serif') {
    const steps = (scene?.steps || []).filter((s) => (s.title || '').trim() || (s.detail || '').trim())
    if (!steps.length || width < 120) return ''

    const uid = `aks${++sequence}`
    const {cards, links, height} = layout(steps, width, measurer(fontFamily))
    const time = timeline(cards.length)
    const pct = (s) => r1((s / time.cycle) * 100)
    const hold = pct(time.holdUntil)
    const gone = pct(time.goneAt)

    const css = []
    const body = []
    let mark = 0

    const keyframes = (from, to, start, end, extra = '') => {
        const id = `${uid}m${mark++}`
        css.push(
            `@keyframes ${id}{0%,${pct(start)}%{${from}}${extra}${pct(end)}%,${hold}%{${to}}${gone}%,100%{${to};opacity:0}}`
            + `.${uid} .${id}{animation:${id} ${r1(time.cycle)}s cubic-bezier(.3,.7,.3,1) infinite}`,
        )

        return id
    }

    cards.forEach((c, i) => {
        const id = keyframes('opacity:0;transform:scale(.86)', 'opacity:1;transform:scale(1)', time.cards[i], time.cards[i] + CARD_IN)
        body.push(`<g class="mk ${id}">${card(c)}</g>`)
    })

    links.forEach((points, i) => {
        const [start, end] = time.links[i]
        // The draw trick (`pathLength="1"` + dash offset); hidden outright
        // until its turn, since at offset 1 a round cap still leaves a dot.
        const line = keyframes('opacity:0;stroke-dashoffset:1', 'opacity:1;stroke-dashoffset:0', start, end, `${pct(start + 0.01)}%{opacity:1;stroke-dashoffset:1}`)
        body.unshift(`<path class="mk line ${line}" d="${pathOf(points)}" pathLength="1" stroke-dasharray="1 1"/>`)
        const tip = keyframes('opacity:0', 'opacity:1', end - 0.05, end + 0.05)
        body.push(`<g class="mk ${tip}">${head(points[points.length - 1], points[points.length - 2])}</g>`)
    })

    if (links.length) {
        const token = `${uid}tk`
        css.push(`@keyframes ${token}{${tokenKeyframes(links, time.links, pct)}}`
            + `.${uid} .${token}{animation:${token} ${r1(time.cycle)}s linear infinite}`)
        body.push(`<g class="fx ${token}"><circle class="halo" r="9"/><circle class="token" r="4.5"/></g>`)

        // Once everything is drawn, data keeps travelling along the arrows.
        // SMIL, with NEGATIVE begins so the dots are already under way: a dot
        // whose motion has not started sits at the svg's top-left corner.
        const flow = keyframes('opacity:0', 'opacity:1', time.drawnAt, time.drawnAt + 0.4)
        const dots = links.map((points, i) => [0, 0.5].map((offset) =>
            `<circle class="token" r="3.5"><animateMotion dur="1.4s" begin="-${r1(i * 0.27 + offset * 1.4)}s" repeatCount="indefinite" path="${pathOf(points)}"/></circle>`,
        ).join('')).join('')
        body.push(`<g class="fx ${flow}">${dots}</g>`)
    }

    const u = `.${uid}`
    const style = [
        `${u}{display:block;overflow:visible;font-family:inherit}`,
        `${u} g,${u} path{transform-box:fill-box;transform-origin:center}`,
        `${u} .card{fill:var(--color-surface,#fff);stroke:var(--color-line-2,#cbd6cd);stroke-width:1.5}`,
        `${u} .card.hl{stroke:var(--color-lime,#aadb1e);stroke-width:2.5}`,
        `${u} .glow{fill:var(--color-lime-soft,#f3f9db)}`,
        `${u} .badge{fill:var(--color-accent,#1b4d2e)}`,
        `${u} .badge.hl{fill:var(--color-lime,#aadb1e)}`,
        `${u} .num{fill:#fff;font-size:12px;font-weight:700}`,
        `${u} .num.hl{fill:var(--color-lime-ink,#3f5708)}`,
        `${u} .tag{fill:var(--color-lime-soft,#f3f9db);stroke:var(--color-lime-line,#e2efb0)}`,
        `${u} .tag-text{fill:var(--color-lime-ink,#3f5708);font-size:10.5px;font-weight:700}`,
        `${u} .title{fill:var(--color-ink,#0b0d10);font-size:${TITLE.size}px;font-weight:${TITLE.weight}}`,
        `${u} .detail{fill:var(--color-muted,#5c7563);font-size:${DETAIL.size}px}`,
        `${u} .line{fill:none;stroke:var(--color-muted,#5c7563);stroke-opacity:.55;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}`,
        `${u} .head{fill:var(--color-muted,#5c7563);fill-opacity:.75}`,
        `${u} .token{fill:var(--color-lime,#aadb1e);stroke:var(--color-accent,#1b4d2e);stroke-width:1.5}`,
        `${u} .halo{fill:var(--color-lime,#aadb1e);opacity:.35}`,
        ...css,
        `@media (prefers-reduced-motion:reduce){${u} .mk{animation:none!important;opacity:1!important;transform:none!important;stroke-dashoffset:0!important}${u} .fx{display:none!important}}`,
    ].join('')

    const label = steps.map((s, i) => `${i + 1}. ${s.title}`).join('; ')

    return `<svg class="${uid}" xmlns="http://www.w3.org/2000/svg" width="${r1(width)}" height="${r1(height)}" viewBox="0 0 ${r1(width)} ${r1(height)}" role="img" aria-label="${esc(label)}">`
        + `<style>${style}</style>${body.join('')}</svg>`
}

/* --------------------------------------------------------------- mount -- */

const mounted = new WeakMap()

/**
 * Draws `scene` into `stage` and keeps it drawn for the stage's width: the
 * column count is a function of the width, so a resize is a re-layout rather
 * than a scale (scaling would shrink the text along with the cards). Calling
 * it again on the same stage swaps the scene — the editor's preview does that
 * on every edit — and the one observer keeps reading the CURRENT scene.
 */
export function mountScene(stage, scene) {
    let state = mounted.get(stage)

    if (!state) {
        state = {width: -1, scene: null}
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
    const width = stage.clientWidth
    if (!force && Math.abs(state.width - width) < 2) return

    state.width = width
    stage.innerHTML = renderSteps(state.scene, width, getComputedStyle(stage).fontFamily)
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
