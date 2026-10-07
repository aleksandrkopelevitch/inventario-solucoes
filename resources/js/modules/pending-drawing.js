/**
 * What the blank tab of a `data-ak-ajax-target="_blank"` call shows while the
 * request behind it runs — a looping drawing of the KIND of diagram being
 * made, rather than a line of grey text on an empty page for a whole minute.
 *
 * One scene per `data-ak-ajax-pending-kind` (the free graph plus the four
 * `App\Enums\DiagramModel` values). A scene is a list of marks, each with the
 * moment of the cycle it appears at; `render()` turns that list into an inline
 * SVG and one `@keyframes` per mark. Every mark shares the same duration and
 * starts at 0, so the loop stays in step forever — `animation-delay` would
 * only offset the FIRST cycle. A small pen hops from mark to mark as each one
 * is drawn, so the picture reads as being drawn rather than fading in.
 *
 * The tab is `about:blank` written with `document.write()`: nothing of the
 * app's CSS reaches it, so everything here is inline and self-contained. The
 * colors are the canvas's own (the "Original" theme in
 * `components/chain/viz.blade.php`) — what appears in this tab is what the tab
 * turns into.
 *
 * A kind this file does not know (or none) gets the label alone, which is
 * what the tab showed before any of this existed.
 */

const CYCLE_SECONDS = 7.5

// Percentages of the cycle: everything is on screen by ~70%, holds, then
// clears so the next pass starts on an empty canvas.
const HOLD_UNTIL = 86
const GONE_AT = 94

const C = {
    ground: '#F4F6F8',
    grid: '#DCE2E9',
    line: '#5A6675',
    node: '#FFFFFF',
    border: '#B9C2CE',
    ink: '#17212B',
    muted: '#5A6675',
    bar: '#D5DBE2',
    barSoft: '#E6EAEE',
    decision: '#F6C453',
    pill: '#17212B',
    start: '#22C55E',
    end: '#EF4444',
    lane: '#ECEFF3',
    pen: '#AADB1E',
    penInk: '#1B4D2E',
    blue: ['#DCE9FF', '#2F6FDB'],
    red: ['#FDE2E1', '#D03B35'],
    green: ['#DDF3E5', '#2E9C5C'],
    orange: ['#FFE6DA', '#D9531E'],
}

/* ---------------------------------------------------------------- marks -- */

/**
 * Each mark is `{ t, type, at, svg }`: `t` is when it appears (% of the
 * cycle), `type` how (pop / draw / grow / fade), `at` where the pen goes to
 * draw it.
 */

/** A white action block with a picture square and two lines of "text". */
function card(t, x, y, w, h, {tone = null, logo = C.green} = {}) {
    const [fill, stroke] = tone ? C[tone] : [C.node, C.border]
    const ly = y + h / 2

    return {
        t, type: 'pop', at: [x + w / 2, ly],
        svg: `<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="9" fill="${fill}" stroke="${stroke}" stroke-width="${tone ? 2 : 1.5}"/>`
            + `<rect x="${x + 12}" y="${ly - 11}" width="22" height="22" rx="6" fill="${logo[0]}" stroke="${logo[1]}" stroke-width="1.5"/>`
            + `<rect x="${x + 44}" y="${ly - 8}" width="${w - 62}" height="6" rx="3" fill="${C.bar}"/>`
            + `<rect x="${x + 44}" y="${ly + 3}" width="${(w - 62) * 0.6}" height="6" rx="3" fill="${C.barSoft}"/>`,
    }
}

/** A rounded state, as the lifecycle model draws one. */
function state(t, x, y, w, h, tone) {
    const [fill, stroke] = C[tone]

    return {
        t, type: 'pop', at: [x + w / 2, y + h / 2],
        svg: `<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="${h / 2}" fill="${fill}" stroke="${stroke}" stroke-width="2"/>`
            + `<rect x="${x + 20}" y="${y + h / 2 - 3}" width="${w - 40}" height="6" rx="3" fill="${stroke}" opacity=".35"/>`,
    }
}

