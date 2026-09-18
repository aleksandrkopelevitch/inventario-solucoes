// The two arrangements the ecosystem map offers, and the rule that makes the
// drill-down legible: a child is always placed RELATIVE to what it came out
// of, so expanding a block grows a cluster instead of reshuffling the map.
//
// `rings` is deterministic — the same portfolio draws the same picture every
// time, which is what lets somebody say "SAP is the one at the top left" to a
// colleague. `force` is d3's simulation, for when the question is which parts
// of the ecosystem clump together rather than where a given system is.

import { forceCenter, forceCollide, forceLink, forceManyBody, forceSimulation, forceX, forceY } from 'd3-force'

const RING_GAP = 150
const FIRST_RING = 210
const MIN_ARC = 120 // world px of perimeter per system — below this, labels collide

/**
 * Concentric rings, most-connected system at the centre.
 *
 * The ring a system lands on is its rank by degree, and its ANGLE is its
 * neighbours' average angle (one barycentre pass over the rings already
 * placed). Evenly spaced angles alone put a system on the opposite side of
 * the map from the two things it talks to, which draws a line straight
 * across everything else.
 */
export function ringsLayout(nodes, edges) {
    if (! nodes.length) return

    const degree = new Map(nodes.map((n) => [n.id, 0]))
    const neighbours = new Map(nodes.map((n) => [n.id, []]))

    for (const edge of edges) {
        if (! degree.has(edge.source) || ! degree.has(edge.target)) continue
        degree.set(edge.source, degree.get(edge.source) + 1)
        degree.set(edge.target, degree.get(edge.target) + 1)
        neighbours.get(edge.source).push(edge.target)
        neighbours.get(edge.target).push(edge.source)
    }

    const ranked = [...nodes].sort(
        (a, b) => degree.get(b.id) - degree.get(a.id) || a.label.localeCompare(b.label),
    )

    const placed = new Map()
    const hub = ranked.shift()
    hub.tx = 0
    hub.ty = 0
    placed.set(hub.id, { angle: 0, radius: 0 })

    let ring = 0
    while (ranked.length) {
        ring++
        const radius = FIRST_RING + (ring - 1) * RING_GAP
        const capacity = Math.max(6, Math.floor((2 * Math.PI * radius) / MIN_ARC))
        const members = ranked.splice(0, capacity)

        // Preferred angle = the mean direction of whatever is already placed.
        // A system with nothing placed yet keeps its rank order, which spreads
        // the unconnected ones evenly instead of stacking them.
        const preferred = members.map((node, i) => {
            const known = neighbours.get(node.id).map((id) => placed.get(id)).filter(Boolean)
            if (! known.length) return { node, angle: (i / members.length) * Math.PI * 2, loose: true }

            const x = known.reduce((sum, p) => sum + Math.cos(p.angle) * (p.radius || 1), 0)
            const y = known.reduce((sum, p) => sum + Math.sin(p.angle) * (p.radius || 1), 0)

            return { node, angle: Math.atan2(y, x), loose: x === 0 && y === 0 }
        })

        preferred.sort((a, b) => a.angle - b.angle)

        preferred.forEach(({ node }, i) => {
            // Odd rings are offset by half a slot so a system never sits
            // directly behind the one in front of it.
            const angle = ((i + (ring % 2 ? 0.5 : 0)) / preferred.length) * Math.PI * 2
            node.tx = Math.cos(angle) * radius
            node.ty = Math.sin(angle) * radius
            placed.set(node.id, { angle, radius })
        })
    }

    return placed
}

/**
 * Satellites around one parent: the diagrams a system takes part in, on an
 * arc that opens AWAY from the centre of the map, so a cluster never grows
 * back over the graph it came from.
 */
/**
 * Um agrupamento por vez: cada hub num anel grande, e os membros dele em
 * anéis concêntricos em volta do próprio hub.
 *
 * `satelliteLayout` não serve aqui: ela abre os filhos num leque de um raio
 * só, o que é certo para os 2-3 diagramas de um sistema e vira uma fileira
 * sobreposta nos 40 sistemas de uma diretoria. E o anel dos hubs é
 * dimensionado pelo tamanho dos agrupamentos, não por uma constante — senão
 * o maior deles engole os vizinhos.
 */
