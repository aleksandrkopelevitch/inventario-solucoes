// The two arrangements the links view of the ecosystem map offers (the
// hosting view lays out its own containers, in ecosystem-map.js).
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

/** d3's simulation over the systems, two of them kept a pair length apart. */
export function buildSimulation(nodes, links, onTick) {
    return forceSimulation(nodes)
        .force('link', forceLink(links)
            .id((d) => d.id)
            .distance(260)
            .strength(0.25))
        .force('charge', forceManyBody().strength(-820))
        .force('collide', forceCollide((d) => d.radius + 16).strength(0.85))
        .force('center', forceCenter(0, 0))
        .force('x', forceX(0).strength(0.015))
        .force('y', forceY(0).strength(0.015))
        .on('tick', onTick)
}
