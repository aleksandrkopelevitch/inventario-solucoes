// Camera, input and the render loop for a canvas graph — everything that is
// true of any such graph, with not one word about solutions or diagrams in
// it. The caller supplies the nodes and draws them; this owns the pixels
// underneath: device-pixel ratio, wheel zoom around the cursor, drag to pan,
// hit-testing, an eased fly-to and the animation frame.
//
// Adapted from the "Second Brain" visualizer by Jay E / RoboNuggets
// (`public/_core.js`: `initCanvas`, `resize`, `hitTest`, `loop`, `flyCam`),
// used under CC BY 4.0 — see the NOTICE file at the repo root.
//
// Two details are load-bearing and easy to lose:
//
// - **Zoom keeps the point under the cursor still.** It converts the cursor
//   to world space BEFORE changing the scale and pans by the difference
//   afterwards; scaling around the canvas centre instead is what makes a
//   graph feel like it is sliding away while you zoom into it.
// - **A drag is not a click.** A press that moves less than `DRAG_SLOP`
//   pixels still counts as a click, because a mouse always moves a little
//   and a graph where clicking sometimes does nothing reads as broken.

const MIN_SCALE = 0.12
const MAX_SCALE = 6
const DRAG_SLOP = 4
const FIT_PAD = 70

export function mountCanvas(canvas, delegate) {
    const ctx = canvas.getContext('2d')
    const view = { k: 1, x: 0, y: 0 }
    let width = 0
    let height = 0
    let dpr = 1
    let fly = null
    let pan = null
    let down = null
    let hover = null
    let tick = 0
    let running = true

    const w2s = (x, y) => [x * view.k + view.x, y * view.k + view.y]
    const s2w = (px, py) => [(px - view.x) / view.k, (py - view.y) / view.k]

    function resize() {
        const rect = canvas.getBoundingClientRect()
        dpr = Math.min(2, window.devicePixelRatio || 1)
        width = Math.max(1, Math.round(rect.width))
        height = Math.max(1, Math.round(rect.height))
        canvas.width = width * dpr
        canvas.height = height * dpr
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
    }

    function localPoint(event) {
        const rect = canvas.getBoundingClientRect()

        return [event.clientX - rect.left, event.clientY - rect.top]
    }

    function hitTest(px, py) {
        const [wx, wy] = s2w(px, py)
        let hit = null
        let best = Infinity

        for (const node of delegate.nodes()) {
            if (node.x == null) continue
            const r = delegate.hitRadius(node) + 6 / view.k
            const d = (node.x - wx) ** 2 + (node.y - wy) ** 2
            if (d < r * r && d < best) {
                best = d
                hit = node
            }
        }

        return hit
    }

    function flyTo(to, duration = 45) {
        fly = { from: { ...view }, to: { ...view, ...to }, t: 0, duration }
    }

    /** Frames the given world-space bounds, or the current nodes' own. */
    function fit(bounds, duration = 45) {
        const b = bounds ?? boundsOfNodes()
        if (!b) return

        const k = Math.max(
            MIN_SCALE,
            Math.min(2, Math.min((width - FIT_PAD * 2) / Math.max(1, b.w), (height - FIT_PAD * 2) / Math.max(1, b.h))),
        )

        flyTo({ k, x: width / 2 - (b.x + b.w / 2) * k, y: height / 2 - (b.y + b.h / 2) * k }, duration)
    }

    function boundsOfNodes() {
        let x0 = Infinity
        let y0 = Infinity
        let x1 = -Infinity
        let y1 = -Infinity

        for (const node of delegate.nodes()) {
            if (node.x == null) continue
            const r = delegate.hitRadius(node) + 18
            x0 = Math.min(x0, node.x - r)
            y0 = Math.min(y0, node.y - r)
            x1 = Math.max(x1, node.x + r)
            y1 = Math.max(y1, node.y + r)
        }

        return x0 === Infinity ? null : { x: x0, y: y0, w: x1 - x0, h: y1 - y0 }
    }

    function zoomBy(factor, atX, atY) {
        const px = atX ?? width / 2
        const py = atY ?? height / 2
        const [wx, wy] = s2w(px, py)
        fly = null
        view.k = Math.max(MIN_SCALE, Math.min(MAX_SCALE, view.k * factor))
        const [sx, sy] = w2s(wx, wy)
        view.x += px - sx
        view.y += py - sy
    }

    // ---- input ----
    const onWheel = (event) => {
        event.preventDefault()
        const [px, py] = localPoint(event)
        zoomBy(Math.exp(-event.deltaY * 0.0014), px, py)
    }

    const onPointerDown = (event) => {
        if (event.button !== 0) return
        const [px, py] = localPoint(event)
        const hit = hitTest(px, py)
        down = { px, py, hit, moved: false }
        if (!hit) pan = { px, py, x: view.x, y: view.y }
        canvas.style.cursor = hit ? 'pointer' : 'grabbing'
        canvas.setPointerCapture?.(event.pointerId)
    }

    const onPointerMove = (event) => {
        const [px, py] = localPoint(event)

        if (down && !down.moved && Math.hypot(px - down.px, py - down.py) > DRAG_SLOP) {
            down.moved = true
        }

        if (pan) {
            fly = null
            view.x = pan.x + (px - pan.px)
            view.y = pan.y + (py - pan.py)

            return
        }

        if (down?.hit && down.moved) {
            // Dragging a node parks it where it was dropped; the layout keeps
            // it there until something re-runs (an expand, or "Organizar").
            const [wx, wy] = s2w(px, py)
            down.hit.x = wx
            down.hit.y = wy
            down.hit.pinned = true
            fly = null

            return
        }

        const next = hitTest(px, py)
        if (next !== hover) {
            hover = next
            canvas.style.cursor = next ? 'pointer' : 'grab'
        }
        delegate.onHover?.(hover, event, { px, py })
    }

    const onPointerUp = (event) => {
        const [px, py] = localPoint(event)
        if (down && !down.moved) {
            delegate.onClick?.(down.hit, event, { px, py })
        }
        down = null
        pan = null
        canvas.style.cursor = hover ? 'pointer' : 'grab'
    }

    const onPointerLeave = () => {
        hover = null
        delegate.onHover?.(null)
        canvas.style.cursor = 'grab'
    }

    const onDoubleClick = (event) => {
        const [px, py] = localPoint(event)
        const hit = hitTest(px, py)
        if (hit) {
            hit.pinned = false
            delegate.onDoubleClick?.(hit)
        }
    }

    canvas.addEventListener('wheel', onWheel, { passive: false })
    canvas.addEventListener('pointerdown', onPointerDown)
    canvas.addEventListener('pointermove', onPointerMove)
    canvas.addEventListener('pointerup', onPointerUp)
    canvas.addEventListener('pointerleave', onPointerLeave)
    canvas.addEventListener('dblclick', onDoubleClick)

    const observer = new ResizeObserver(resize)
    observer.observe(canvas)
    resize()
    view.x = width / 2
    view.y = height / 2

    function frame() {
        if (!running) return
        tick++

        if (fly) {
            fly.t++
            const p = Math.min(1, fly.t / fly.duration)
            const e = 1 - Math.pow(1 - p, 3)
            view.k = fly.from.k + (fly.to.k - fly.from.k) * e
            view.x = fly.from.x + (fly.to.x - fly.from.x) * e
            view.y = fly.from.y + (fly.to.y - fly.from.y) * e
            if (p >= 1) fly = null
        }

        ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
        ctx.clearRect(0, 0, width, height)
        delegate.draw(ctx, { ...view, width, height, tick, hover, dpr }, api)

        requestAnimationFrame(frame)
    }

    const api = {
        view,
        w2s,
        s2w,
        fit,
        flyTo,
        zoomBy,
        hitTest,
        get hover() {
            return hover
        },
        get size() {
            return { width, height }
        },
        flyToNode(node, scale) {
            const k = scale ?? Math.max(1.2, view.k)
            flyTo({ k, x: width / 2 - node.x * k, y: height / 2 - node.y * k })
        },
        destroy() {
            running = false
            observer.disconnect()
            canvas.removeEventListener('wheel', onWheel)
            canvas.removeEventListener('pointerdown', onPointerDown)
            canvas.removeEventListener('pointermove', onPointerMove)
            canvas.removeEventListener('pointerup', onPointerUp)
            canvas.removeEventListener('pointerleave', onPointerLeave)
            canvas.removeEventListener('dblclick', onDoubleClick)
        },
    }

    requestAnimationFrame(frame)

    return api
}
