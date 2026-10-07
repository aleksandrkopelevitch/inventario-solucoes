// Ecosystem map — a canvas picture of every system and who talks to whom.
//
// Always at SOLUTION level (2026-10-07): one block per system, one arrow per
// PAIR of systems. The arrow carries the direction of every flow between the
// two, across every diagram — ida, volta or ambos — so two systems that meet
// in five drawings still get a single line. The drill-down that used to unfold
// a system into its diagrams and a diagram into its chain is gone: too much
// detail for a picture of the whole ecosystem. A click opens a card instead.
//
// Two views, picked in the page's bar (`[data-ak-map-view]`):
//
//   - "Por ligações" (default): the systems on concentric rings (or a force
//     simulation), most connected in the middle.
//   - "Por hospedagem": one coloured container per cloud / hosting model
//     (`DiagramGraphService::hostingOf()`), its look set on the attributes
//     screen, with the systems inside. Its arrows run between the CONTAINERS
//     by default — one per link the systems have — or between the systems,
//     toggled by `[data-ak-map-arrows-mode]`. A link between two systems of the
//     same container is always drawn between the systems: there is no box to
//     leave.
//
// The search box finds a system and marks it with an orange ring until Esc,
// centring the camera on it.
//
// The renderer is adapted from the "Second Brain" workspace-graph visualizer
// by Jay E / RoboNuggets, used under CC BY 4.0 — see NOTICE at the repo root.
// `graph-canvas/` holds the parts of it that know nothing about this app.

import { fold } from './fold.js'
import { mountCanvas } from './graph-canvas/camera.js'
import { buildSimulation, ringsLayout } from './graph-canvas/layout.js'
import { arrowHead, circle, glowSprite, hexToRgba, mix, orbSprite } from './graph-canvas/sprites.js'

const mounted = new WeakSet()

const SOLUTION_R = 26
const EASE = 0.14
const SEARCH_ORANGE = '#ff8a1f'

// Hosting view geometry, in world px.
const CELL = 150          // one system's slot inside a container
const BOX_PAD = 34        // inner padding of a container
const BOX_HEADER = 46     // the container's title strip
const BOX_GAP = 170       // space between containers — room for the arrows
const ARROW_SPREAD = 26   // distance between parallel arrows of one container pair

export function init() {
    document.querySelectorAll('[data-ak-ecosystem-map]').forEach((shell) => {
        if (mounted.has(shell)) return
        mounted.add(shell)
        mount(shell)
    })
}