export function groupsLayout(groups, membersOf, inner = 130, gap = 82) {
    if (! groups.length) return

    const outerRadius = (group) => {
        const total = membersOf(group).length
        let placed = 0
        let ring = 0
        let radius = inner

        while (placed < total) {
            ring++
            radius = inner + (ring - 1) * gap
            placed += Math.max(5, Math.floor((2 * Math.PI * radius) / gap))
        }

        return total ? radius : inner * 0.5
    }

    const radii = groups.map(outerRadius)
    const total = radii.reduce((sum, r) => sum + r, 0)
    // O anel precisa de circunferência para a soma dos diâmetros dos
    // agrupamentos, e de raio suficiente para o maior deles não cruzar o
    // centro. O que for maior manda.
    const ring = groups.length === 1
        ? 0
        : Math.max(Math.max(...radii) * 1.7, (total * 2.4) / (2 * Math.PI))

    // Cada agrupamento ocupa um ARCO PROPORCIONAL ao próprio tamanho, não uma
    // fatia igual: eles chegam ordenados do maior para o menor, então fatias
    // iguais punham os maiores lado a lado com a mesma folga dos menores, e
    // eles se sobrepunham — era a única coisa ilegível na visão de longe.
    let walked = 0

    groups.forEach((group, i) => {
        const share = total ? radii[i] / total : 1 / groups.length
        const angle = (walked + share / 2) * Math.PI * 2 - Math.PI / 2
        walked += share
        group.tx = Math.cos(angle) * ring
        group.ty = Math.sin(angle) * ring
        group.angle = angle

        const members = membersOf(group)
        let placed = 0
        let level = 0

        while (placed < members.length) {
            level++
            const radius = inner + (level - 1) * gap
            const capacity = Math.max(5, Math.floor((2 * Math.PI * radius) / gap))
            const slice = members.slice(placed, placed + capacity)

            slice.forEach((child, j) => {
                // Cada anel gira um pouco, senão os raios se alinham e o
                // agrupamento lê como uma estrela em vez de um disco.
                const a = level * 0.6 + (j / slice.length) * Math.PI * 2
                child.tx = group.tx + Math.cos(a) * radius
                child.ty = group.ty + Math.sin(a) * radius
                child.angle = a
            })

            placed += slice.length
        }
    })
}

export function satelliteLayout(parent, children, radius) {
    const away = Math.atan2(parent.ty ?? parent.y ?? 0, parent.tx ?? parent.x ?? 0) || 0
    const spread = Math.min(Math.PI * 1.6, 0.5 + children.length * 0.42)

    children.forEach((child, i) => {
        const t = children.length === 1 ? 0.5 : i / (children.length - 1)
        const angle = away - spread / 2 + spread * t
        child.tx = (parent.tx ?? parent.x) + Math.cos(angle) * radius
        child.ty = (parent.ty ?? parent.y) + Math.sin(angle) * radius
        child.angle = angle
    })
}

/**
 * A diagram's chain, laid along a ray running outward from the diagram's own
 * block — the reading order of the flow, in the direction the cluster was
 * already growing. A chain is read as a sentence ("SAP → Digibee → SVL →
 * BigQuery"), so a circle would be the wrong shape however well it packed.
 */
export function chainLayout(diagram, steps, spacing) {
    const angle = diagram.angle ?? Math.atan2(diagram.ty ?? 0, diagram.tx ?? 0) ?? 0
    // Perpendicular zig-zag of a few px keeps a long chain from drawing every
    // block on one straight line through the labels of the one before it.
    const nx = Math.cos(angle + Math.PI / 2)
    const ny = Math.sin(angle + Math.PI / 2)

    steps.forEach((step, i) => {
        const along = spacing * (i + 1)
        const wobble = (i % 2 ? 1 : -1) * 26
        step.tx = (diagram.tx ?? diagram.x) + Math.cos(angle) * along + nx * wobble
        step.ty = (diagram.ty ?? diagram.y) + Math.sin(angle) * along + ny * wobble
    })
}

/**
 * d3's simulation over whatever is visible. Link distance carries the
 * hierarchy: a chain step sits close to its diagram, a diagram close to the
 * systems it belongs to, and two systems keep the whole pair length apart.
 */
export function buildSimulation(nodes, links, onTick) {
    const distance = { pair: 260, owns: 120, member: 78, flow: 96 }

    return forceSimulation(nodes)
        .force('link', forceLink(links)
            .id((d) => d.id)
            .distance((l) => distance[l.kind] ?? 140)
            .strength((l) => (l.kind === 'pair' ? 0.25 : 0.6)))
        .force('charge', forceManyBody().strength((d) => (d.type === 'solution' ? -820 : -260)))
        .force('collide', forceCollide((d) => d.radius + 16).strength(0.85))
        .force('center', forceCenter(0, 0))
        .force('x', forceX(0).strength(0.015))
        .force('y', forceY(0).strength(0.015))
        .on('tick', onTick)
}