function diamond(t, cx, cy, r) {
    return {
        t, type: 'pop', at: [cx, cy],
        svg: `<polygon points="${cx},${cy - r} ${cx + r},${cy} ${cx},${cy + r} ${cx - r},${cy}" fill="${C.decision}" stroke="${C.ink}" stroke-opacity=".15"/>`
            + `<rect x="${cx - r / 2}" y="${cy - 3}" width="${r}" height="6" rx="3" fill="${C.ink}" opacity=".3"/>`,
    }
}

function terminal(t, cx, cy, kind) {
    const svg = kind === 'start'
        ? `<circle cx="${cx}" cy="${cy}" r="11" fill="${C.start}"/>`
        : `<circle cx="${cx}" cy="${cy}" r="13" fill="none" stroke="${C.end}" stroke-width="2.5"/><circle cx="${cx}" cy="${cy}" r="7.5" fill="${C.end}"/>`

    return {t, type: 'pop', at: [cx, cy], svg}
}

/** A database: a body and the lid that makes it read as one. */
function cylinder(t, x, y, w, h, tone) {
    const [fill, stroke] = C[tone]
    const e = 10

    return {
        t, type: 'pop', at: [x + w / 2, y + h / 2],
        svg: `<path d="M${x} ${y + e} V${y + h - e} A${w / 2} ${e} 0 0 0 ${x + w} ${y + h - e} V${y + e}" fill="${C.node}" stroke="${stroke}" stroke-width="2"/>`
            + `<ellipse cx="${x + w / 2}" cy="${y + e}" rx="${w / 2}" ry="${e}" fill="${fill}" stroke="${stroke}" stroke-width="2"/>`
            + `<path d="M${x} ${y + e + 18} A${w / 2} ${e} 0 0 0 ${x + w} ${y + e + 18}" fill="none" stroke="${stroke}" stroke-width="1.5" opacity=".45"/>`,
    }
}

/**
 * An arrow along `points` ([[x, y], ...], 90° segments like the canvas), or
 * along a raw path `d` ending at `tip` heading `dir`. The line is drawn; its
 * head pops when the line arrives.
 */
function arrow(t, points, {dashed = false, d = null, tip = null, dir = null} = {}) {
    const path = d ?? 'M' + points.map(([x, y]) => `${x} ${y}`).join(' L')
    const end = tip ?? points[points.length - 1]
    let heading = dir

    if (!heading) {
        const [px, py] = points[points.length - 2]
        heading = Math.atan2(end[1] - py, end[0] - px)
    }

    const head = headAt(end, heading)
    const stroke = `stroke="${C.line}" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"`

    // A dashed line cannot also be drawn with the dash-offset trick (that IS
    // its dash array), so it fades in instead.
    return [
        dashed
            ? {t, type: 'fade', at: end, svg: `<path d="${path}" ${stroke} stroke-dasharray="6 5"/>`}
            : {t, type: 'draw', at: end, svg: `<path d="${path}" ${stroke} pathLength="1"/>`},
        {t: t + (dashed ? 3 : 6), type: 'pop', at: null, svg: head},
    ]
}

function headAt([x, y], angle) {
    const len = 9
    const spread = 0.45
    const a = [x - len * Math.cos(angle - spread), y - len * Math.sin(angle - spread)]
    const b = [x - len * Math.cos(angle + spread), y - len * Math.sin(angle + spread)]

    return `<polygon points="${x},${y} ${a[0].toFixed(1)},${a[1].toFixed(1)} ${b[0].toFixed(1)},${b[1].toFixed(1)}" fill="${C.line}"/>`
}

/* --------------------------------------------------------------- scenes -- */

const DOWN = Math.PI / 2
const UP = -Math.PI / 2