function mount(shell) {
    const canvas = shell.querySelector('[data-ak-map-canvas]')
    const tip = shell.querySelector('[data-ak-map-tip]')
    const card = shell.querySelector('[data-ak-map-card]')
    const status = shell.querySelector('[data-ak-map-status]')
    const searchInput = shell.querySelector('[data-ak-map-search]')
    const results = shell.querySelector('[data-ak-map-results]')
    const linksOnly = shell.querySelector('[data-ak-map-links-only]')

    // The view selector and the arrows toggle live in the PAGE's bar, outside
    // the shell — one map per page, so the document is the right scope.
    const viewSelect = document.querySelector('[data-ak-map-view]')
    const arrowsToggle = document.querySelector('[data-ak-map-arrows]')

    const palette = readPalette()

    const state = {
        baseUrl: shell.dataset.akMapUrl,
        view: 'links',
        arrows: 'hosting',
        payloads: {},
        payload: null,
        nodes: [],
        nodeById: new Map(),
        links: [],
        containers: [],
        containerById: new Map(),
        selected: null,
        highlighted: null,
        hoverLink: null,
        layout: 'rings',
        orbit: false,
        spin: 0,
        sim: null,
    }

    const view = mountCanvas(canvas, {
        nodes: () => state.nodes,
        hitRadius: (node) => node.radius,
        draw: (ctx, frame, api) => draw(ctx, frame, api),
        onHover: (node, event, point) => onHover(node, event, point),
        onClick: (node, event, point) => onClick(node, event, point),
        onDoubleClick: (node) => centerOn(node, 1.4),
    })

    load()

    // ---- data ----------------------------------------------------------

    /**
     * One payload per view, fetched once: the hosting view asks for the whole
     * catalog (`?view=hosting`), the links view only for systems some diagram
     * connects.
     */
    async function load() {
        if (! state.payloads[state.view]) {
            setStatus('Carregando…')
            const url = state.view === 'hosting' ? `${state.baseUrl}?view=hosting` : state.baseUrl
            const response = await fetch(url, { headers: { Accept: 'application/json' } })
            if (! response.ok) {
                setStatus('Não foi possível carregar o mapa.')

                return
            }

            const payload = await response.json()
            state.payloads[state.view] = {
                nodes: payload.nodes ?? [],
                edges: payload.edges ?? [],
                hostings: payload.hostings ?? [],
            }
        }

        state.payload = state.payloads[state.view]
        state.nodes = []
        state.nodeById = new Map()
        select(null)
        rebuild()
        if (state.highlighted && state.nodeById.has(state.highlighted)) {
            centerOn(state.nodeById.get(state.highlighted), 1)
        } else {
            fitAll()
        }
    }

    /** Rebuilds nodes, links and containers from the payload of the current view. */
    function rebuild() {
        const previous = state.nodeById
        const byId = new Map()

        state.nodes = state.payload.nodes.map((solution) => {
            const node = Object.assign(previous.get(solution.id) ?? { x: 0, y: 0 }, {
                id: solution.id,
                type: 'solution',
                label: solution.label,
                radius: SOLUTION_R,
                color: palette.family(solution.categoryFamily),
                data: solution,
                hosting: solution.hosting,
            })
            node.logo ??= logoFor(solution.logo)
            byId.set(node.id, node)

            return node
        })
        state.nodeById = byId

        state.links = state.payload.edges
            .filter((edge) => byId.has(edge.source) && byId.has(edge.target))
            .map((edge) => ({
                ...edge,
                kind: 'pair',
                sn: byId.get(edge.source),
                tn: byId.get(edge.target),
                // `unidirectional` always runs source → target: the server
                // orients every pair by the first flow it saw.
                forward: true,
                backward: edge.direction === 'bidirectional',
            }))

        state.containers = state.view === 'hosting'
            ? state.payload.hostings.map((hosting) => ({
                ...hosting,
                image: hosting.image ? logoFor(hosting.image) : null,
                members: state.nodes.filter((n) => n.hosting === hosting.id),
            })).filter((c) => c.members.length)
            : []
        state.containerById = new Map(state.containers.map((c) => [c.id, c]))

        relayout()

        const linkCount = state.links.length
        setStatus(state.view === 'hosting'
            ? `${state.nodes.length} sistemas em ${state.containers.length} hospedagens · ${linkCount} ligações`
            : `${state.nodes.length} sistemas · ${linkCount} ligações`)
    }

    function relayout() {
        state.sim?.stop()
        state.sim = null

        if (state.view === 'hosting') {
            hostingLayout(state.containers)

            return
        }

        if (state.layout === 'force') {
            state.sim = buildSimulation(state.nodes, state.links.map((l) => ({ ...l })), () => {})
            state.sim.alpha(0.9).restart()

            return
        }

        ringsLayout(state.nodes, state.links)
    }

    /**
     * Containers as a grid of boxes, each sized to hold its systems in a
     * square-ish grid of cells. Largest first, wrapped into rows about as wide
     * as the whole thing is tall, so the picture fits a screen rather than a
     * corridor.
     */
    function hostingLayout(containers) {
        containers.sort((a, b) => b.members.length - a.members.length || a.label.localeCompare(b.label))

        for (const box of containers) {
            const cols = Math.max(1, Math.ceil(Math.sqrt(box.members.length * 1.4)))
            const rows = Math.ceil(box.members.length / cols)
            box.cols = cols
            box.w = Math.max(cols * CELL, 240) + BOX_PAD * 2
            box.h = rows * CELL + BOX_PAD * 2 + BOX_HEADER
        }

        const area = containers.reduce((sum, b) => sum + (b.w + BOX_GAP) * (b.h + BOX_GAP), 0)
        const rowWidth = Math.max(Math.sqrt(area) * 1.35, Math.max(0, ...containers.map((b) => b.w)))

        let x = 0
        let y = 0
        let rowHeight = 0
        for (const box of containers) {
            if (x > 0 && x + box.w > rowWidth) {
                x = 0
                y += rowHeight + BOX_GAP
                rowHeight = 0
            }
            box.x = x
            box.y = y
            x += box.w + BOX_GAP
            rowHeight = Math.max(rowHeight, box.h)
        }

        // Centre the whole arrangement on the origin, like the rings are.
        const width = Math.max(...containers.map((b) => b.x + b.w), 0)
        const height = Math.max(...containers.map((b) => b.y + b.h), 0)
        for (const box of containers) {
            box.x -= width / 2
            box.y -= height / 2
            box.cx = box.x + box.w / 2
            box.cy = box.y + box.h / 2

            const sorted = [...box.members].sort((a, b) => a.label.localeCompare(b.label))
            const usedCols = Math.min(box.cols, sorted.length)
            const offset = (box.w - BOX_PAD * 2 - usedCols * CELL) / 2
            sorted.forEach((node, i) => {
                node.tx = box.x + BOX_PAD + offset + (i % box.cols) * CELL + CELL / 2
                node.ty = box.y + BOX_HEADER + BOX_PAD + Math.floor(i / box.cols) * CELL + CELL / 2 - 10
            })
        }
    }

    /**
     * The arrows actually drawn. Between systems, by default; on the hosting
     * view with "Setas por hospedagem", a link between two DIFFERENT
     * containers becomes an arrow between the containers instead — still one
     * per link, fanned out side by side so parallel ones never overlap.
     */
    function drawnLinks() {
        if (state.view !== 'hosting' || state.arrows !== 'hosting') {
            return state.links.map((link) => ({ ...link, from: link.sn, to: link.tn, between: 'solutions' }))
        }

        const perPair = new Map()
        const drawn = []

        for (const link of state.links) {
            const a = state.containerById.get(link.sn.hosting)
            const b = state.containerById.get(link.tn.hosting)
            if (! a || ! b || a === b) {
                drawn.push({ ...link, from: link.sn, to: link.tn, between: 'solutions' })
                continue
            }

            const key = [a.id, b.id].sort().join('|')
            if (! perPair.has(key)) perPair.set(key, [])
            const item = { ...link, from: a, to: b, between: 'containers' }
            perPair.get(key).push(item)
            drawn.push(item)
        }

        for (const items of perPair.values()) {
            items.forEach((item, i) => {
                // Signed against a canonical orientation, so A→B and B→A of
                // the same pair fan out to opposite sides instead of piling up.
                const flip = item.from.id > item.to.id ? -1 : 1
                item.offset = (i - (items.length - 1) / 2) * ARROW_SPREAD * flip
            })
        }

        return drawn
    }

    // ---- geometry ------------------------------------------------------

    /** Centre of whatever a drawn link connects (a block or a container). */
    function centreOf(end) {
        return end.type === 'solution' ? [end.x, end.y] : [end.cx, end.cy]
    }

    /**
     * The two points a drawn link runs between: off each block's edge, or on
     * each container's border, shifted sideways by `offset` (parallel arrows).
     */
    function endpoints(link) {
        const [ax, ay] = centreOf(link.from)
        const [bx, by] = centreOf(link.to)
        const angle = Math.atan2(by - ay, bx - ax)
        const nx = -Math.sin(angle) * (link.offset ?? 0)
        const ny = Math.cos(angle) * (link.offset ?? 0)

        const trim = (end, cx, cy, dir) => {
            if (end.type === 'solution') {
                const gap = end.radius + 6

                return [cx + Math.cos(angle) * gap * dir, cy + Math.sin(angle) * gap * dir]
            }

            // Ray from the centre to the border of the rectangle.
            const dx = Math.cos(angle) * dir
            const dy = Math.sin(angle) * dir
            const tx = dx ? (end.w / 2) / Math.abs(dx) : Infinity
            const ty = dy ? (end.h / 2) / Math.abs(dy) : Infinity
            const t = Math.min(tx, ty) + 4

            return [cx + dx * t, cy + dy * t]
        }

        const [sx, sy] = trim(link.from, ax + nx, ay + ny, 1)
        const [ex, ey] = trim(link.to, bx + nx, by + ny, -1)

        return { sx, sy, ex, ey, angle }
    }

    /** Distance from a world point to a drawn link, for hover and click. */
    function distanceTo(link, wx, wy) {
        const { sx, sy, ex, ey } = endpoints(link)
        const dx = ex - sx
        const dy = ey - sy
        const len2 = dx * dx + dy * dy || 1
        const t = Math.max(0, Math.min(1, ((wx - sx) * dx + (wy - sy) * dy) / len2))

        return Math.hypot(wx - (sx + t * dx), wy - (sy + t * dy))
    }

    // ---- interaction ----------------------------------------------------

    function onClick(node, event, point) {
        tip.hidden = true

        if (node) {
            select(node)

            return
        }

        const link = linkAt(point)
        if (link) {
            select({ type: 'link', link })

            return
        }

        const box = containerAt(point)
        if (box) {
            select({ type: 'container', box })
            focusBox(box)

            return
        }

        state.highlighted = null
        select(null)
    }

    function onHover(node, event, point) {
        state.hoverLink = node ? null : linkAt(point)
        const box = node || state.hoverLink ? null : containerAt(point)

        const html = node ? tipForNode(node) : state.hoverLink ? tipForLink(state.hoverLink) : box ? tipForBox(box) : null
        if (! html || ! point) {
            tip.hidden = true

            return
        }

        tip.innerHTML = html
        tip.hidden = false
        const bounds = canvas.getBoundingClientRect()
        tip.style.left = `${Math.min(point.px + 16, bounds.width - tip.offsetWidth - 12)}px`
        tip.style.top = `${Math.min(point.py + 16, bounds.height - tip.offsetHeight - 12)}px`
    }

    function linkAt(point) {
        if (! point) return null
        const [wx, wy] = view.s2w(point.px, point.py)
        const tolerance = 9 / view.view.k
        let best = null
        let bestDistance = tolerance

        for (const link of drawnLinks()) {
            const d = distanceTo(link, wx, wy)
            if (d < bestDistance) {
                bestDistance = d
                best = link
            }
        }

        return best
    }

    function containerAt(point) {
        if (! point || ! state.containers.length) return null
        const [wx, wy] = view.s2w(point.px, point.py)

        return state.containers.find((b) => wx >= b.x && wx <= b.x + b.w && wy >= b.y && wy <= b.y + b.h) ?? null
    }

    /**
     * Frames the picture by where the blocks are GOING, never by where they
     * are: every block eases toward its target, so live coordinates right
     * after a layout describe a picture that has not been drawn yet.
     */
    function fitAll() {
        const boxes = state.containers.length
            ? state.containers.map((b) => [b.x, b.y, b.x + b.w, b.y + b.h])
            : state.nodes.filter((n) => n.tx != null).map((n) => {
                const [x, y] = targetOf(n)

                return [x - 70, y - 70, x + 70, y + 70]
            })
        if (! boxes.length) return

        const x0 = Math.min(...boxes.map((b) => b[0]))
        const y0 = Math.min(...boxes.map((b) => b[1]))
        const x1 = Math.max(...boxes.map((b) => b[2]))
        const y1 = Math.max(...boxes.map((b) => b[3]))
        view.fit({ x: x0, y: y0, w: x1 - x0, h: y1 - y0 })
    }

    function focusBox(box) {
        view.fit({ x: box.x - 60, y: box.y - 60, w: box.w + 120, h: box.h + 120 })
    }

    /** Puts `node` in the middle of the screen, at its destination. */
    function centerOn(node, scale) {
        const [x, y] = targetOf(node)
        const k = scale ?? Math.max(1, view.view.k)
        const { width, height } = view.size
        view.flyTo({ k, x: width / 2 - x * k, y: height / 2 - y * k })
    }

    /**
     * Where a node is HEADED, in the world the camera sees — the layout's
     * target, turned by however far the orbit has spun.
     */
    function targetOf(node) {
        const tx = node.tx ?? node.x
        const ty = node.ty ?? node.y
        if (! state.orbit || state.view !== 'links' || state.layout !== 'rings') return [tx, ty]

        const r = Math.hypot(tx, ty)
        const a = Math.atan2(ty, tx) + state.spin

        return [Math.cos(a) * r, Math.sin(a) * r]
    }

    function select(subject) {
        state.selected = subject
        if (! subject) {
            card.hidden = true

            return
        }
        card.innerHTML = cardFor(subject)
        card.hidden = false
    }

    // ---- drawing --------------------------------------------------------

    function draw(ctx, frame, api) {
        const { width, height, tick } = frame

        ctx.save()
        drawBackdrop(ctx, width, height)

        if (state.orbit && state.view === 'links') state.spin += 0.00035

        for (const node of state.nodes) {
            if (node.tx == null || (state.view === 'links' && state.layout === 'force')) continue
            const [tx, ty] = targetOf(node)
            node.x += (tx - node.x) * EASE
            node.y += (ty - node.y) * EASE
        }

        ctx.translate(api.view.x, api.view.y)
        ctx.scale(api.view.k, api.view.k)

        const focus = focusSet()
        const k = api.view.k

        for (const box of state.containers) drawContainer(ctx, box, k, focus)
        for (const link of drawnLinks()) drawLink(ctx, link, k, focus)
        for (const node of state.nodes) drawNode(ctx, node, k, focus, tick)

        ctx.restore()
        drawContainerTitles(ctx, api)
        drawLabels(ctx, api, focus)
    }

    function drawBackdrop(ctx, width, height) {
        const grd = ctx.createLinearGradient(0, 0, 0, height)
        grd.addColorStop(0, '#12161a')
        grd.addColorStop(1, '#070a0c')
        ctx.fillStyle = grd
        ctx.fillRect(0, 0, width, height)

        // A faint hex weave, the reference's own backdrop — it gives the pan
        // gesture something to move against, which an empty field does not.
        const size = 26
        const hs = size * Math.sqrt(3)
        const vs = size * 1.5
        const cx = width / 2
        const cy = height / 2
        const maxD = Math.hypot(cx, cy)
        ctx.strokeStyle = 'rgba(170,219,30,0.05)'
        ctx.lineWidth = 1

        for (let row = -1; row < height / vs + 2; row++) {
            for (let col = -1; col < width / hs + 2; col++) {
                const hx = col * hs + (row % 2 ? hs / 2 : 0)
                const hy = row * vs
                const fade = Math.max(0, 1 - (Math.hypot(hx - cx, hy - cy) / maxD) * 0.85)
                if (fade < 0.12) continue
                ctx.globalAlpha = fade
                ctx.beginPath()
                for (let i = 0; i < 6; i++) {
                    const a = (Math.PI / 3) * i - Math.PI / 6
                    const px = hx + size * Math.cos(a)
                    const py = hy + size * Math.sin(a)
                    i === 0 ? ctx.moveTo(px, py) : ctx.lineTo(px, py)
                }
                ctx.closePath()
                ctx.stroke()
            }
        }
        ctx.globalAlpha = 1
    }

    /**
     * What stays at full strength while a system or a link is selected: the
     * subject and its direct neighbours. null when nothing is — then the whole
     * picture is lit.
     */
    function focusSet() {
        const subject = state.selected
        if (! subject || subject.type === 'container') return null

        const ids = new Set()
        if (subject.type === 'link') {
            ids.add(subject.link.source)
            ids.add(subject.link.target)

            return ids
        }

        ids.add(subject.id)
        for (const link of state.links) {
            if (link.source === subject.id) ids.add(link.target)
            if (link.target === subject.id) ids.add(link.source)
        }

        return ids
    }

    function isLit(link, focus) {
        if (state.hoverLink?.id === link.id) return true
        if (state.selected?.type === 'link') return state.selected.link.id === link.id

        return focus ? focus.has(link.source) && focus.has(link.target) : false
    }

    function drawContainer(ctx, box, k, focus) {
        const dim = focus && ! box.members.some((m) => focus.has(m.id))
        const selected = state.selected?.type === 'container' && state.selected.box.id === box.id

        ctx.globalAlpha = dim ? 0.35 : 1
        roundRectPath(ctx, box.x, box.y, box.w, box.h, 22)
        ctx.fillStyle = hexToRgba(box.color, 0.16)
        ctx.fill()
        ctx.strokeStyle = hexToRgba(box.color, selected ? 1 : 0.7)
        ctx.lineWidth = (selected ? 3 : 1.6) / k
        ctx.stroke()

        // The title strip, a shade stronger than the body.
        ctx.save()
        roundRectPath(ctx, box.x, box.y, box.w, box.h, 22)
        ctx.clip()
        ctx.fillStyle = hexToRgba(box.color, 0.22)
        ctx.fillRect(box.x, box.y, box.w, BOX_HEADER)
        ctx.restore()
        ctx.globalAlpha = 1
    }

    /** Container titles and pictures, in screen space so they stay crisp. */
    function drawContainerTitles(ctx, api) {
        const k = api.view.k
        for (const box of state.containers) {
            const [sx, sy] = api.w2s(box.x, box.y)
            const headerPx = BOX_HEADER * k
            if (headerPx < 14) continue

            const icon = Math.min(26, headerPx * 0.62)
            let textX = sx + 16 * Math.min(1, k * 1.4)
            const midY = sy + headerPx / 2

            if (box.image?.complete && box.image.naturalWidth) {
                ctx.fillStyle = 'rgba(255,255,255,0.92)'
                roundRectPath(ctx, textX, midY - icon / 2, icon, icon, 5)
                ctx.fill()
                ctx.save()
                roundRectPath(ctx, textX + 2, midY - icon / 2 + 2, icon - 4, icon - 4, 4)
                ctx.clip()
                ctx.drawImage(box.image, textX + 2, midY - icon / 2 + 2, icon - 4, icon - 4)
                ctx.restore()
                textX += icon + 8
            }

            ctx.font = `700 ${Math.round(Math.min(15, Math.max(10, headerPx * 0.34)))}px Inter, system-ui, sans-serif`
            ctx.textAlign = 'left'
            ctx.textBaseline = 'middle'
            ctx.fillStyle = '#ffffff'
            ctx.fillText(box.label.toUpperCase(), textX, midY)
            ctx.font = `500 ${Math.round(Math.min(12, Math.max(9, headerPx * 0.26)))}px Inter, system-ui, sans-serif`
            ctx.fillStyle = 'rgba(255,255,255,0.55)'
            const labelWidth = ctx.measureText(box.label.toUpperCase()).width
            ctx.fillText(`· ${box.members.length} sistema${box.members.length === 1 ? '' : 's'}`, textX + labelWidth * 1.12 + 8, midY)
            ctx.textBaseline = 'alphabetic'
        }
    }

    function drawLink(ctx, link, k, focus) {
        const lit = isLit(link, focus)
        const dim = (focus || state.selected?.type === 'link') && ! lit
        const { sx, sy, ex, ey, angle } = endpoints(link)

        const fromColor = link.from.color
        const toColor = link.to.color
        ctx.globalAlpha = dim ? 0.12 : 1

        const grd = ctx.createLinearGradient(sx, sy, ex, ey)
        grd.addColorStop(0, hexToRgba(fromColor, lit ? 0.95 : 0.55))
        grd.addColorStop(1, hexToRgba(toColor, lit ? 0.95 : 0.55))
        ctx.strokeStyle = grd
        ctx.lineWidth = (lit ? 2.6 : 1.6) / k

        ctx.beginPath()
        ctx.moveTo(sx, sy)
        ctx.lineTo(ex, ey)
        ctx.stroke()

        const size = Math.max(7, 9 / k)
        if (link.forward) {
            ctx.fillStyle = hexToRgba(toColor, 0.95)
            arrowHead(ctx, ex, ey, angle, size)
        }
        if (link.backward) {
            ctx.fillStyle = hexToRgba(fromColor, 0.95)
            arrowHead(ctx, sx, sy, angle + Math.PI, size)
        }

        ctx.globalAlpha = 1
    }

    function drawNode(ctx, node, k, focus, tick) {
        if (node.x == null) return
        const dim = focus && ! focus.has(node.id)
        const alpha = dim ? 0.22 : 1
        const r = node.radius

        const glowR = r * 2.5
        ctx.globalAlpha = alpha * 0.55
        ctx.drawImage(glowSprite(node.color), node.x - glowR, node.y - glowR, glowR * 2, glowR * 2)
        ctx.globalAlpha = alpha

        ctx.drawImage(orbSprite(node.color, 0.42), node.x - r, node.y - r, r * 2, r * 2)
        ctx.strokeStyle = 'rgba(8,10,12,0.85)'
        ctx.lineWidth = 2 / k + 0.4
        circle(ctx, node.x, node.y, r)
        ctx.stroke()

        if (node.logo?.complete && node.logo.naturalWidth) {
            const s = r * 1.05
            ctx.save()
            circle(ctx, node.x, node.y, r - 3)
            ctx.clip()
            ctx.drawImage(node.logo, node.x - s, node.y - s, s * 2, s * 2)
            ctx.restore()
        }

        if (state.selected?.id === node.id) {
            ctx.strokeStyle = hexToRgba(palette.lime, 0.9)
            ctx.lineWidth = 2 / k
            circle(ctx, node.x, node.y, r + 7 + Math.sin(tick * 0.08) * 1.5)
            ctx.stroke()
        }

        // The search result: a thick orange ring that pulses outward, until
        // Esc or a click on empty space. Drawn at full strength even when the
        // rest is dimmed — it is the thing being looked for.
        if (state.highlighted === node.id) {
            ctx.globalAlpha = 1
            ctx.strokeStyle = SEARCH_ORANGE
            ctx.lineWidth = Math.max(4, 4 / k)
            circle(ctx, node.x, node.y, r + 5)
            ctx.stroke()

            const pulse = (tick % 90) / 90
            ctx.globalAlpha = 1 - pulse
            ctx.lineWidth = Math.max(2, 2 / k)
            circle(ctx, node.x, node.y, r + 8 + pulse * 26)
            ctx.stroke()
        }

        ctx.globalAlpha = 1
    }

    function drawLabels(ctx, api, focus) {
        const k = api.view.k
        ctx.textAlign = 'center'
        try {
            ctx.letterSpacing = '0.8px'
        } catch {
            // letterSpacing is not everywhere yet; the label reads fine without it.
        }

        for (const node of state.nodes) {
            if (node.x == null) continue
            const focused = api.hover?.id === node.id || state.selected?.id === node.id || state.highlighted === node.id
            // On the hosting view the systems sit in a grid, so a name may
            // only use its own cell: cut to fit, and left out entirely while
            // the cells are too small to hold a word — far out, the
            // containers' titles are the reading.
            const room = state.view === 'hosting' && ! focused ? CELL * k - 12 : Infinity
            if (room < 44) continue

            const [sx, sy] = api.w2s(node.x, node.y)
            if (sx < -80 || sy < -40 || sx > api.size.width + 80 || sy > api.size.height + 40) continue

            ctx.font = '700 10.5px Inter, system-ui, sans-serif'
            const label = fitText(ctx, node.label.toUpperCase(), room)
            const y = sy + node.radius * k + 14
            ctx.globalAlpha = focus && ! focus.has(node.id) && ! focused ? 0.22 : 1
            ctx.fillStyle = 'rgba(6,9,11,0.85)'
            ctx.fillText(label, sx + 1, y + 1)
            ctx.fillStyle = state.highlighted === node.id ? SEARCH_ORANGE : focused ? '#ffffff' : 'rgba(236,241,238,0.92)'
            ctx.fillText(label, sx, y)
            ctx.globalAlpha = 1
        }

        try {
            ctx.letterSpacing = '0px'
        } catch {
            // as above
        }
    }

    // ---- chrome ---------------------------------------------------------

    function setStatus(text) {
        if (status) status.textContent = text
    }

    function arrowGlyph(link) {
        return link.backward ? '↔' : '→'
    }

    function tipForNode(node) {
        const hosting = state.containerById.get(node.hosting)

        return `<strong>${escapeHtml(node.label)}</strong>
            <span class="block text-white/55">${escapeHtml(node.data.categoryLabel ?? 'Sistema')}${hosting ? ` · ${escapeHtml(hosting.label)}` : ''}</span>
            <span class="block text-white/40">${linksOf(node).length} ligação${linksOf(node).length === 1 ? '' : 'ões'} · clique para detalhes</span>`
    }

    function tipForLink(link) {
        const count = link.diagrams?.length ?? 0

        return `<strong>${escapeHtml(link.sn.label)} ${arrowGlyph(link)} ${escapeHtml(link.tn.label)}</strong>
            ${link.label ? `<span class="block text-white/55">${escapeHtml(link.label)}</span>` : ''}
            <span class="block text-white/40">${count} diagrama${count === 1 ? '' : 's'} · clique para ver</span>`
    }

    function tipForBox(box) {
        return `<strong>${escapeHtml(box.label)}</strong>
            <span class="block text-white/55">${box.members.length} sistema${box.members.length === 1 ? '' : 's'}</span>`
    }

    function linksOf(node) {
        return state.links.filter((l) => l.source === node.id || l.target === node.id)
    }

    function cardFor(subject) {
        const rows = []
        const add = (label, value) => value && rows.push(
            `<div class="flex items-baseline justify-between gap-3 border-t border-white/10 py-1.5">
                <dt class="text-[10px] uppercase tracking-wider text-white/40">${label}</dt>
                <dd class="text-right text-[12px] text-white/85">${escapeHtml(value)}</dd>
            </div>`,
        )

        let title
        let body = ''
        let action = null

        if (subject.type === 'link') {
            const link = subject.link
            title = `${link.sn.label} ${arrowGlyph(link)} ${link.tn.label}`
            add('Sentido', link.backward ? 'Ida e volta' : `${link.sn.label} → ${link.tn.label}`)
            add('Protocolo', link.label)
            body = (link.diagrams ?? []).length
                ? `<p class="mt-3 text-[10px] uppercase tracking-wider text-white/40">Diagramas</p>
                   <ul class="mt-1 space-y-1">${link.diagrams.map((d) => `<li>${diagramItem(d)}</li>`).join('')}</ul>`
                : ''
        } else if (subject.type === 'container') {
            title = subject.box.label
            add('Sistemas', String(subject.box.members.length))
        } else {
            const d = subject.data
            title = subject.label
            add('Categoria', d.categoryLabel)
            add('Status', d.statusLabel)
            add('Criticidade', d.criticalityLabel)
            add('Hospedagem', d.environmentLabel)
            add('Cloud', d.cloudLabel)
            add('Contrato', d.contractLabel)
            add('Suporte', d.supportLabel)
            add('Diretoria', d.directorate)
            add('Ligações', String(linksOf(subject).length))
            action = { url: d.url, label: 'Ver solução' }
        }

        return `
            <div class="flex items-start justify-between gap-3">
                <h3 class="text-[13px] font-semibold leading-snug text-white">${escapeHtml(title)}</h3>
                <button type="button" data-ak-map-card-close class="-mr-1 -mt-1 rounded p-1 text-white/40 transition hover:text-white">×</button>
            </div>
            <dl class="mt-2">${rows.join('')}</dl>
            ${body}
            ${action ? `<a href="${escapeAttr(action.url)}" target="_blank" rel="noopener"
                class="mt-3 inline-flex w-full items-center justify-center rounded-lg bg-white/10 px-3 py-2 text-[12px] font-medium text-white transition hover:bg-white/20">${action.label} ↗</a>` : ''}`
    }

    /**
     * A diagram behind an arrow: a link for whoever may open the diagrams
     * (the documentation module — `data-ak-map-diagrams`), its name for
     * everybody else. The map itself is open to every account; the drawings
     * are not.
     */
    function diagramItem(d) {
        const name = escapeHtml(d.name)

        return shell.dataset.akMapDiagrams === '1'
            ? `<a href="${escapeAttr(d.url)}" target="_blank" rel="noopener"
                  class="text-[12px] text-white/85 underline decoration-white/25 underline-offset-2 hover:text-white">${name} ↗</a>`
            : `<span class="text-[12px] text-white/75">${name}</span>`
    }

    // ---- view & arrows --------------------------------------------------

    function setView(value) {
        state.view = value === 'hosting' ? 'hosting' : 'links'
        if (arrowsToggle) arrowsToggle.hidden = state.view !== 'hosting'
        if (linksOnly) linksOnly.hidden = state.view !== 'links'
        load()
    }

    viewSelect?.addEventListener('change', () => setView(viewSelect.value))

    arrowsToggle?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-ak-map-arrows-mode]')
        if (! button) return
        state.arrows = button.dataset.akMapArrowsMode
        arrowsToggle.querySelectorAll('[data-ak-map-arrows-mode]').forEach((b) => {
            b.setAttribute('aria-pressed', String(b === button))
        })
        if (state.selected?.type === 'link') select(null)
    })

    // ---- search ---------------------------------------------------------

    searchInput?.addEventListener('input', () => {
        const term = fold(searchInput.value.trim())
        if (! term || ! state.payload) {
            results.hidden = true

            return
        }

        const hits = state.payload.nodes
            .filter((n) => fold(n.label).includes(term))
            .slice(0, 8)

        results.innerHTML = hits.length
            ? hits.map((hit) => `
                <button type="button" data-ak-map-goto="${escapeAttr(hit.id)}"
                        class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-[12px] text-white/85 transition hover:bg-white/10">
                    <span class="truncate">${escapeHtml(hit.label)}</span>
                    <span class="shrink-0 text-[10px] uppercase tracking-wide text-white/35">${escapeHtml(hit.categoryLabel ?? 'Sistema')}</span>
                </button>`).join('')
            : '<p class="px-3 py-2 text-[12px] text-white/45">Nenhum sistema encontrado.</p>'
        results.hidden = false
    })

    /** Marks the system with the orange ring and brings it to the centre. */
    function goTo(id) {
        const node = state.nodeById.get(id)
        if (! node) return
        state.highlighted = id
        select(node)
        centerOn(node, Math.max(1.1, view.view.k))
    }

    shell.addEventListener('click', (event) => {
        const goto = event.target.closest('[data-ak-map-goto]')
        if (goto) {
            goTo(goto.dataset.akMapGoto)
            results.hidden = true
            searchInput.value = ''

            return
        }

        if (event.target.closest('[data-ak-map-card-close]')) {
            select(null)

            return
        }

        const action = event.target.closest('[data-ak-map-action]')
        if (! action) return

        const kind = action.dataset.akMapAction
        if (kind === 'fit') fitAll()
        if (kind === 'zoom-in') view.zoomBy(1.25)
        if (kind === 'zoom-out') view.zoomBy(0.8)
        if (kind === 'orbit') {
            state.orbit = ! state.orbit
            action.dataset.akMapOn = String(state.orbit)
        }
        if (kind === 'layout') {
            state.layout = state.layout === 'rings' ? 'force' : 'rings'
            action.querySelector('[data-label]').textContent = state.layout === 'rings' ? 'Órbitas' : 'Força'
            relayout()
        }
        if (kind === 'fullscreen') {
            document.fullscreenElement ? document.exitFullscreen() : shell.requestFullscreen?.()
        }
    })

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || ! shell.isConnected) return
        state.highlighted = null
        select(null)
    })
}

