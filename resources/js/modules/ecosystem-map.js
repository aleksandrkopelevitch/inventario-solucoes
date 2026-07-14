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
// Vizinhos (satélites) são o MESMO cartão do hub (avatar + nome, variante
// compacta `.ak-eco-satellite-card`) — não um avatar-só — pra sempre dar
// pra ler o nome, tanto do hub quanto de cada satélite. O raio do anel é
// derivado do tamanho REAL medido de cada cartão (hub + maior satélite +
// perímetro necessário pro nº de vizinhos), não uma constante fixa — um hub
// de grau 1 fica bem próximo do seu único vizinho.
//
// Uma solução pode aparecer como hub (posição própria) E como satélite no
// anel de outro hub — intencional: evita as curvas cruzando o canvas
// inteiro que emaranhavam o desenho antigo (ver CLAUDE.md/plano). Hubs com
// muitos vizinhos (grau > EXPAND_THRESHOLD) nascem colapsados (só o cartão +
// badge com a contagem); clique na badge expande/colapsa o anel daquele hub
// SEM tocar no `layout()` do grid — nenhum outro hub muda de lugar, e o
// hub alternado também fica onde estava (só o anel dele aparece/desaparece
// ao redor). Recalcular o grid inteiro a cada toggle fazia a view inteira
// reposicionar de forma imprevisível a cada clique — desorientador.
//
// Clique num cartão (hub ou satélite) abre um popover com os atributos da
// solução + botão "Ver mais" (nova aba) — não navega direto. Pan/zoom/fit/
// tela cheia seguem o mesmo padrão de integration-viz.js (view.x/y/scale
// sobre um #world com um único transform, Fullscreen API real).

const SVG_NS = 'http://www.w3.org/2000/svg'
const MIN_SCALE = 0.15
const MAX_SCALE = 2.5
const FIT_PAD = 60
const EXPAND_THRESHOLD = 6
const RING_GAP = 8 // espaço mínimo entre satélites vizinhos ao redor do anel
const HUB_MARGIN = 18 // folga do footprint em torno do hub/anel, pro grid não colar clusters
const HUB_EDGE_GAP = 4 // afasta a ponta da seta da borda do hub
const SAT_EDGE_GAP = 4 // afasta a ponta da seta da borda do satélite
const GRID_GAP = 12 // espaço entre clusters (hub+anel) no grid empacotado
const DRAG_THRESHOLD = 4 // px de tela — abaixo disso um mousedown+mouseup ainda conta como clique (abre popover), não arraste
const FOCUS_SCALE = 1 // zoom aplicado ao focar um sistema pela busca — legível sem aproximar demais

const mounted = new WeakSet()
let uidCounter = 0

export function init() {
    document.querySelectorAll('[data-ecosystem-map]').forEach(mount)
}