const SCENES = {
    // "Fluxo, no canvas" — systems of the catalog and what talks to what.
    graph: () => [
        card(2, 16, 100, 128, 60, {logo: C.green}),
        ...arrow(10, [[144, 130], [176, 130], [176, 60], [204, 60]]),
        card(16, 204, 30, 128, 60, {logo: C.blue}),
        ...arrow(22, [[144, 130], [176, 130], [176, 200], [204, 200]]),
        card(28, 204, 170, 128, 60, {logo: C.orange}),
        ...arrow(36, [[332, 60], [362, 60], [362, 130], [376, 130]]),
        ...arrow(42, [[332, 200], [362, 200], [362, 130], [376, 130]]),
        card(50, 376, 100, 128, 60, {logo: C.red}),
    ],

    // "Processo" — steps in lanes, a decision, an exception that loops back.
    workflow: () => [
        {t: 0, type: 'fade', at: null, svg: lanes()},
        terminal(4, 62, 72, 'start'),
        ...arrow(9, [[73, 72], [100, 72]]),
        card(14, 100, 44, 120, 56, {logo: C.blue}),
        ...arrow(21, [[220, 72], [262, 72]]),
        diamond(28, 290, 72, 28),
        ...arrow(35, [[318, 72], [454, 72]]),
        terminal(43, 470, 72, 'end'),
        ...arrow(48, [[290, 100], [290, 160]], {}),
        card(55, 228, 160, 124, 54, {tone: 'orange', logo: C.red}),
        ...arrow(62, [[228, 187], [160, 187], [160, 100]]),
    ],

    // "Sequência" — participants, lifelines, calls in order and their returns.
    sequence: () => {
        const xs = [96, 260, 424]
        const marks = []

        xs.forEach((x, i) => {
            marks.push(card(2 + i * 5, x - 62, 14, 124, 44, {logo: [C.blue, C.green, C.orange][i]}))
            marks.push({
                t: 6 + i * 5, type: 'grow', at: null,
                svg: `<line x1="${x}" y1="58" x2="${x}" y2="246" stroke="${C.border}" stroke-width="2" stroke-dasharray="5 5"/>`,
            })
        })

        const call = (t, from, to, y, dashed = false) => arrow(t, [[xs[from], y], [xs[to] + (to > from ? -6 : 6), y]], {dashed})

        return [
            ...marks,
            activation(24, xs[1], 88, 148),
            ...call(22, 0, 1, 88),
            activation(32, xs[2], 116, 116 + 30),
            ...call(30, 1, 2, 116),
            ...call(40, 2, 1, 146, true),
            ...call(48, 1, 0, 176, true),
            ...call(56, 0, 2, 214),
        ]
    },

    // "Ciclo de vida" — the states of a run, a retry, a failure and the end.
    lifecycle: () => [
        terminal(2, 24, 112, 'start'),
        ...arrow(6, [[35, 112], [52, 112]]),
        state(10, 52, 90, 108, 44, 'blue'),
        ...arrow(16, [[160, 112], [200, 112]]),
        state(21, 200, 90, 108, 44, 'orange'),
        ...arrow(27, [[308, 112], [348, 112]]),
        state(32, 348, 90, 108, 44, 'green'),
        ...arrow(38, [[456, 112], [479, 112]]),
        terminal(43, 492, 112, 'end'),
        ...arrow(48, null, {d: 'M402 90 C402 34 254 34 254 84', tip: [254, 86], dir: DOWN}),
        ...arrow(54, [[254, 134], [254, 182]]),
        state(59, 200, 182, 108, 44, 'red'),
        ...arrow(65, null, {d: 'M308 204 H492 V129', tip: [492, 127], dir: UP}),
    ],

    // "Fluxo de dados" — where data comes from, what transforms it, where it
    // rests; dots keep moving along the arrows once they are drawn.
    dataflow: () => {
        const paths = [
            'M114 64 H158 V116 H204',
            'M114 196 H158 V144 H204',
            'M326 130 H400',
        ]

        return [
            cylinder(2, 30, 30, 84, 70, 'blue'),
            cylinder(8, 30, 162, 84, 70, 'orange'),
            ...arrow(16, [[114, 64], [158, 64], [158, 116], [204, 116]]),
            ...arrow(22, [[114, 196], [158, 196], [158, 144], [204, 144]]),
            card(30, 204, 102, 122, 56, {tone: 'green', logo: C.green}),
            ...arrow(38, [[326, 130], [400, 130]]),
            cylinder(46, 404, 96, 88, 72, 'green'),
            {t: 54, type: 'fade', at: null, svg: dots(paths), className: 'motion'},
        ]
    },
}