// ---- helpers ------------------------------------------------------------

/** `text`, cut with an ellipsis to at most `width` px in the current font. */
function fitText(ctx, text, width) {
    if (width === Infinity || ctx.measureText(text).width <= width) return text

    let cut = text
    while (cut.length > 1 && ctx.measureText(`${cut}…`).width > width) cut = cut.slice(0, -1)

    return `${cut.trimEnd()}…`
}

function roundRectPath(ctx, x, y, w, h, r) {
    const radius = Math.min(r, w / 2, h / 2)
    ctx.beginPath()
    ctx.moveTo(x + radius, y)
    ctx.arcTo(x + w, y, x + w, y + h, radius)
    ctx.arcTo(x + w, y + h, x, y + h, radius)
    ctx.arcTo(x, y + h, x, y, radius)
    ctx.arcTo(x, y, x + w, y, radius)
    ctx.closePath()
}

/**
 * The map's colors are the app's colors: the eight category families and the
 * semantic tones, read straight off the `@theme` tokens so nothing is
 * restated here as a literal. They are lightened on the way in — the tokens
 * are tuned for near-black text on white, and this canvas is the other way
 * round.
 */
function readPalette() {
    const styles = getComputedStyle(document.documentElement)
    const token = (name, fallback) => (styles.getPropertyValue(name).trim() || fallback)
    const onDark = (hex) => mix(hex, '#ffffff', 0.3)

    const families = {}
    for (const family of ['emerald', 'teal', 'blue', 'indigo', 'fuchsia', 'rose', 'amber', 'slate']) {
        families[family] = onDark(token(`--color-cat-${family}`, '#64748b'))
    }

    const lime = onDark(token('--color-lime', '#AADB1E'))
    const neutral = families.slate

    return {
        lime,
        neutral,
        family: (name) => families[name] ?? neutral,
    }
}

function logoFor(url) {
    if (! url) return null
    const img = new Image()
    img.src = url

    return img
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[c])
}

function escapeAttr(value) {
    return escapeHtml(value).replace(/`/g, '&#96;')
}
