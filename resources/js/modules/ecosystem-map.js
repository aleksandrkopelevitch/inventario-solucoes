// Mapa do ecossistema (somente leitura) — layout radial hub-and-spoke.
//
// Cada solução vira um hub (cartão arredondado, mesmo visual do bloco do
// integration-viz) posicionado uma única vez num grid compacto que empacota
// os FOOTPRINTs (cartão + anel de vizinhos, quando expandido) em linhas,
// maiores primeiro. Nada de `dagre`/layout por rank aqui: a maioria dos
// pares/clusters deste grafo é pequena e majoritariamente desconexa entre
// si (muitas soluções com 0-2 vizinhos, alguns hubs bem conectados) — um
// layout por rank (esquerda→direita) empurra tudo que não compartilha
// aresta pro mesmo rank e colapsa numa coluna única (verificado visualmente
// durante a implementação). O grid empacotado dá 2D de verdade
// independente da conectividade.
//
// Uma solução pode aparecer como hub (posição própria) E como satélite no
// anel de outro hub — intencional: evita as curvas cruzando o canvas
// inteiro que emaranhavam o desenho antigo (ver CLAUDE.md/plano). Hubs com
// muitos vizinhos (grau > EXPAND_THRESHOLD) nascem colapsados (só o cartão +
// badge com a contagem); clique na badge expande/colapsa o anel daquele hub
// e reflui o layout inteiro (recalcular o grid é barato nesta escala).
//
// Clique no cartão do hub ou num satélite navega direto pra
// /solucoes/{slug} — mesmo comportamento do mapa antigo. Pan/zoom/fit/tela
// cheia seguem o mesmo padrão de integration-viz.js (view.x/y/scale sobre um
// #world com um único transform, Fullscreen API real).

const SVG_NS = 'http://www.w3.org/2000/svg'
const MIN_SCALE = 0.15
const MAX_SCALE = 2.5
const FIT_PAD = 60
const EXPAND_THRESHOLD = 6
const SATELLITE_SIZE = 30
const RING_GAP = 10
const HUB_MARGIN = 60
const HUB_EDGE_GAP = 6
const GRID_GAP = 28

const mounted = new WeakSet()
let uidCounter = 0

export function init() {
    document.querySelectorAll('[data-ecosystem-map]').forEach(mount)
}

// Mesmo fallback do catálogo (`x-ui.logo`), refeito em DOM puro — os nós
// deste mapa não passam por Blade (chegam via fetch), mesma razão de
// integration-viz.js.
function buildAvatar(data, extraClass) {
    const avatar = document.createElement('span')
    avatar.className = 'ak-viz-node-avatar' + (extraClass ? ' ' + extraClass : '')
    if (data.logo) {
        const img = document.createElement('img')
        img.src = data.logo
        img.alt = ''
        avatar.appendChild(img)
    } else {
        avatar.classList.add('is-fallback')
        avatar.textContent = (data.label ?? '').trim().charAt(0).toUpperCase() || '?'
    }
    return avatar
}

function paintHub(el, data) {
    el.innerHTML = ''
    const body = document.createElement('div')
    body.className = 'ak-viz-node-body'
    body.appendChild(buildAvatar(data))
    const text = document.createElement('span')
    text.textContent = data.label ?? '?'
    body.appendChild(text)
    el.appendChild(body)
}