/** The two swimlanes a process is drawn across — who does what. */
function lanes() {
    return [16, 134].map(y =>
        `<rect x="6" y="${y}" width="508" height="110" rx="10" fill="${C.lane}"/>`
        + `<rect x="18" y="${y + 12}" width="34" height="6" rx="3" fill="${C.bar}"/>`,
    ).join('')
}

/** The narrow bar on a lifeline while that participant is busy answering. */
function activation(t, x, y1, y2) {
    return {
        t, type: 'grow', at: null,
        svg: `<rect x="${x - 6}" y="${y1}" width="12" height="${y2 - y1}" rx="3" fill="${C.node}" stroke="${C.border}" stroke-width="1.5"/>`,
    }
}

/**
 * Data travelling: SMIL, so it needs no keyframes of its own. The begins are
 * NEGATIVE — already under way when the tab paints — because a dot whose
 * motion has not started yet sits at its own origin, the canvas's top-left
 * corner.
 */
function dots(paths) {
    return paths.map((d, i) => [0, 0.55].map(offset =>
        `<circle r="4" fill="${C.pen}" stroke="${C.penInk}" stroke-width="1.5">`
        + `<animateMotion dur="1.6s" begin="-${(i * 0.3 + offset * 1.6).toFixed(2)}s" repeatCount="indefinite" path="${d}"/></circle>`,
    ).join('')).join('')
}

/* --------------------------------------------------------------- render -- */

function render(kind) {
    const marks = SCENES[kind]?.()
    if (!marks) return ''

    const css = []
    const body = []

    marks.forEach((mark, i) => {
        const id = `m${i}`
        const t = mark.t
        const shown = Math.min(t + (mark.type === 'draw' ? 7 : 5), HOLD_UNTIL - 1)

        const [from, to] = {
            pop: ['opacity:0;transform:scale(.82)', 'opacity:1;transform:scale(1)'],
            fade: ['opacity:0', 'opacity:1'],
            grow: ['opacity:0;transform:scaleY(0)', 'opacity:1;transform:scaleY(1)'],
            draw: ['stroke-dashoffset:1', 'stroke-dashoffset:0'],
        }[mark.type]

        // A drawn line is hidden outright until its turn: at offset 1 its
        // round cap would still leave a dot where it starts.
        const reveal = mark.type === 'draw' ? `${t + 0.5}%{opacity:1}` : ''

        css.push(
            `@keyframes ${id}{0%,${t}%{${from};opacity:0}${reveal}${shown}%,${HOLD_UNTIL}%{${to};opacity:1}${GONE_AT}%,100%{${to};opacity:0}}`
            + `.scene .${id}{animation:${id} ${CYCLE_SECONDS}s cubic-bezier(.3,.7,.3,1) infinite}`
            + (mark.type === 'grow' ? `.scene .${id}{transform-origin:top center}` : ''),
        )

        // The draw trick lives on the path itself; the rest animate a group.
        const svg = mark.type === 'draw'
            ? mark.svg.replace('<path ', `<path class="mk ${id}" stroke-dasharray="1 1" `)
            : `<g class="mk ${id} ${mark.className ?? ''}">${mark.svg}</g>`

        body.push(svg)
    })

    css.push(penKeyframes(marks))

    return `<style>${css.join('')}</style>`
        + `<svg class="scene" viewBox="0 0 520 260" role="img" aria-hidden="true">`
        + `<defs><pattern id="grid" width="16" height="16" patternUnits="userSpaceOnUse"><circle cx="1" cy="1" r="1" fill="${C.grid}"/></pattern></defs>`
        + `<rect width="520" height="260" fill="url(#grid)"/>`
        + body.join('')
        + `<g class="pen"><circle r="9" fill="${C.pen}" opacity=".35"/><circle r="4.5" fill="${C.pen}" stroke="${C.penInk}" stroke-width="1.5"/></g>`
        + `</svg>`
}

