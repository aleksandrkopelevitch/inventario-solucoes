// Ecosystem map — a canvas graph you drill into.
//
// Three levels of the same reading, on one surface:
//
//   1. MACRO — one block per Solution, one link per PAIR of solutions. The
//      link says SAP and SVL talk to each other; it does not say how many
//      ways, or through what.
//   2. Clicking a system, or one of those links, unfolds the DIAGRAMS behind
//      it — a link that carries three integrations turns into three named
//      blocks instead of one anonymous line.
//   3. Clicking a diagram unfolds its CHAIN, the drawing itself:
//      SAP → Digibee → SVL → BigQuery, with each step's protocol.
//
// Everything is in one payload (see `DiagramGraphService`), so a level
// costs no round trip and the search box can find a drawing nobody has
// expanded yet.
//
// The renderer is adapted from the "Second Brain" workspace-graph visualizer
// by Jay E / RoboNuggets, used under CC BY 4.0 — see NOTICE at the repo root.
// `graph-canvas/` holds the parts of it that know nothing about this app.
//
// Two things here are deliberate and easy to undo:
//
// - **A child is born at its parent's position.** Every node eases from
//   where it is toward a target, and a node that has just appeared starts at
//   the block it came out of. That is the whole drill-down feeling: blocks
//   grow out of what you clicked instead of materialising somewhere else.
// - **Nothing is ever hidden, only dimmed.** Expanding a system dims the
//   rest of the ecosystem to a quarter — it stays on screen, so you can see
//   where in the map you are standing.

import { fold } from './fold.js'
import { mountCanvas } from './graph-canvas/camera.js'
import { buildSimulation, chainLayout, ringsLayout, satelliteLayout } from './graph-canvas/layout.js'
import { arrowHead, circle, glowSprite, hexToRgba, hexagon, linkCtrl, linkPoint, mix, orbSprite, roundedRect } from './graph-canvas/sprites.js'

const mounted = new WeakSet()

const SOLUTION_R = 26
const DIAGRAM_R = 17
const STEP_R = 15
const SATELLITE_GAP = 200
const CHAIN_SPACING = 120
const EASE = 0.14