function mount(root) {
    if (mounted.has(root)) return
    mounted.add(root)

    const viewport = root.querySelector('[data-eco-viewport]')
    const world = root.querySelector('[data-eco-world]')
    const edgesSvg = root.querySelector('[data-eco-edges]')
    const statusEl = root.querySelector('[data-eco-status]')
    const loadingEl = root.querySelector('[data-eco-loading]')
    const emptyEl = root.querySelector('[data-eco-empty]')
    const zoomLabel = root.querySelector('[data-eco-zoom-label]')
    const markerEnd = root.querySelector('[data-eco-marker-end]')
    const markerStart = root.querySelector('[data-eco-marker-start]')
    const zoomInBtn = root.querySelector('[data-eco-zoom-in]')
    const zoomOutBtn = root.querySelector('[data-eco-zoom-out]')
    const fitBtn = root.querySelector('[data-eco-fit]')
    const fullscreenBtn = root.querySelector('[data-eco-fullscreen]')
    const fsOpenIcon = root.querySelector('[data-eco-fs-open]')
    const fsCloseIcon = root.querySelector('[data-eco-fs-close]')

    const navBase = root.dataset.navBase || ''
    const uid = 'akeco' + ++uidCounter
    markerEnd.id = uid + '-end'
    markerStart.id = uid + '-start'

    const view = { x: FIT_PAD, y: FIT_PAD, scale: 1 }
    const expandState = new Map() // nodeId -> bool, sobrevive a re-fetches (troca de filtro) na mesma sessão
    let hubs = []
    let graphRef = null
    let panning = false
    let panStart = null

    function applyView() {
        world.style.transform = `translate(${view.x}px,${view.y}px) scale(${view.scale})`
        if (zoomLabel) zoomLabel.textContent = Math.round(view.scale * 100) + '%'
    }

    function screenToWorld(clientX, clientY) {
        const r = viewport.getBoundingClientRect()
        return { x: (clientX - r.left - view.x) / view.scale, y: (clientY - r.top - view.y) / view.scale }
    }

    function zoomBy(factor, aroundClientX, aroundClientY) {
        const r = viewport.getBoundingClientRect()
        const cx = aroundClientX ?? r.left + r.width / 2
        const cy = aroundClientY ?? r.top + r.height / 2
        const before = screenToWorld(cx, cy)
        view.scale = Math.max(MIN_SCALE, Math.min(MAX_SCALE, view.scale * factor))
        view.x = cx - r.left - before.x * view.scale
        view.y = cy - r.top - before.y * view.scale
        applyView()
    }

    function fit() {
        if (!hubs.length) return
        let x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity
        hubs.forEach((h) => {
            const pad = ringPad(h)
            x0 = Math.min(x0, h.x - pad)
            y0 = Math.min(y0, h.y - pad)
            x1 = Math.max(x1, h.x + h.w + pad)
            y1 = Math.max(y1, h.y + h.h + pad)
        })
        const cw = Math.max(1, x1 - x0)
        const ch = Math.max(1, y1 - y0)
        const r = viewport.getBoundingClientRect()
        const scale = Math.min(1.25, (r.width - FIT_PAD * 2) / cw, (r.height - FIT_PAD * 2) / ch)
        view.scale = Math.max(MIN_SCALE, Math.min(MAX_SCALE, scale || 1))
        view.x = (r.width - cw * view.scale) / 2 - x0 * view.scale
        view.y = (r.height - ch * view.scale) / 2 - y0 * view.scale
        applyView()
    }

    function toggleFullscreen() {
        if (document.fullscreenElement === root) document.exitFullscreen?.()
        else root.requestFullscreen?.()
    }
    root.addEventListener('fullscreenchange', () => {
        const isFs = document.fullscreenElement === root
        fsOpenIcon?.classList.toggle('hidden', isFs)
        fsCloseIcon?.classList.toggle('hidden', !isFs)
        requestAnimationFrame(() => requestAnimationFrame(fit))
    })

    viewport.addEventListener('mousedown', (e) => {
        if (e.button !== 0) return
        panning = true
        panStart = { x: e.clientX, y: e.clientY, vx: view.x, vy: view.y }
        viewport.classList.add('is-panning')
    })
    window.addEventListener('mousemove', (e) => {
        if (!panning || !panStart) return
        view.x = panStart.vx + (e.clientX - panStart.x)
        view.y = panStart.vy + (e.clientY - panStart.y)
        applyView()
    })
    window.addEventListener('mouseup', () => {
        panning = false
        panStart = null
        viewport.classList.remove('is-panning')
    })
    viewport.addEventListener(
        'wheel',
        (e) => {
            e.preventDefault()
            zoomBy(e.deltaY < 0 ? 1.08 : 0.926, e.clientX, e.clientY)
        },
        { passive: false }
    )
    zoomInBtn?.addEventListener('click', () => zoomBy(1.2))
    zoomOutBtn?.addEventListener('click', () => zoomBy(0.833))
    fitBtn?.addEventListener('click', fit)
    fullscreenBtn?.addEventListener('click', toggleFullscreen)

    function navigateTo(slug) {
        if (slug) window.location.href = navBase + '/' + slug
    }

    // Grau acima do threshold nasce colapsado; ao ou abaixo, expandido — a
    // não ser que o usuário já tenha alternado esse hub nesta sessão
    // (preserva a escolha entre trocas de filtro, que recarregam o grafo).
    function defaultExpanded(nodeId, degree) {
        if (expandState.has(nodeId)) return expandState.get(nodeId)
        return degree <= EXPAND_THRESHOLD
    }

    // Raio do anel: cabe a diagonal do cartão + os satélites sem que dois
    // satélites vizinhos se sobreponham (perímetro mínimo pro nº de
    // vizinhos), com folga suficiente pra sobrar um spoke visível entre a
    // borda do hub e o satélite (`drawSpoke()` corta ~hubGap de um lado e
    // ~satGap do outro — o raio não pode ficar perto demais desses dois
    // cortes juntos, senão a linha desenhada fica menor que 1px).
    function ringRadius(hub) {
        if (!hub.expanded || hub.degree === 0) return 0
        const base = Math.hypot(hub.w, hub.h) / 2 + 50
        const byCount = (hub.degree * (SATELLITE_SIZE + RING_GAP)) / (2 * Math.PI)
        return Math.max(base, byCount)
    }

    function ringPad(hub) {
        return hub.expanded && hub.degree > 0 ? ringRadius(hub) + SATELLITE_SIZE : 0
    }

    // Tamanho reservado pro hub no grid empacotado (`layout()`) — quadrado
    // que cabe o anel inteiro quando expandido, ou só o cartão (+ badge) quando não.
    function footprint(hub) {
        if (hub.expanded && hub.degree > 0) {
            const side = 2 * (ringRadius(hub) + SATELLITE_SIZE / 2) + HUB_MARGIN
            return { w: side, h: side }
        }
        return { w: hub.w + HUB_MARGIN / 2, h: hub.h + HUB_MARGIN / 2 }
    }

    // Grid empacotado: maiores footprints primeiro (hubs expandidos com anel
    // grande viram "âncoras" visuais, o resto preenche ao redor) — sem
    // depender de conectividade. A largura de quebra de linha NÃO é a
    // largura literal do viewport (isso produzia uma coluna alta e estreita
    // quando a soma dos footprints era grande — `fit()` então precisava
    // zerar o zoom quase todo pra caber uma torre vertical numa tela
    // widescreen); em vez disso, mira a MESMA proporção do viewport a
    // partir da área total ocupada (`largura = sqrt(área * proporção)`),
    // pra o retângulo resultante já nascer parecido com o formato da tela.
    function layout() {
        const rect = viewport.getBoundingClientRect()
        const aspect = rect.width && rect.height ? rect.width / rect.height : 16 / 9
        const sorted = [...hubs].sort((a, b) => {
            const fa = footprint(a)
            const fb = footprint(b)

            return fb.w * fb.h - fa.w * fa.h
        })
        const totalArea = sorted.reduce((sum, h) => {
            const f = footprint(h)

            return sum + f.w * f.h
        }, 0)
        const rowWidth = Math.max(600, Math.sqrt(totalArea * aspect))

        let x = GRID_GAP
        let y = GRID_GAP
        let rowH = 0
        sorted.forEach((h) => {
            const f = footprint(h)
            if (x > GRID_GAP && x + f.w > rowWidth) {
                x = GRID_GAP
                y += rowH + GRID_GAP
                rowH = 0
            }
            h.x = x + (f.w - h.w) / 2
            h.y = y + (f.h - h.h) / 2
            x += f.w + GRID_GAP
            rowH = Math.max(rowH, f.h)
        })
    }

    function clearRing(hub) {
        hub.satelliteEls?.forEach((el) => el.remove())
        hub.satelliteEls = []
        hub.badgeEl?.remove()
        hub.badgeEl = null
    }

    function clearEdges() {
        edgesSvg.querySelectorAll('.ak-eco-spoke').forEach((el) => el.remove())
    }

    function relayout() {
        layout()
        hubs.forEach((h) => {
            h.el.style.left = h.x + 'px'
            h.el.style.top = h.y + 'px'
        })
        draw()
        fit()
    }

    function toggleHub(hub) {
        hub.expanded = !hub.expanded
        expandState.set(hub.id, hub.expanded)
        relayout()
    }

    function drawBadge(hub) {
        if (hub.degree === 0) return
        const badge = document.createElement('button')
        badge.type = 'button'
        badge.className = 'ak-eco-badge' + (hub.expanded ? ' is-open' : '')
        badge.textContent = hub.expanded ? '−' : String(hub.degree)
        badge.title = hub.expanded
            ? 'Recolher conexões'
            : `${hub.degree} conexõ${hub.degree === 1 ? 'ão' : 'ões'} — clique para expandir`
        badge.addEventListener('mousedown', (e) => e.stopPropagation())
        badge.addEventListener('click', (e) => {
            e.stopPropagation()
            toggleHub(hub)
        })
        hub.el.appendChild(badge)
        hub.badgeEl = badge
    }

    // Seta do centro do hub até o satélite, com o gap de integration-viz (a
    // ponta não invade nem o cartão nem o círculo do satélite) e marcador
    // conforme o sentido observado do PAR (hub é source/target/os dois).
    function drawSpoke(hub, neighbor) {
        const cx = hub.x + hub.w / 2
        const cy = hub.y + hub.h / 2
        const dx = neighbor.sx - cx
        const dy = neighbor.sy - cy
        const dist = Math.hypot(dx, dy) || 1
        const nx = dx / dist
        const ny = dy / dist
        const hubGap = Math.hypot(hub.w, hub.h) / 2 + HUB_EDGE_GAP
        const satGap = SATELLITE_SIZE / 2 + 2
        const x0 = cx + nx * hubGap
        const y0 = cy + ny * hubGap
        const x1 = neighbor.sx - nx * satGap
        const y1 = neighbor.sy - ny * satGap

        const path = document.createElementNS(SVG_NS, 'path')
        path.setAttribute('class', 'ak-viz-edge ak-eco-spoke')
        path.setAttribute('d', `M ${x0} ${y0} L ${x1} ${y1}`)

        const edge = neighbor.edge
        const isSource = edge.source === hub.id
        const bidirectional = edge.direction === 'bidirectional'
        if (bidirectional || isSource) path.setAttribute('marker-end', `url(#${markerEnd.id})`)
        if (bidirectional || !isSource) path.setAttribute('marker-start', `url(#${markerStart.id})`)

        const titleParts = [neighbor.node.label]
        if (edge.label) titleParts.push(edge.label)
        path.setAttribute('data-title', titleParts.join(' · '))
        const title = document.createElementNS(SVG_NS, 'title')
        title.textContent = titleParts.join(' · ')
        path.appendChild(title)

        edgesSvg.appendChild(path)
    }

    function drawRing(hub) {
        clearRing(hub)
        drawBadge(hub)
        if (!hub.expanded || hub.degree === 0) return

        const cx = hub.x + hub.w / 2
        const cy = hub.y + hub.h / 2
        const radius = ringRadius(hub)

        hub.neighbors.forEach((neighbor, i) => {
            const angle = (i / hub.neighbors.length) * Math.PI * 2 - Math.PI / 2
            neighbor.sx = cx + radius * Math.cos(angle)
            neighbor.sy = cy + radius * Math.sin(angle)

            drawSpoke(hub, neighbor)

            const sat = buildAvatar(neighbor.node, 'ak-eco-satellite')
            sat.style.left = neighbor.sx + 'px'
            sat.style.top = neighbor.sy + 'px'
            const titleParts = [neighbor.node.label]
            if (neighbor.edge.label) titleParts.push(neighbor.edge.label)
            sat.title = titleParts.join(' · ')
            sat.addEventListener('mousedown', (e) => e.stopPropagation())
            sat.addEventListener('click', (e) => {
                e.stopPropagation()
                navigateTo(neighbor.node.slug)
            })
            world.appendChild(sat)
            hub.satelliteEls.push(sat)
        })
    }

    function draw() {
        clearEdges()
        hubs.forEach(drawRing)
    }

    function updateStatus() {
        const pairs = graphRef?.edges?.length ?? 0
        statusEl.textContent = `${hubs.length} soluções · ${pairs} ligaç${pairs === 1 ? 'ão' : 'ões'} · zoom ${Math.round(view.scale * 100)}%`
    }

    function clearWorld() {
        hubs.forEach((h) => {
            clearRing(h)
            h.el.remove()
        })
        hubs = []
        clearEdges()
    }

    function render(graph) {
        clearWorld()
        graphRef = graph

        if (!graph || !Array.isArray(graph.nodes) || graph.nodes.length === 0) {
            emptyEl.classList.remove('hidden')
            return
        }
        emptyEl.classList.add('hidden')

        const byId = new Map(graph.nodes.map((n) => [n.id, n]))
        const neighborsOf = new Map(graph.nodes.map((n) => [n.id, []]))
        ;(graph.edges || []).forEach((edge) => {
            if (!byId.has(edge.source) || !byId.has(edge.target)) return
            neighborsOf.get(edge.source).push({ node: byId.get(edge.target), edge })
            neighborsOf.get(edge.target).push({ node: byId.get(edge.source), edge })
        })

        hubs = graph.nodes.map((data) => {
            const el = document.createElement('div')
            el.className = 'ak-viz-node ak-eco-hub'
            paintHub(el, data)
            el.addEventListener('mousedown', (e) => e.stopPropagation())
            el.addEventListener('click', () => navigateTo(data.slug))
            world.appendChild(el)

            const neighbors = neighborsOf.get(data.id) || []
            const degree = neighbors.length

            return {
                ...data,
                el,
                neighbors,
                degree,
                expanded: defaultExpanded(data.id, degree),
                satelliteEls: [],
                badgeEl: null,
                w: 0,
                h: 0,
                x: 0,
                y: 0,
            }
        })
        hubs.forEach((h) => {
            h.w = h.el.offsetWidth
            h.h = h.el.offsetHeight
        })

        layout()
        hubs.forEach((h) => {
            h.el.style.left = h.x + 'px'
            h.el.style.top = h.y + 'px'
        })

        draw()
        updateStatus()
        fit()
    }

    function fetchAndRender(url) {
        loadingEl.classList.remove('hidden')
        emptyEl.classList.add('hidden')
        fetch(url, { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((data) => render(data))
            .catch(() => {
                statusEl.textContent = 'Erro ao carregar o grafo.'
            })
            .finally(() => loadingEl.classList.add('hidden'))
    }

    root.__ecosystemMapReload = fetchAndRender

    function boot() {
        if (root.dataset.sourceUrl) {
            fetchAndRender(root.dataset.sourceUrl)
        } else {
            loadingEl.classList.add('hidden')
            emptyEl.classList.remove('hidden')
        }
    }

    document.addEventListener('DOMContentLoaded', boot)
    if (document.readyState !== 'loading') boot()
}