/** The pen visits each mark at the moment it is drawn, then leaves with them. */
function penKeyframes(marks) {
    // It arrives as a block lands, or as a line reaches its head; two marks
    // finishing on the same frame keep the first.
    const stops = marks
        .filter(m => m.at)
        .map(m => [m.t + (m.type === 'draw' ? 7 : 3), m.at])
        .sort((a, b) => a[0] - b[0])
        .filter(([t], i, all) => i === 0 || t > all[i - 1][0])
    const last = stops[stops.length - 1]
    const at = ([x, y]) => `transform:translate(${x}px,${y}px)`

    const frames = [`0%{${at(stops[0][1])};opacity:0}`]
    stops.forEach(([t, p]) => frames.push(`${t}%{${at(p)};opacity:1}`))
    frames.push(`${Math.min(last[0] + 8, HOLD_UNTIL)}%{${at(last[1])};opacity:0}`, `100%{${at(last[1])};opacity:0}`)

    return `@keyframes pen{${frames.join('')}}`
        + `.pen{animation:pen ${CYCLE_SECONDS}s cubic-bezier(.5,0,.3,1) infinite}`
}

/* ----------------------------------------------------------------- page -- */

export function pendingTabHtml({label, hint = '', kind = ''}) {
    const scene = render(kind)

    return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">'
        + '<meta name="viewport" content="width=device-width,initial-scale=1">'
        + `<title>${escapeText(label)}</title>`
        + `<style>${PAGE_CSS}</style></head><body>`
        + '<main>'
        + (scene ? `<div class="board">${scene}</div>` : '')
        + `<p class="label">${escapeText(label.replace(/…$/, ''))}<span class="busy" aria-hidden="true"><i></i><i></i><i></i></span></p>`
        + (hint ? `<p class="hint">${escapeText(hint)}</p>` : '')
        + (scene ? '<p class="note">Pode levar até um minuto. Esta aba vira o diagrama assim que ele ficar pronto.</p>' : '')
        + '</main></body></html>'
}

const PAGE_CSS = `
*{box-sizing:border-box}
html,body{margin:0;min-height:100%}
body{display:grid;place-items:center;min-height:100vh;padding:24px 16px;background:${C.ground};color:${C.ink};
  font:400 14px/1.45 "IBM Plex Sans",Inter,system-ui,-apple-system,"Segoe UI",sans-serif;-webkit-font-smoothing:antialiased}
main{width:100%;max-width:560px;text-align:center}
.board{background:${C.ground};border:1px solid ${C.border};border-radius:16px;padding:12px;margin-bottom:22px;
  box-shadow:0 1px 2px rgba(23,33,43,.04),0 12px 32px -12px rgba(23,33,43,.14)}
.scene{display:block;width:100%;height:auto;overflow:visible}
.scene g,.scene path{transform-box:fill-box;transform-origin:center}
.label{margin:0;font-weight:600;font-size:16px;color:${C.ink}}
.hint{margin:6px 0 0;color:${C.muted}}
.note{margin:14px 0 0;font-size:12px;color:#8A96A6}
.busy{display:inline-flex;gap:3px;margin-left:6px;vertical-align:middle}
.busy i{width:5px;height:5px;border-radius:50%;background:${C.penInk};animation:busy 1.2s ease-in-out infinite}
.busy i:nth-child(2){animation-delay:.15s}
.busy i:nth-child(3){animation-delay:.3s}
@keyframes busy{0%,80%,100%{opacity:.25;transform:translateY(0)}40%{opacity:1;transform:translateY(-3px)}}
@media (prefers-reduced-motion:reduce){
  .scene .mk{animation:none!important;opacity:1!important;transform:none!important;stroke-dashoffset:0!important}
  .scene .pen,.scene .motion{display:none}
  .busy i{animation-duration:2.4s}
}`

function escapeText(value) {
    return String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
}