const KIND_LABEL = {
    system: 'Sistema',
    decision: 'Decisão',
    actor: 'Ator',
    start: 'Início',
    end: 'Fim',
    image: 'Imagem',
}

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
    const crumb = shell.querySelector('[data-ak-map-crumb]')
    const status = shell.querySelector('[data-ak-map-status]')
    const searchInput = shell.querySelector('[data-ak-map-search]')
    const results = shell.querySelector('[data-ak-map-results]')

    const palette = readPalette()

    const state = {
        url: shell.dataset.akMapUrl,
        payload: null,
        nodes: [],
        nodeById: new Map(),
        links: [],
        expandedSolutions: new Set(),
        expandedPairs: new Set(),
        openDiagrams: new Set(),
        selected: null,
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
        onDoubleClick: (node) => view.flyToNode(node, 1.4),
    })

    load(state.url)
    shell.__ecosystemMapReload = (url) => {
        state.url = url
        collapseAll(false)
        load(url)
    }

    // ---- data ----------------------------------------------------------

    async function load(url) {
        setStatus('Carregando…')
        const response = await fetch(url, { headers: { Accept: 'application/json' } })
        if (! response.ok) {
            setStatus('Não foi possível carregar o mapa.')

            return
        }

        const payload = await response.json()
        state.payload = {
            nodes: payload.nodes ?? [],
            edges: payload.edges ?? [],
            diagrams: payload.diagrams ?? [],
        }
        state.diagramBySlug = new Map(state.payload.diagrams.map((d) => [d.slug, d]))
        state.nodes = []
        state.nodeById = new Map()
        rebuild()
        fitAll()
    }

    /**
     * Rebuilds the visible graph from the drill-down state. Node objects are
     * reused by id, which is what keeps a block where it already was when
     * something else expands beside it.
     */
    function rebuild() {
        const previous = state.nodeById
        const nodes = []
        const links = []
        const byId = new Map()

        const put = (node) => {
            const existing = previous.get(node.id)
            const merged = existing ? Object.assign(existing, node) : node
            if (! existing) {
                const parent = node.parentId ? byId.get(node.parentId) : null
                merged.x = parent?.x ?? 0
                merged.y = parent?.y ?? 0
                merged.born = true
            }
            nodes.push(merged)
            byId.set(merged.id, merged)

            return merged
        }

        for (const solution of state.payload.nodes) {
            put({
                id: solution.id,
                type: 'solution',
                label: solution.label,
                radius: SOLUTION_R,
                color: palette.family(solution.categoryFamily),
                data: solution,
                logo: logoFor(solution.logo),
            })
        }

        for (const edge of state.payload.edges) {
            if (! byId.has(edge.source) || ! byId.has(edge.target)) continue
            links.push({
                id: edge.id,
                kind: 'pair',
                source: edge.source,
                target: edge.target,
                label: edge.label,
                status: edge.status,
                direction: edge.direction,
                diagrams: edge.diagrams ?? [],
            })
        }

        // A diagram shows up when a system it touches is expanded, when a pair
        // it produces is expanded, or when it is itself open.
        for (const diagram of state.payload.diagrams) {
            const viaSolutions = diagram.solutions.filter((id) => state.expandedSolutions.has(id))
            const viaPairs = state.payload.edges.filter(
                (edge) => state.expandedPairs.has(edge.id) && (edge.diagrams ?? []).some((d) => d.slug === diagram.slug),
            )
            const open = state.openDiagrams.has(diagram.slug)

            if (! viaSolutions.length && ! viaPairs.length && ! open) continue

            // Anchored to the pair when it came from one — a diagram that
            // explains SAP↔SVL belongs between them, not orbiting one side.
            const anchor = viaPairs.length ? byId.get(viaPairs[0].source) : byId.get(viaSolutions[0])

            const node = put({
                id: diagram.id,
                type: 'diagram',
                label: diagram.label,
                radius: DIAGRAM_R,
                color: palette.status(diagram.status),
                data: diagram,
                parentId: anchor?.id,
                viaPairs: viaPairs.map((edge) => edge.id),
                viaSolutions,
                open,
            })

            const owners = new Set(viaSolutions)
            viaPairs.forEach((edge) => {
                owners.add(edge.source)
                owners.add(edge.target)
            })
            if (! owners.size) diagram.solutions.forEach((id) => owners.add(id))

            owners.forEach((solutionId) => {
                if (! byId.has(solutionId)) return
                links.push({ id: `${diagram.id}~${solutionId}`, kind: 'owns', source: solutionId, target: diagram.id })
            })

            if (! open) continue

            const stepIds = diagram.chain.nodes.map((step, i) => {
                const id = `${diagram.id}#${i}`
                put({
                    id,
                    type: 'step',
                    label: step.label,
                    radius: STEP_R,
                    color: step.solutionId
                        ? palette.family(state.payload.nodes.find((s) => s.id === step.solutionId)?.categoryFamily)
                        : palette.neutral,
                    data: step,
                    nodeKind: step.kind,
                    parentId: node.id,
                    diagramId: diagram.id,
                })
                links.push({ id: `${id}~member`, kind: 'member', source: diagram.id, target: id })

                return id
            })

            diagram.chain.edges.forEach((edge, i) => {
                const from = stepIds[edge.from]
                const to = stepIds[edge.to]
                if (! from || ! to) return
                links.push({
                    id: `${diagram.id}!${i}`,
                    kind: 'flow',
                    source: from,
                    target: to,
                    arrow: edge.arrow,
                    label: edge.protocol,
                })
            })
        }

        state.nodes = nodes
        state.nodeById = byId
        state.links = links.map((link) => ({
            ...link,
            sn: byId.get(link.source),
            tn: byId.get(link.target),
        })).filter((link) => link.sn && link.tn)

        relayout()
        renderCrumb()
        setStatus(`${state.payload.nodes.length} sistemas · ${state.payload.edges.length} ligações · ${state.payload.diagrams.length} diagramas`)
    }

    function relayout() {
        const solutions = state.nodes.filter((n) => n.type === 'solution')
        const pairs = state.links.filter((l) => l.kind === 'pair')

        if (state.layout === 'force') {
            state.sim?.stop()
            state.sim = buildSimulation(state.nodes, state.links.map((l) => ({ ...l })), () => {})
            state.sim.alpha(0.9).restart()

            return
        }

        state.sim?.stop()
        state.sim = null
        ringsLayout(solutions, pairs)

        // Diagrams hanging off one system fan out away from the centre; the
        // ones explaining a pair sit on the pair's own midpoint.
        for (const solution of solutions) {
            const children = state.nodes.filter(
                (n) => n.type === 'diagram' && ! n.viaPairs.length && n.viaSolutions.includes(solution.id),
            )
            if (children.length) satelliteLayout(solution, children, SATELLITE_GAP)
        }

        for (const link of pairs) {
            const children = state.nodes.filter((n) => n.type === 'diagram' && n.viaPairs.includes(link.id))
            if (! children.length) continue

            const mx = (link.sn.tx + link.tn.tx) / 2
            const my = (link.sn.ty + link.tn.ty) / 2
            const angle = Math.atan2(link.tn.ty - link.sn.ty, link.tn.tx - link.sn.tx) + Math.PI / 2

            children.forEach((child, i) => {
                const offset = (i - (children.length - 1) / 2) * 92
                child.tx = mx + Math.cos(angle) * offset
                child.ty = my + Math.sin(angle) * offset
                child.angle = angle
            })
        }

        for (const diagram of state.nodes.filter((n) => n.type === 'diagram' && n.open)) {
            const steps = state.nodes.filter((n) => n.type === 'step' && n.diagramId === diagram.id)
            chainLayout(diagram, steps, CHAIN_SPACING)
        }
    }

    // ---- interaction ----------------------------------------------------

    function onClick(node, event, point) {
        if (! node) {
            const link = linkAt(point)
            if (link?.kind === 'pair') {
                toggle(state.expandedPairs, link.id)
                tip.hidden = true
                rebuild()
                focusOn(link.sn, link.tn, ...state.nodes.filter((n) => n.type === 'diagram' && n.viaPairs.includes(link.id)))
            } else {
                select(null)
            }

            return
        }

        if (node.type === 'solution') toggle(state.expandedSolutions, node.id)
        if (node.type === 'diagram') toggle(state.openDiagrams, node.data.slug)

        // The tooltip was answering a hover that the click has just made
        // stale — left up, it sits exactly over whatever unfolded.
        tip.hidden = true

        select(node)
        rebuild()

        // Frame what just appeared. A cluster grows outward from the block
        // that was clicked, so without this the third or fourth diagram of a
        // system opens off-screen and the expansion reads as nothing having
        // happened.
        if (node.type === 'solution' && state.expandedSolutions.has(node.id)) {
            focusOn(node, ...state.nodes.filter((n) => n.type === 'diagram' && n.viaSolutions.includes(node.id)))
        }

        if (node.type === 'diagram' && state.openDiagrams.has(node.data.slug)) {
            focusOn(node, ...state.nodes.filter((n) => n.type === 'step' && n.diagramId === node.id))
        }
    }

    function onHover(node, event, point) {
        state.hoverLink = node ? null : linkAt(point)

        const subject = node ?? state.hoverLink
        if (! subject || ! point) {
            tip.hidden = true

            return
        }

        tip.innerHTML = node ? tipForNode(node) : tipForLink(state.hoverLink)
        tip.hidden = false
        const bounds = canvas.getBoundingClientRect()
        tip.style.left = `${Math.min(point.px + 16, bounds.width - tip.offsetWidth - 12)}px`
        tip.style.top = `${Math.min(point.py + 16, bounds.height - tip.offsetHeight - 12)}px`
    }

    /** Nearest pair link under the cursor — sampled along the curve it is drawn as. */
    function linkAt(point) {
        if (! point) return null
        const [wx, wy] = view.s2w(point.px, point.py)
        const tolerance = 10 / view.view.k
        let best = null
        let bestDistance = tolerance

        state.links.forEach((link, i) => {
            if (link.kind !== 'pair') return
            for (let t = 0.1; t <= 0.9; t += 0.1) {
                const [x, y] = linkPoint(link.sn, link.tn, i, t)
                const d = Math.hypot(x - wx, y - wy)
                if (d < bestDistance) {
                    bestDistance = d
                    best = link
                }
            }
        })

        return best
    }

    function toggle(set, key) {
        set.has(key) ? set.delete(key) : set.add(key)
    }

    function collapseAll(refit = true) {
        state.expandedSolutions.clear()
        state.expandedPairs.clear()
        state.openDiagrams.clear()
        select(null)
        if (state.payload) rebuild()
        if (refit) fitAll()
    }

    /**
     * Frames the graph by where the blocks are GOING, never by where they
     * are. Every node eases toward its target and a fresh one starts at its
     * parent's position, so measuring the live coordinates right after a
     * layout frames a graph that has not been drawn yet — which is how the
     * first paint ended up with half the ecosystem outside the viewport.
     */
    function fitAll() {
        const placed = state.nodes.filter((n) => n.tx != null)
        if (! placed.length) return

        const pad = 70
        const xs = placed.map((n) => targetOf(n)[0])
        const ys = placed.map((n) => targetOf(n)[1])

        view.fit({
            x: Math.min(...xs) - pad,
            y: Math.min(...ys) - pad,
            w: Math.max(...xs) - Math.min(...xs) + pad * 2,
            h: Math.max(...ys) - Math.min(...ys) + pad * 2,
        })
    }

    /**
     * Where a node is HEADED, in the world the camera sees — the layout's
     * target, turned by however far the orbit has spun. Framing is the one
     * place both have to be taken together: the spin rotates what is drawn
     * without touching what was computed, so a fit that read the raw target
     * would aim the camera at where the block was before the map turned.
     */
    function targetOf(node) {
        const tx = node.tx ?? node.x
        const ty = node.ty ?? node.y
        if (! state.orbit || state.layout !== 'rings') return [tx, ty]

        const r = Math.hypot(tx, ty)
        const a = Math.atan2(ty, tx) + state.spin

        return [Math.cos(a) * r, Math.sin(a) * r]
    }

    function focusOn(...nodes) {
        const present = nodes.filter(Boolean)
        if (! present.length) return

        const xs = present.map((n) => targetOf(n)[0])
        const ys = present.map((n) => targetOf(n)[1])
        const pad = 170

        view.fit({
            x: Math.min(...xs) - pad,
            y: Math.min(...ys) - pad,
            w: Math.max(...xs) - Math.min(...xs) + pad * 2,
            h: Math.max(...ys) - Math.min(...ys) + pad * 2,
        })
    }

    function select(node) {
        state.selected = node
        if (! node) {
            card.hidden = true

            return
        }
        card.innerHTML = cardFor(node)
        card.hidden = false
    }

    // ---- drawing --------------------------------------------------------

    function draw(ctx, frame, api) {
        const { width, height, tick } = frame

        ctx.save()
        drawBackdrop(ctx, width, height)

        if (state.orbit) state.spin += 0.00035

        for (const node of state.nodes) {
            if (node.tx == null || node.pinned || state.layout === 'force') continue
            const [tx, ty] = targetOf(node)
            node.x += (tx - node.x) * EASE
            node.y += (ty - node.y) * EASE
            if (node.born && Math.hypot(tx - node.x, ty - node.y) < 1) node.born = false
        }

        ctx.translate(api.view.x, api.view.y)
        ctx.scale(api.view.k, api.view.k)

        const dimmed = focusSet()

        state.links.forEach((link, i) => drawLink(ctx, link, i, api.view.k, dimmed, tick))
        for (const node of state.nodes) drawNode(ctx, node, api.view.k, dimmed, tick)

        ctx.restore()
        drawLabels(ctx, api, dimmed)
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

    /** null when nothing is expanded — then everything is at full strength. */
    function focusSet() {
        if (! state.expandedSolutions.size && ! state.expandedPairs.size && ! state.openDiagrams.size) return null

        const ids = new Set()
        for (const node of state.nodes) {
            if (node.type === 'solution' && state.expandedSolutions.has(node.id)) ids.add(node.id)
            if (node.type === 'diagram' || node.type === 'step') ids.add(node.id)
        }
        for (const link of state.links) {
            if (link.kind === 'pair' && state.expandedPairs.has(link.id)) {
                ids.add(link.source)
                ids.add(link.target)
            }
            if (link.kind === 'owns') ids.add(link.source)
        }

        return ids
    }

    function alphaFor(node, dimmed) {
        if (state.selected?.id === node.id) return 1

        return dimmed && ! dimmed.has(node.id) ? 0.22 : 1
    }

    function drawLink(ctx, link, i, k, dimmed, tick) {
        const a = link.sn
        const b = link.tn
        if (a.x == null || b.x == null) return

        const lit = state.hoverLink?.id === link.id
        const dim = dimmed && ! (dimmed.has(link.source) && dimmed.has(link.target))
        ctx.globalAlpha = lit ? 1 : dim ? 0.12 : 1

        const [cx, cy] = linkCtrl(a, b, i)

        if (link.kind === 'pair') {
            const grd = ctx.createLinearGradient(a.x, a.y, b.x, b.y)
            grd.addColorStop(0, hexToRgba(a.color, lit ? 0.95 : 0.5))
            grd.addColorStop(1, hexToRgba(b.color, lit ? 0.95 : 0.5))
            ctx.strokeStyle = grd
            ctx.lineWidth = (lit ? 2.6 : 1.7) / k
        } else if (link.kind === 'flow') {
            ctx.strokeStyle = hexToRgba(palette.lime, 0.75)
            ctx.lineWidth = 1.6 / k
        } else if (link.kind === 'member') {
            ctx.strokeStyle = 'rgba(255,255,255,0.14)'
            ctx.lineWidth = 0.8 / k
            ctx.setLineDash([3 / k, 5 / k])
        } else {
            ctx.strokeStyle = hexToRgba(b.color, 0.42)
            ctx.lineWidth = 1 / k
            ctx.setLineDash([5 / k, 6 / k])
            ctx.lineDashOffset = -tick * 0.18
        }

        ctx.beginPath()
        ctx.moveTo(a.x, a.y)
        ctx.quadraticCurveTo(cx, cy, b.x, b.y)
        ctx.stroke()
        ctx.setLineDash([])

        if (link.kind === 'pair' || link.kind === 'flow') {
            const forward = link.kind === 'flow' ? link.arrow !== '<-' : true
            const backward = link.kind === 'flow'
                ? link.arrow !== '->'
                : link.direction === 'bidirectional'

            ctx.fillStyle = link.kind === 'flow' ? hexToRgba(palette.lime, 0.9) : hexToRgba(b.color, 0.85)
            if (forward) headAt(ctx, link, i, b, 1, k)
            if (backward) {
                ctx.fillStyle = link.kind === 'flow' ? hexToRgba(palette.lime, 0.9) : hexToRgba(a.color, 0.85)
                headAt(ctx, link, i, a, 0, k)
            }
        }

        ctx.globalAlpha = 1
    }

    /** Arrowhead parked just off the target block, pointing the way the flow runs. */
    function headAt(ctx, link, i, node, end, k) {
        const t = end === 1 ? 0.995 : 0.005
        const near = end === 1 ? 0.93 : 0.07
        const [x, y] = linkPoint(link.sn, link.tn, i, t)
        const [px, py] = linkPoint(link.sn, link.tn, i, near)
        const angle = Math.atan2(y - py, x - px)
        const gap = node.radius + 5

        arrowHead(ctx, x - Math.cos(angle) * gap, y - Math.sin(angle) * gap, angle, Math.max(7, 9 / k))
    }

    function drawNode(ctx, node, k, dimmed, tick) {
        if (node.x == null) return
        const alpha = alphaFor(node, dimmed)
        const r = node.radius
        ctx.globalAlpha = alpha

        const glowR = r * 2.5
        ctx.globalAlpha = alpha * 0.55
        ctx.drawImage(glowSprite(node.color), node.x - glowR, node.y - glowR, glowR * 2, glowR * 2)
        ctx.globalAlpha = alpha

        if (node.type === 'solution') {
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

            const count = countFor(node)
            if (count) drawBadge(ctx, node, count, k, state.expandedSolutions.has(node.id))
        } else if (node.type === 'diagram') {
            hexagon(ctx, node.x, node.y, r)
            ctx.fillStyle = node.open ? node.color : mix(node.color, '#0b0e11', 0.55)
            ctx.fill()
            ctx.strokeStyle = hexToRgba(node.color, 0.95)
            ctx.lineWidth = 1.8 / k
            ctx.stroke()
        } else {
            drawStep(ctx, node, r, k)
        }

        if (state.selected?.id === node.id) {
            ctx.strokeStyle = hexToRgba(palette.lime, 0.9)
            ctx.lineWidth = 2 / k
            circle(ctx, node.x, node.y, r + 7 + Math.sin(tick * 0.08) * 1.5)
            ctx.stroke()
        }

        ctx.globalAlpha = 1
    }

    /**
     * A chain step keeps the F3 canvas's shape vocabulary, because it IS the
     * same drawing: a decision is a hexagon, an actor and the two terminals
     * are circles, everything else is a rounded block.
     */
    function drawStep(ctx, node, r, k) {
        const kind = node.nodeKind
        ctx.lineWidth = 1.6 / k

        if (kind === 'decision') {
            hexagon(ctx, node.x, node.y, r)
        } else if (kind === 'actor' || kind === 'start' || kind === 'end') {
            circle(ctx, node.x, node.y, r * 0.8)
        } else {
            roundedRect(ctx, node.x, node.y, r, 6)
        }

        ctx.fillStyle = kind === 'start'
            ? hexToRgba(palette.lime, 0.9)
            : kind === 'end'
                ? hexToRgba(palette.crit, 0.9)
                : mix(node.color, '#0b0e11', 0.5)
        ctx.fill()
        ctx.strokeStyle = hexToRgba(node.color, 0.9)
        ctx.stroke()

        if (node.data.logo) {
            node.logoImage ??= logoFor(node.data.logo)
            if (node.logoImage?.complete && node.logoImage.naturalWidth) {
                ctx.save()
                roundedRect(ctx, node.x, node.y, r - 3, 5)
                ctx.clip()
                ctx.drawImage(node.logoImage, node.x - r, node.y - r, r * 2, r * 2)
                ctx.restore()
            }
        }
    }

    /** How many diagrams a system takes part in — the reason to click it. */
    function countFor(node) {
        return state.payload.diagrams.filter((d) => d.solutions.includes(node.id)).length
    }

    function drawBadge(ctx, node, count, k, open) {
        const bx = node.x + node.radius * 0.82
        const by = node.y - node.radius * 0.82
        const r = 9

        circle(ctx, bx, by, r)
        ctx.fillStyle = open ? palette.lime : '#0f1215'
        ctx.fill()
        ctx.strokeStyle = open ? palette.lime : hexToRgba(node.color, 0.9)
        ctx.lineWidth = 1.4 / k
        ctx.stroke()

        ctx.fillStyle = open ? '#0b0e11' : '#e7ecea'
        ctx.font = '700 11px Inter, system-ui, sans-serif'
        ctx.textAlign = 'center'
        ctx.textBaseline = 'middle'
        ctx.fillText(String(count), bx, by + 0.5)
        ctx.textBaseline = 'alphabetic'
    }

    function drawLabels(ctx, api, dimmed) {
        const k = api.view.k
        ctx.textAlign = 'center'

        for (const node of state.nodes) {
            if (node.x == null) continue
            const focused = api.hover?.id === node.id || state.selected?.id === node.id
            if (node.type === 'diagram' && k < 0.42 && ! focused) continue
            if (node.type === 'step' && k < 0.5 && ! focused) continue

            const [sx, sy] = api.w2s(node.x, node.y)
            if (sx < -80 || sy < -40 || sx > api.size.width + 80 || sy > api.size.height + 40) continue

            const label = node.type === 'solution' ? node.label.toUpperCase() : node.label
            ctx.font = node.type === 'solution'
                ? '700 10.5px Inter, system-ui, sans-serif'
                : '500 10px Inter, system-ui, sans-serif'
            try {
                ctx.letterSpacing = node.type === 'solution' ? '0.8px' : '0px'
            } catch {
                // letterSpacing is not everywhere yet; the label reads fine without it.
            }

            const y = sy + node.radius * k + 14
            ctx.globalAlpha = alphaFor(node, dimmed)
            ctx.fillStyle = 'rgba(6,9,11,0.85)'
            ctx.fillText(label, sx + 1, y + 1)
            ctx.fillStyle = focused ? '#ffffff' : node.type === 'solution' ? 'rgba(236,241,238,0.92)' : 'rgba(198,208,203,0.8)'
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

    function renderCrumb() {
        if (! crumb) return

        const chips = []
        for (const id of state.expandedSolutions) {
            const node = state.nodeById.get(id)
            if (node) chips.push({ label: node.label, kind: 'solution', key: id })
        }
        for (const id of state.expandedPairs) {
            const link = state.links.find((l) => l.id === id)
            if (link) chips.push({ label: `${link.sn.label} ↔ ${link.tn.label}`, kind: 'pair', key: id })
        }
        for (const slug of state.openDiagrams) {
            const diagram = state.diagramBySlug.get(slug)
            if (diagram) chips.push({ label: diagram.label, kind: 'diagram', key: slug })
        }

        crumb.innerHTML = chips.length
            ? chips.map((chip) => `
                <button type="button" data-ak-map-crumb-drop="${chip.kind}:${escapeAttr(chip.key)}"
                        class="inline-flex items-center gap-1.5 rounded-full border border-white/15 bg-white/5 px-3 py-1 text-[11px] text-white/80 transition hover:border-white/35 hover:text-white">
                    ${escapeHtml(chip.label)}
                    <span aria-hidden="true" class="text-white/45">×</span>
                </button>`).join('')
            : '<span class="text-[11px] text-white/35">Clique em um sistema ou em uma ligação para abrir os diagramas.</span>'
    }

    function tipForNode(node) {
        if (node.type === 'solution') {
            const count = countFor(node)

            return `<strong>${escapeHtml(node.label)}</strong>
                <span class="block text-white/55">${escapeHtml(node.data.categoryLabel ?? 'Sistema')}</span>
                <span class="block text-white/40">${count} diagrama${count === 1 ? '' : 's'} · clique para ${state.expandedSolutions.has(node.id) ? 'recolher' : 'expandir'}</span>`
        }

        if (node.type === 'diagram') {
            return `<strong>${escapeHtml(node.label)}</strong>
                <span class="block text-white/55">${escapeHtml(node.data.statusLabel ?? '')}${node.data.protocolLabel ? ` · ${escapeHtml(node.data.protocolLabel)}` : ''}</span>
                <span class="block text-white/40">Clique para ${node.open ? 'fechar' : 'abrir'} o fluxo</span>`
        }

        return `<strong>${escapeHtml(node.label)}</strong>
            <span class="block text-white/55">${escapeHtml(KIND_LABEL[node.nodeKind] ?? 'Bloco')}</span>`
    }

    function tipForLink(link) {
        const count = link.diagrams.length

        return `<strong>${escapeHtml(link.sn.label)} ${link.direction === 'bidirectional' ? '↔' : '→'} ${escapeHtml(link.tn.label)}</strong>
            ${link.label ? `<span class="block text-white/55">${escapeHtml(link.label)}</span>` : ''}
            <span class="block text-white/40">${count} diagrama${count === 1 ? '' : 's'} · clique para ${state.expandedPairs.has(link.id) ? 'recolher' : 'expandir'}</span>`
    }

    function cardFor(node) {
        const rows = []
        const add = (label, value) => value && rows.push(
            `<div class="flex items-baseline justify-between gap-3 border-t border-white/10 py-1.5">
                <dt class="text-[10px] uppercase tracking-wider text-white/40">${label}</dt>
                <dd class="text-right text-[12px] text-white/85">${escapeHtml(value)}</dd>
            </div>`,
        )

        let title = node.label
        let action = null

        if (node.type === 'solution') {
            const d = node.data
            add('Categoria', d.categoryLabel)
            add('Status', d.statusLabel)
            add('Criticidade', d.criticalityLabel)
            add('Ambiente', d.environmentLabel)
            add('Nuvem', d.cloudLabel)
            add('Contrato', d.contractLabel)
            add('Suporte', d.supportLabel)
            add('Diretoria', d.directorate)
            action = { url: d.url, label: 'Ver solução' }
        } else if (node.type === 'diagram') {
            const d = node.data
            add('Status', d.statusLabel)
            add('Criticidade', d.criticalityLabel)
            add('Sincronismo', d.syncModeLabel)
            add('Protocolo', d.protocolLabel)
            add('Blocos', String(d.chain.nodes.length))
            action = { url: d.url, label: 'Abrir diagrama' }
        } else {
            add('Tipo', KIND_LABEL[node.nodeKind] ?? 'Bloco')
            if (node.data.url) action = { url: node.data.url, label: 'Ver solução' }
            title = node.label
        }

        return `
            <div class="flex items-start justify-between gap-3">
                <h3 class="text-[13px] font-semibold leading-snug text-white">${escapeHtml(title)}</h3>
                <button type="button" data-ak-map-card-close class="-mr-1 -mt-1 rounded p-1 text-white/40 transition hover:text-white">×</button>
            </div>
            <dl class="mt-2">${rows.join('')}</dl>
            ${action ? `<a href="${escapeAttr(action.url)}" target="_blank" rel="noopener"
                class="mt-3 inline-flex w-full items-center justify-center rounded-lg bg-white/10 px-3 py-2 text-[12px] font-medium text-white transition hover:bg-white/20">${action.label} ↗</a>` : ''}`
    }

    // ---- search ---------------------------------------------------------

    searchInput?.addEventListener('input', () => {
        const term = fold(searchInput.value.trim())
        if (! term || ! state.payload) {
            results.hidden = true

            return
        }

        const hits = [
            ...state.payload.nodes
                .filter((n) => fold(n.label).includes(term))
                .map((n) => ({ id: n.id, label: n.label, hint: n.categoryLabel ?? 'Sistema', kind: 'solution' })),
            ...state.payload.diagrams
                .filter((d) => fold(d.label).includes(term))
                .map((d) => ({ id: d.id, label: d.label, hint: 'Diagrama', kind: 'diagram', slug: d.slug })),
        ].slice(0, 8)

        results.innerHTML = hits.length
            ? hits.map((hit) => `
                <button type="button" data-ak-map-goto="${escapeAttr(hit.kind)}:${escapeAttr(hit.slug ?? hit.id)}"
                        class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-[12px] text-white/85 transition hover:bg-white/10">
                    <span class="truncate">${escapeHtml(hit.label)}</span>
                    <span class="shrink-0 text-[10px] uppercase tracking-wide text-white/35">${escapeHtml(hit.hint)}</span>
                </button>`).join('')
            : '<p class="px-3 py-2 text-[12px] text-white/45">Nada encontrado.</p>'
        results.hidden = false
    })

    /**
     * Jumping to a diagram has to EXPAND it into view first — the search
     * looks at the whole payload, including drawings nobody has unfolded, and
     * flying the camera at a node that is not on the canvas shows an empty
     * patch of map.
     */
    function goTo(kind, key) {
        if (kind === 'diagram') {
            const diagram = state.diagramBySlug.get(key)
            if (! diagram) return
            diagram.solutions.slice(0, 1).forEach((id) => state.expandedSolutions.add(id))
            state.openDiagrams.add(key)
            rebuild()
            const node = state.nodeById.get(diagram.id)
            select(node ?? null)
            if (node) focusOn(node, ...state.nodes.filter((n) => n.type === 'step' && n.diagramId === node.id))

            return
        }

        const node = state.nodeById.get(key)
        if (! node) return
        view.flyToNode(node, 1)
        select(node)
    }

    shell.addEventListener('click', (event) => {
        const goto = event.target.closest('[data-ak-map-goto]')
        if (goto) {
            const [kind, key] = goto.dataset.akMapGoto.split(/:(.+)/)
            goTo(kind, key)
            results.hidden = true
            searchInput.value = ''

            return
        }

        const drop = event.target.closest('[data-ak-map-crumb-drop]')
        if (drop) {
            const [kind, key] = drop.dataset.akMapCrumbDrop.split(/:(.+)/)
            if (kind === 'solution') state.expandedSolutions.delete(key)
            if (kind === 'pair') state.expandedPairs.delete(key)
            if (kind === 'diagram') state.openDiagrams.delete(key)
            rebuild()

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
        if (kind === 'collapse') collapseAll()
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
        if (state.selected) select(null)
        else collapseAll()
    })
}

// ---- helpers ------------------------------------------------------------

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
    const hot = onDark(token('--color-hot', '#b26a11'))
    const crit = onDark(token('--color-crit', '#b23b3b'))
    const neutral = families.slate

    return {
        lime,
        hot,
        crit,
        neutral,
        family: (name) => families[name] ?? neutral,
        status: (value) => ({
            active: lime,
            in_development: hot,
            planned: families.blue,
            deprecated: crit,
        })[value] ?? neutral,
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
