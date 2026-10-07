// Canvas drawing primitives for the ecosystem map: color math, cached glow
// and orb sprites, the circle and the arrowhead.
//
// Adapted from the "Second Brain" workspace-graph visualizer by Jay E /
// RoboNuggets (`public/_flows2.js`), used under CC BY 4.0 — see the NOTICE
// file at the repo root. What is ours is the vocabulary it draws (a
// category's color family); the technique below is theirs.
//
// The one idea worth restating, because it is why this file exists at all:
// a radial gradient is expensive and a graph redraws every frame, so a glow
// is built ONCE per color into an offscreen canvas and blitted with
// `drawImage` afterwards. Building them per node per frame is what makes a
// canvas graph stutter at a few hundred nodes.

const glowCache = {}
const orbCache = {}

/** #abc → #aabbcc, so every helper below can slice fixed offsets. */
function norm(hex) {
    return hex.length === 4 ? '#' + hex[1] + hex[1] + hex[2] + hex[2] + hex[3] + hex[3] : hex
}

export function hexToRgba(hex, a) {
    const h = norm(hex)
    const r = parseInt(h.slice(1, 3), 16)
    const g = parseInt(h.slice(3, 5), 16)
    const b = parseInt(h.slice(5, 7), 16)

    return `rgba(${r},${g},${b},${a})`
}

/** Linear blend between two hexes — `t` 0 keeps `a`, 1 keeps `b`. */
export function mix(a, b, t) {
    const pa = [1, 3, 5].map((i) => parseInt(norm(a).slice(i, i + 2), 16))
    const pb = [1, 3, 5].map((i) => parseInt(norm(b).slice(i, i + 2), 16))

    return '#' + pa.map((v, i) => Math.round(v + (pb[i] - v) * t).toString(16).padStart(2, '0')).join('')
}

/** Soft halo behind a node. One 64px canvas per color, reused forever. */
export function glowSprite(color) {
    const key = norm(color)
    if (glowCache[key]) return glowCache[key]

    const size = 64
    const c = document.createElement('canvas')
    c.width = c.height = size
    const g = c.getContext('2d')
    const grd = g.createRadialGradient(size / 2, size / 2, 0, size / 2, size / 2, size / 2)
    grd.addColorStop(0, hexToRgba(key, 0.55))
    grd.addColorStop(0.4, hexToRgba(key, 0.22))
    grd.addColorStop(1, hexToRgba(key, 0))
    g.fillStyle = grd
    g.fillRect(0, 0, size, size)

    return (glowCache[key] = c)
}

/** Lit sphere — the light sits up and to the left, like every other one. */
export function orbSprite(color, litMix = 0.35) {
    const key = color + '|' + litMix
    if (orbCache[key]) return orbCache[key]

    const size = 48
    const c = document.createElement('canvas')
    c.width = c.height = size
    const g = c.getContext('2d')
    const grd = g.createRadialGradient(size * 0.38, size * 0.38, size * 0.05, size / 2, size / 2, size / 2)
    grd.addColorStop(0, mix(color, '#ffffff', litMix))
    grd.addColorStop(1, color)
    g.fillStyle = grd
    g.beginPath()
    g.arc(size / 2, size / 2, size / 2, 0, Math.PI * 2)
    g.fill()

    return (orbCache[key] = c)
}

// ---- shapes ----------------------------------------------------------------

export function circle(ctx, x, y, s) {
    ctx.beginPath()
    ctx.arc(x, y, s, 0, Math.PI * 2)
}

/** Filled arrowhead at `(x, y)`, pointing along `angle`. */
export function arrowHead(ctx, x, y, angle, size) {
    ctx.beginPath()
    ctx.moveTo(x, y)
    ctx.lineTo(x - Math.cos(angle - 0.4) * size, y - Math.sin(angle - 0.4) * size)
    ctx.lineTo(x - Math.cos(angle + 0.4) * size, y - Math.sin(angle + 0.4) * size)
    ctx.closePath()
    ctx.fill()
}