// Mesmo fallback do catálogo (`x-ui.logo`), refeito em DOM puro — os nós
// deste mapa não passam por Blade (chegam via fetch), mesma razão de
// integration-viz.js.
function buildAvatar(data) {
    const avatar = document.createElement('span')
    avatar.className = 'ak-viz-node-avatar'
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

// Pinta o conteúdo de um cartão (avatar + nome) — usado tanto pro hub quanto
// pro satélite, a única diferença entre os dois é a classe CSS aplicada ao
// elemento raiz (`.ak-eco-hub` vs `.ak-eco-satellite-card`).
function paintCard(el, data) {
    el.innerHTML = ''
    const body = document.createElement('div')
    body.className = 'ak-viz-node-body'
    body.appendChild(buildAvatar(data))
    const text = document.createElement('span')
    text.textContent = data.label ?? '?'
    body.appendChild(text)
    el.appendChild(body)
}

function attrRow(label, value) {
    const row = document.createElement('div')
    row.className = 'ak-eco-popover-attr'
    const l = document.createElement('span')
    l.className = 'ak-eco-popover-attr-label'
    l.textContent = label
    const v = document.createElement('span')
    v.className = 'ak-eco-popover-attr-value'
    v.textContent = value || '—'
    row.append(l, v)
    return row
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
    const searchOpenBtn = root.querySelector('[data-eco-search-open]')
    const searchOverlay = root.querySelector('[data-eco-search-overlay]')
    const searchInput = root.querySelector('[data-eco-search-input]')
    const searchResultsEl = root.querySelector('[data-eco-search-results]')
    const searchEmptyEl = root.querySelector('[data-eco-search-empty]')

    const uid = 'akeco' + ++uidCounter
    markerEnd.id = uid + '-end'
    markerStart.id = uid + '-start'

    const view = { x: FIT_PAD, y: FIT_PAD, scale: 1 }
    const expandState = new Map() // nodeId -> bool, sobrevive a re-fetches (troca de filtro) na mesma sessão
    let hubs = []
    let graphRef = null
    let panning = false
    let panStart = null
    let popoverEl = null
    let popoverAnchor = null
    let searchMatches = []
    let searchActiveIndex = -1
    let focusTimer = null

    function applyView() {
        world.style.transform = `translate(${view.x}px,${view.y}px) scale(${view.scale})`
        if (zoomLabel) zoomLabel.textContent = Math.round(view.scale * 100) + '%'
        // O popover não fecha em pan/zoom (só Esc/[X] — ver `closePopover`
        // callers), então precisa acompanhar o anchor pra não ficar
        // flutuando desconectado do cartão enquanto a view se move.
        if (popoverEl && popoverAnchor) positionPopover(popoverEl, popoverAnchor)
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

    // ── popover de atributos ────────────────────────────────────────
    function closePopover() {
        popoverEl?.remove()
        popoverEl = null
        popoverAnchor = null
    }

    function positionPopover(pop, anchorEl) {
        const rootRect = root.getBoundingClientRect()
        const anchorRect = anchorEl.getBoundingClientRect()
        const pw = pop.offsetWidth
        const ph = pop.offsetHeight

        let left = anchorRect.left - rootRect.left + anchorRect.width / 2 - pw / 2
        left = Math.max(8, Math.min(left, rootRect.width - pw - 8))

        let top = anchorRect.top - rootRect.top + anchorRect.height + 10
        if (top + ph > rootRect.height - 8) {
            top = anchorRect.top - rootRect.top - ph - 10
        }
        top = Math.max(8, top)

        pop.style.left = left + 'px'
        pop.style.top = top + 'px'
    }

    function openPopover(node, anchorEl) {
        closePopover()

        const pop = document.createElement('div')
        pop.className = 'ak-eco-popover'
        pop.addEventListener('mousedown', (e) => e.stopPropagation())
        pop.addEventListener('click', (e) => e.stopPropagation())

        const head = document.createElement('div')
        head.className = 'ak-eco-popover-head'

        const title = document.createElement('div')
        title.className = 'ak-eco-popover-title'
        title.textContent = node.label ?? ''
        head.appendChild(title)

        const closeBtn = document.createElement('button')
        closeBtn.type = 'button'
        closeBtn.className = 'ak-eco-popover-close'
        closeBtn.setAttribute('aria-label', 'Fechar')
        closeBtn.textContent = '×'
        closeBtn.addEventListener('click', (e) => {
            e.stopPropagation()
            closePopover()
        })
        head.appendChild(closeBtn)

        pop.appendChild(head)

        const grid = document.createElement('div')
        grid.className = 'ak-eco-popover-grid'
        grid.append(
            attrRow('Categoria', node.categoryLabel),
            attrRow('Status', node.statusLabel),
            attrRow('Criticidade', node.criticalityLabel),
            attrRow('Ambiente', node.environmentLabel),
            attrRow('Hospedagem', node.cloudLabel),
            attrRow('Contrato', node.contractLabel),
            attrRow('Suporte', node.supportLabel),
            attrRow('Diretoria', node.directorate)
        )
        pop.appendChild(grid)

        const link = document.createElement('a')
        link.className = 'ak-eco-popover-more'
        link.href = node.url || '#'
        link.target = '_blank'
        link.rel = 'noopener'
        link.textContent = 'Ver mais'
        pop.appendChild(link)

        root.appendChild(pop)
        positionPopover(pop, anchorEl)
        popoverEl = pop
        popoverAnchor = anchorEl
    }

    // Só fecha no [X] (botão no próprio popover) ou Esc — clicar fora
    // (canvas, pan, zoom, sidebar, outro filtro) NUNCA fecha o popover
    // sozinho. Isto é deliberado só neste mapa: o usuário quer poder
    // clicar/arrastar o canvas com o popover aberto pra comparar um cartão
    // com outro, sem o popover sumir no meio do gesto.
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closePopover()
    })

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

    // ── busca de sistema (Ctrl+K / Cmd+K ou lupa) ──────────────────
    // `display` inline (não a classe `hidden`) pela mesma razão do satélite
    // acima — evita depender de quem vence o empate de especificidade com
    // o CSS local deste componente.
    function openSearch() {
        if (!hubs.length) return
        searchOverlay.style.display = 'flex'
        searchInput.value = ''
        renderSearchResults('')
        requestAnimationFrame(() => searchInput.focus())
    }

    function closeSearch() {
        searchOverlay.style.display = 'none'
    }

    function setSearchActive(i) {
        searchActiveIndex = i
        ;[...searchResultsEl.children].forEach((el, idx) => el.classList.toggle('is-active', idx === i))
    }

    // Foca (pan+zoom centralizado) e destaca por alguns segundos o hub
    // escolhido — não navega pra outra página, só localiza no próprio mapa.
    function focusHub(hub) {
        const r = viewport.getBoundingClientRect()
        view.scale = FOCUS_SCALE
        view.x = r.width / 2 - (hub.x + hub.w / 2) * view.scale
        view.y = r.height / 2 - (hub.y + hub.h / 2) * view.scale
        applyView()

        hub.el.classList.remove('is-focused')
        void hub.el.offsetWidth // força reflow — reinicia a animação mesmo se o hub já estava em foco
        hub.el.classList.add('is-focused')
        clearTimeout(focusTimer)
        focusTimer = setTimeout(() => hub.el.classList.remove('is-focused'), 2200)
    }

    function selectSearchResult(hub) {
        closeSearch()
        focusHub(hub)
    }

    // Busca só entre hubs (todo nó do grafo tem uma posição própria no
    // grid — "sistema principal" é qualquer um deles), não entre cartões-
    // satélite (que são só a mesma solução redesenhada dentro do anel de
    // outro hub, não um alvo de foco à parte).
    function renderSearchResults(query) {
        const q = query.trim().toLowerCase()
        searchMatches = !q ? hubs : hubs.filter((h) => (h.label ?? '').toLowerCase().includes(q))
        searchMatches = searchMatches.slice(0, 30)
        searchResultsEl.innerHTML = ''
        searchEmptyEl.classList.toggle('hidden', searchMatches.length > 0)

        searchMatches.forEach((hub, i) => {
            const li = document.createElement('li')
            li.className = 'ak-eco-search-item'
            const body = document.createElement('div')
            body.className = 'ak-viz-node-body'
            body.appendChild(buildAvatar(hub))
            const text = document.createElement('span')
            text.className = 'ak-eco-search-item-label'
            text.textContent = hub.label ?? '?'
            body.appendChild(text)
            li.appendChild(body)
            if (hub.categoryLabel) {
                const meta = document.createElement('span')
                meta.className = 'ak-eco-search-item-meta'
                meta.textContent = hub.categoryLabel
                li.appendChild(meta)
            }
            li.addEventListener('mousedown', (e) => e.preventDefault())
            li.addEventListener('mouseenter', () => setSearchActive(i))
            li.addEventListener('click', () => selectSearchResult(hub))
            searchResultsEl.appendChild(li)
        })

        setSearchActive(searchMatches.length ? 0 : -1)
    }

    searchOpenBtn?.addEventListener('click', () => {
        if (searchOverlay.style.display === 'flex') closeSearch()
        else openSearch()
    })
    searchInput?.addEventListener('input', () => renderSearchResults(searchInput.value))
    searchInput?.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown') {
            e.preventDefault()
            if (searchMatches.length) setSearchActive((searchActiveIndex + 1) % searchMatches.length)
        } else if (e.key === 'ArrowUp') {
            e.preventDefault()
            if (searchMatches.length) setSearchActive((searchActiveIndex - 1 + searchMatches.length) % searchMatches.length)
        } else if (e.key === 'Enter') {
            e.preventDefault()
            if (searchActiveIndex >= 0) selectSearchResult(searchMatches[searchActiveIndex])
        } else if (e.key === 'Escape') {
            e.preventDefault()
            closeSearch()
        }
    })
    // Clique no overlay fora do painel fecha (mesmo padrão do side-panel
    // global) — clique dentro do painel (input, lista) não propaga por não
    // ter listener de fechamento.
    searchOverlay?.addEventListener('mousedown', (e) => {
        if (e.target === searchOverlay) closeSearch()
    })

    // Não intercepta se o usuário já está digitando em outro campo de texto
    // da página (ex.: um filtro fora deste componente) — Ctrl+K é um atalho
    // "global" da página, mas não deve roubar teclas de um input alheio.
    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            const active = document.activeElement
            const typingElsewhere =
                active &&
                active !== searchInput &&
                (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA' || active.isContentEditable)
            if (typingElsewhere || root.offsetParent === null) return
            e.preventDefault()
            openSearch()
        }
    })

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

    // Grau acima do threshold nasce colapsado; ao ou abaixo, expandido — a
    // não ser que o usuário já tenha alternado esse hub nesta sessão
    // (preserva a escolha entre trocas de filtro, que recarregam o grafo).
    function defaultExpanded(nodeId, degree) {
        if (expandState.has(nodeId)) return expandState.get(nodeId)
        return degree <= EXPAND_THRESHOLD
    }

    // Maior "raio" (metade da diagonal) entre os cartões-satélite do hub —
    // usado tanto pro raio mínimo do anel quanto pro footprint reservado.
    function maxNeighborRadius(hub) {
        return hub.neighbors.reduce((m, n) => Math.max(m, Math.hypot(n.w, n.h) / 2), 0)
    }

    // Perímetro necessário pra caber todos os satélites lado a lado sem
    // sobrepor (soma da "largura" de cada um, não um valor médio/fixo —
    // cartões de nomes longos ocupam mais fatia do círculo).
    function neighborCircumference(hub) {
        return hub.neighbors.reduce((sum, n) => sum + Math.hypot(n.w, n.h) + RING_GAP, 0)
    }

    // Raio do anel: ancorado no tamanho REAL medido dos cartões (hub +
    // maior satélite + uma folga pequena) — não mais uma constante fixa
    // desconectada do conteúdo. Um hub de grau 1 fica bem próximo do seu
    // único vizinho; o piso por perímetro só entra quando há vizinhos
    // suficientes pra precisarem de mais espaço ao redor.
    function ringRadius(hub) {
        if (!hub.expanded || hub.degree === 0) return 0
        const base = Math.hypot(hub.w, hub.h) / 2 + RING_GAP + maxNeighborRadius(hub)
        const byCircumference = neighborCircumference(hub) / (2 * Math.PI)
        return Math.max(base, byCircumference)
    }

    function ringPad(hub) {
        return hub.expanded && hub.degree > 0 ? ringRadius(hub) + maxNeighborRadius(hub) : 0
    }

    // Tamanho reservado pro hub no grid empacotado (`layout()`) — quadrado
    // que cabe o anel inteiro quando expandido, ou só o cartão quando não.
    function footprint(hub) {
        if (hub.expanded && hub.degree > 0) {
            const side = 2 * (ringRadius(hub) + maxNeighborRadius(hub)) + HUB_MARGIN
            return { w: side, h: side }
        }
        return { w: hub.w + HUB_MARGIN, h: hub.h + HUB_MARGIN }
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
    // Hubs com `mapPosition` salva (arrastados e persistidos em algum
    // momento — `saveHubPosition()`) pulam o empacotamento automático e vão
    // direto pra posição salva; só os demais (`auto`) entram no grid
    // empacotado, como se os manuais nem existissem (pode sobrepor um
    // cluster manual — aceitável, é o preço de deixar o usuário fixar a
    // posição; ele pode arrastar de novo pra abrir espaço).
    function layout() {
        const rect = viewport.getBoundingClientRect()
        const aspect = rect.width && rect.height ? rect.width / rect.height : 16 / 9
        const manual = hubs.filter((h) => h.mapPosition)
        const auto = hubs.filter((h) => !h.mapPosition)

        manual.forEach((h) => {
            h.x = h.mapPosition.x
            h.y = h.mapPosition.y
        })

        const sorted = [...auto].sort((a, b) => {
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

    function clearEdges() {
        edgesSvg.querySelectorAll('.ak-eco-spoke, .ak-eco-halo').forEach((el) => el.remove())
    }

    // Expandir/colapsar NUNCA move nada: nem o hub alternado, nem os
    // demais hubs, nem a view (pan/zoom). `layout()` (o grid empacotado)
    // só corre na carga inicial/troca de filtro — aqui só redesenha o
    // anel daquele hub (mostra/esconde satélites, refaz spokes/halo) em
    // cima da posição que já existia. Um anel que cresce pode passar por
    // cima do cartão de um cluster vizinho — aceitável (o usuário pode
    // recolher de novo), o alternativo (reempacotar tudo) é o que fazia a
    // tela toda pular a cada clique.
    function toggleHub(hub) {
        closePopover()
        hub.expanded = !hub.expanded
        expandState.set(hub.id, hub.expanded)
        draw()
        updateStatus()
    }

    // Arrasta um hub (sistema primário) pra reposicioná-lo — só o cartão
    // solto do `layout()` do grid empacotado, que não roda de novo depois
    // (só na carga inicial/troca de filtro, igual ao `toggleHub`). O anel
    // de satélites do próprio hub (se expandido) e seus spokes/halo
    // acompanham em tempo real porque `draw()` deriva a posição deles de
    // `hub.x/y` a cada chamada — nenhum outro hub se move.
    function startHubDrag(hub, downEvent) {
        const startX = downEvent.clientX
        const startY = downEvent.clientY
        const startHubX = hub.x
        const startHubY = hub.y
        let dragging = false
        let rafId = null

        function apply() {
            rafId = null
            hub.el.style.left = hub.x + 'px'
            hub.el.style.top = hub.y + 'px'
            draw()
            if (popoverEl && popoverAnchor === hub.el) positionPopover(popoverEl, popoverAnchor)
        }

        function onMove(e) {
            if (!dragging) {
                if (Math.hypot(e.clientX - startX, e.clientY - startY) < DRAG_THRESHOLD) return
                dragging = true
                hub.didDrag = true
                hub.el.classList.add('is-dragging')
            }
            hub.x = startHubX + (e.clientX - startX) / view.scale
            hub.y = startHubY + (e.clientY - startY) / view.scale
            if (rafId == null) rafId = requestAnimationFrame(apply)
        }

        function onUp() {
            window.removeEventListener('mousemove', onMove)
            window.removeEventListener('mouseup', onUp)
            hub.el.classList.remove('is-dragging')
            if (rafId != null) {
                cancelAnimationFrame(rafId)
                apply()
            }
            // Só persiste se o mousedown virou arraste de verdade — um
            // clique parado (abre popover) não deve gerar um PATCH à toa.
            if (dragging) {
                hub.mapPosition = { x: hub.x, y: hub.y }
                saveHubPosition(hub)
            }
        }

        window.addEventListener('mousemove', onMove)
        window.addEventListener('mouseup', onUp)
    }

    // Auto-save silencioso — sem botão/toast de confirmação, é uma
    // customização de layout, não uma ação que precisa de feedback (mesmo
    // padrão de "salva sozinho" do `solution-attributes.js`). Erro de rede
    // só aparece na barra de status pra não incomodar com modal/toast no
    // meio de um arraste; a posição já está correta na tela, só não foi
    // persistida — o próximo arraste tenta de novo.
    function saveHubPosition(hub) {
        if (!hub.positionUrl) return
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')

        fetch(hub.positionUrl, {
            method: 'PATCH',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify({ x: hub.x, y: hub.y }),
        }).catch(() => {
            statusEl.textContent = 'Não foi possível salvar a posição do sistema.'
        })
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

    // Distância do centro até a BORDA real de um cartão retangular w×h, na
    // direção (nx,ny) — não o raio da circunferência circunscrita
    // (`hypot(w,h)/2`, usada antes). Pra um cartão bem mais largo que alto
    // (o caso comum aqui), a circunferência circunscrita é bem maior que a
    // borda real na direção vertical/diagonal — a ponta da seta parava bem
    // antes de tocar o cartão, flutuando solta no meio do caminho. Isto
    // resolve a intersecção reta×retângulo (min dos dois eixos) + uma folga
    // pequena, então a seta encosta na borda de fato.
    function edgeGapToward(w, h, nx, ny, extraGap) {
        const toVertical = nx !== 0 ? w / 2 / Math.abs(nx) : Infinity
        const toHorizontal = ny !== 0 ? h / 2 / Math.abs(ny) : Infinity
        return Math.min(toVertical, toHorizontal) + extraGap
    }

    // Seta do centro do hub até o satélite, com gap nas duas pontas (a linha
    // não invade nem o cartão do hub nem o do satélite) e marcador conforme
    // o sentido observado do PAR (hub é source/target/os dois).
    function drawSpoke(hub, neighbor) {
        const cx = hub.x + hub.w / 2
        const cy = hub.y + hub.h / 2
        const dx = neighbor.sx - cx
        const dy = neighbor.sy - cy
        const dist = Math.hypot(dx, dy) || 1
        const nx = dx / dist
        const ny = dy / dist
        const hubGap = edgeGapToward(hub.w, hub.h, nx, ny, HUB_EDGE_GAP)
        const satGap = edgeGapToward(neighbor.w, neighbor.h, nx, ny, SAT_EDGE_GAP)
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

        if (edge.label) {
            const title = document.createElementNS(SVG_NS, 'title')
            title.textContent = edge.label
            path.appendChild(title)
        }

        edgesSvg.appendChild(path)
    }

    // Só reposiciona/mostra-esconde os cartões-satélite (já criados e
    // medidos em `render()`) e redesenha os spokes — nunca recria elementos,
    // pra alternar expandir/colapsar não custar um reflow de DOM inteiro.
    function drawRing(hub) {
        hub.badgeEl?.remove()
        hub.badgeEl = null
        drawBadge(hub)

        if (hub.degree === 0) return

        if (!hub.expanded) {
            // `.hidden` (Tailwind) e `.ak-viz-node` (regra local, `display:
            // flex`) empatam em especificidade (uma classe cada) — a que
            // vier depois na cascata vence, e o `<style>` deste componente
            // é injetado DEPOIS do bundle do Tailwind no documento, então
            // `display:flex` ganhava do `display:none` e o satélite
            // continuava visível mesmo com a classe aplicada. `style.display`
            // inline sempre vence qualquer classe, então força de verdade.
            hub.neighbors.forEach((n) => {
                n.el.style.display = 'none'
            })
            return
        }

        const cx = hub.x + hub.w / 2
        const cy = hub.y + hub.h / 2
        const radius = ringRadius(hub)

        hub.neighbors.forEach((neighbor, i) => {
            const angle = (i / hub.neighbors.length) * Math.PI * 2 - Math.PI / 2
            neighbor.sx = cx + radius * Math.cos(angle)
            neighbor.sy = cy + radius * Math.sin(angle)

            neighbor.el.style.display = ''
            neighbor.el.style.left = neighbor.sx + 'px'
            neighbor.el.style.top = neighbor.sy + 'px'

            drawSpoke(hub, neighbor)
        })
    }

    // Uma cor por grupo (hash do id do hub, não do índice na lista — assim
    // a cor de um grupo não pula pra outra a cada troca de filtro só
    // porque a ordem dos nós mudou) pra diferenciar clusters vizinhos no
    // grid; sem isso, todo halo saía do mesmo tom e dois clusters lado a
    // lado se liam como um blob só.
    const HALO_PALETTE = ['#C9D4F7', '#FBD6B0', '#B7EACD', '#F5C2DD', '#BFE3F5', '#E4D2F7', '#FFE49E', '#CFE0B8']
    function haloColor(hub) {
        const key = String(hub.id)
        let hash = 0
        for (let i = 0; i < key.length; i++) hash = (hash * 31 + key.charCodeAt(i)) | 0
        return HALO_PALETTE[Math.abs(hash) % HALO_PALETTE.length]
    }

    // Halo (círculo bem sutil, atrás dos cartões) por trás de cada hub
    // expandido + seu anel de satélites — sem ele, um hub e seus vizinhos
    // são só pontos flutuando soltos no grid, com nada além da linha fina
    // da seta amarrando visualmente o grupo. Como o `<svg>` é o primeiro
    // filho de `world` (os cartões, `<div>`s, são anexados depois), tudo
    // que é desenhado aqui nasce automaticamente ATRÁS dos cartões — não
    // precisa de z-index.
    function drawHalo(hub) {
        if (!hub.expanded || hub.degree === 0) return
        const circle = document.createElementNS(SVG_NS, 'circle')
        circle.setAttribute('class', 'ak-eco-halo')
        circle.setAttribute('cx', hub.x + hub.w / 2)
        circle.setAttribute('cy', hub.y + hub.h / 2)
        circle.setAttribute('r', ringRadius(hub) + maxNeighborRadius(hub) + HUB_MARGIN / 2)
        circle.style.fill = haloColor(hub)
        edgesSvg.appendChild(circle)
    }

    function draw() {
        clearEdges()
        hubs.forEach(drawHalo)
        hubs.forEach(drawRing)
    }

    function updateStatus() {
        const pairs = graphRef?.edges?.length ?? 0
        statusEl.textContent = `${hubs.length} soluções · ${pairs} ligaç${pairs === 1 ? 'ão' : 'ões'} · zoom ${Math.round(view.scale * 100)}%`
    }

    function clearWorld() {
        closePopover()
        hubs.forEach((h) => {
            h.badgeEl?.remove()
            h.neighbors.forEach((n) => n.el?.remove())
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
            paintCard(el, data)
            world.appendChild(el)

            const neighbors = neighborsOf.get(data.id) || []
            const degree = neighbors.length

            const hub = {
                ...data,
                el,
                neighbors,
                degree,
                expanded: defaultExpanded(data.id, degree),
                badgeEl: null,
                w: 0,
                h: 0,
                x: 0,
                y: 0,
                didDrag: false,
            }

            el.addEventListener('mousedown', (e) => {
                e.stopPropagation()
                if (e.button !== 0) return
                startHubDrag(hub, e)
            })
            el.addEventListener('click', (e) => {
                e.stopPropagation()
                // Um arraste real dispara `click` também (mouseup natural) —
                // suprime a abertura do popover nesse caso, só o próximo
                // clique "parado" volta a abrir.
                if (hub.didDrag) {
                    hub.didDrag = false
                    return
                }
                openPopover(data, el)
            })

            return hub
        })
        hubs.forEach((h) => {
            h.w = h.el.offsetWidth
            h.h = h.el.offsetHeight
        })

        // Satélites: um cartão por (hub, vizinho), criado e medido uma única
        // vez aqui — `drawRing()` só reposiciona/mostra-esconde depois.
        hubs.forEach((hub) => {
            hub.neighbors.forEach((neighbor) => {
                const el = document.createElement('div')
                el.className = 'ak-viz-node ak-eco-satellite-card'
                paintCard(el, neighbor.node)
                if (neighbor.edge.label) el.title = neighbor.edge.label
                el.addEventListener('mousedown', (e) => e.stopPropagation())
                el.addEventListener('click', (e) => {
                    e.stopPropagation()
                    openPopover(neighbor.node, el)
                })
                world.appendChild(el)
                neighbor.el = el
                neighbor.w = el.offsetWidth
                neighbor.h = el.offsetHeight
            })
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
