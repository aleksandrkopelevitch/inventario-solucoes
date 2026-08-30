// Ecosystem map (read-only) — radial hub-and-spoke layout.
//
// Each solution becomes a hub (rounded card, same visual as the
// diagram-viz block) positioned once in a compact grid that packs the
// FOOTPRINTs (card + neighbor ring, when expanded) into rows, largest first.
// No `dagre`/rank layout here: most pairs/clusters in this graph are small
// and mostly disconnected from each other (many solutions with 0-2
// neighbors, a few well-connected hubs) — a rank layout (left→right) pushes
// everything that shares no edge into the same rank and collapses into a
// single column (verified visually during implementation). The packed grid
// gives genuine 2D layout regardless of connectivity.
//
// Neighbors (satellites) are the SAME card as the hub (avatar + name,
// compact `.ak-eco-satellite-card` variant) — not an avatar alone — so the
// name is always readable, both for the hub and for each satellite. The
// ring radius is derived from the REAL measured size of each card (hub +
// largest satellite + perimeter needed for the number of neighbors), not a
// fixed constant — a degree-1 hub sits close to its single neighbor.
//
// A solution can appear as a hub (its own position) AND as a satellite in
// another hub's ring — intentional: avoids the canvas-spanning curves that
// tangled up the old drawing (see CLAUDE.md/plan). Hubs with many neighbors
// (degree > EXPAND_THRESHOLD) start collapsed (just the card + a badge with
// the count); clicking the badge expands/collapses that hub's ring WITHOUT
// touching the grid's `layout()` — no other hub moves, and the toggled hub
// itself also stays where it was (only its ring appears/disappears around
// it). Recalculating the whole grid on every toggle made the entire view
// reposition unpredictably on every click — disorienting.
//
// Clicking a card (hub or satellite) opens a popover with the solution's
// attributes + a "See more" button (new tab) — it doesn't navigate directly.
// Pan/zoom/fit/fullscreen follow the same pattern as chain-viz.js
// (view.x/y/scale on a single #world transform, real Fullscreen API).

import {fold} from './fold.js'

const SVG_NS = 'http://www.w3.org/2000/svg'
const MIN_SCALE = 0.15
const MAX_SCALE = 2.5
const FIT_PAD = 60
const EXPAND_THRESHOLD = 6
const RING_GAP = 8 // minimum spacing between neighboring satellites around the ring
const HUB_MARGIN = 18 // footprint slack around the hub/ring, so the grid doesn't stick clusters together
const HUB_EDGE_GAP = 4 // pulls the arrow tip away from the hub's edge
const SAT_EDGE_GAP = 4 // pulls the arrow tip away from the satellite's edge
const GRID_GAP = 12 // spacing between clusters (hub+ring) in the packed grid
const DRAG_THRESHOLD = 4 // screen px — below this a mousedown+mouseup still counts as a click (opens popover), not a drag
const FOCUS_SCALE = 1 // zoom applied when focusing a system via search — readable without zooming in too much

const mounted = new WeakSet()
let uidCounter = 0

export function init() {
    document.querySelectorAll('[data-ecosystem-map]').forEach(mount)
}

// Same fallback as the catalog (`x-ui.logo`), redone in plain DOM — this
// map's nodes don't go through Blade (they arrive via fetch), same reason as
// chain-viz.js.
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

// Paints a card's content (avatar + name) — used for both the hub and the
// satellite, the only difference between the two is the CSS class applied to
// the root element (`.ak-eco-hub` vs `.ak-eco-satellite-card`).
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
    const expandState = new Map() // nodeId -> bool, survives re-fetches (filter changes) within the same session
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
        // The popover doesn't close on pan/zoom (only Esc/[X] — see
        // `closePopover` callers), so it needs to follow the anchor so it
        // doesn't end up floating disconnected from the card while the view
        // moves.
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

    // ── attribute popover ────────────────────────────────────────
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

    // Only closes via [X] (button on the popover itself) or Esc — clicking
    // outside (canvas, pan, zoom, sidebar, another filter) NEVER closes the
    // popover on its own. This is deliberate only in this map: the user
    // wants to be able to click/drag the canvas with the popover open to
    // compare one card against another, without the popover disappearing
    // mid-gesture.
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

    // ── system search (Ctrl+K / Cmd+K or magnifier) ──────────────────
    // Inline `display` (not the `hidden` class) for the same reason as the
    // satellite above — avoids depending on who wins the specificity tie
    // with this component's local CSS.
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

    // Focuses (centered pan+zoom) and highlights the chosen hub for a few
    // seconds — doesn't navigate to another page, just locates it within the
    // map itself.
    function focusHub(hub) {
        const r = viewport.getBoundingClientRect()
        view.scale = FOCUS_SCALE
        view.x = r.width / 2 - (hub.x + hub.w / 2) * view.scale
        view.y = r.height / 2 - (hub.y + hub.h / 2) * view.scale
        applyView()

        hub.el.classList.remove('is-focused')
        void hub.el.offsetWidth // forces reflow — restarts the animation even if the hub was already focused
        hub.el.classList.add('is-focused')
        clearTimeout(focusTimer)
        focusTimer = setTimeout(() => hub.el.classList.remove('is-focused'), 2200)
    }

    function selectSearchResult(hub) {
        closeSearch()
        focusHub(hub)
    }

    // Searches only among hubs (every graph node has its own position in the
    // grid — "primary system" is any one of them), not among satellite
    // cards (which are just the same solution redrawn inside another hub's
    // ring, not a separate focus target).
    function renderSearchResults(query) {
        const q = fold(query.trim())
        searchMatches = !q ? hubs : hubs.filter((h) => fold(h.label).includes(q))
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
    // Clicking the overlay outside the panel closes it (same pattern as the
    // global side-panel) — clicking inside the panel (input, list) doesn't
    // propagate since it has no close listener.
    searchOverlay?.addEventListener('mousedown', (e) => {
        if (e.target === searchOverlay) closeSearch()
    })

    // Doesn't intercept if the user is already typing into another text
    // field on the page (e.g. a filter outside this component) — Ctrl+K is a
    // "global" page shortcut, but it shouldn't steal keystrokes from someone
    // else's input.
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

    // Degree above the threshold starts collapsed; at or below, expanded —
    // unless the user has already toggled this hub in this session
    // (preserves the choice across filter changes, which reload the graph).
    function defaultExpanded(nodeId, degree) {
        if (expandState.has(nodeId)) return expandState.get(nodeId)
        return degree <= EXPAND_THRESHOLD
    }

    // Largest "radius" (half the diagonal) among the hub's satellite cards —
    // used both for the ring's minimum radius and for the reserved footprint.
    function maxNeighborRadius(hub) {
        return hub.neighbors.reduce((m, n) => Math.max(m, Math.hypot(n.w, n.h) / 2), 0)
    }

    // Perimeter needed to fit all satellites side by side without
    // overlapping (sum of each one's "width", not an average/fixed value —
    // cards with long names take up a bigger slice of the circle).
    function neighborCircumference(hub) {
        return hub.neighbors.reduce((sum, n) => sum + Math.hypot(n.w, n.h) + RING_GAP, 0)
    }

    // Ring radius: anchored to the REAL measured size of the cards (hub +
    // largest satellite + a small gap) — no longer a fixed constant
    // disconnected from the content. A degree-1 hub sits close to its single
    // neighbor; the perimeter-based floor only kicks in when there are
    // enough neighbors to need more space around them.
    function ringRadius(hub) {
        if (!hub.expanded || hub.degree === 0) return 0
        const base = Math.hypot(hub.w, hub.h) / 2 + RING_GAP + maxNeighborRadius(hub)
        const byCircumference = neighborCircumference(hub) / (2 * Math.PI)
        return Math.max(base, byCircumference)
    }

    function ringPad(hub) {
        return hub.expanded && hub.degree > 0 ? ringRadius(hub) + maxNeighborRadius(hub) : 0
    }

    // Size reserved for the hub in the packed grid (`layout()`) — a square
    // that fits the whole ring when expanded, or just the card when not.
    function footprint(hub) {
        if (hub.expanded && hub.degree > 0) {
            const side = 2 * (ringRadius(hub) + maxNeighborRadius(hub)) + HUB_MARGIN
            return { w: side, h: side }
        }
        return { w: hub.w + HUB_MARGIN, h: hub.h + HUB_MARGIN }
    }

    // Packed grid: largest footprints first (expanded hubs with a big ring
    // become visual "anchors", the rest fills in around them) — independent
    // of connectivity. The line-wrap width is NOT the viewport's literal
    // width (that produced a tall, narrow column when the sum of footprints
    // was large — `fit()` then had to zero out almost all the zoom to fit a
    // vertical tower on a widescreen); instead, it targets the SAME aspect
    // ratio as the viewport starting from the total occupied area
    // (`width = sqrt(area * aspect)`), so the resulting rectangle is already
    // shaped like the screen. Hubs with a saved `mapPosition` (dragged and
    // persisted at some point — `saveHubPosition()`) skip automatic packing
    // and go straight to their saved position; only the rest (`auto`) enter
    // the packed grid, as if the manual ones didn't exist (may overlap a
    // manual cluster — acceptable, it's the price of letting the user pin a
    // position; they can drag again to make room).
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

    // Expanding/collapsing NEVER moves anything: not the toggled hub, not
    // the other hubs, not the view (pan/zoom). `layout()` (the packed grid)
    // only runs on initial load/filter change — here it only redraws that
    // hub's ring (shows/hides satellites, redoes spokes/halo) on top of the
    // position that already existed. A growing ring may overlap a
    // neighboring cluster's card — acceptable (the user can collapse it
    // again), the alternative (repacking everything) is what made the whole
    // screen jump on every click.
    function toggleHub(hub) {
        closePopover()
        hub.expanded = !hub.expanded
        expandState.set(hub.id, hub.expanded)
        draw()
        updateStatus()
    }

    // Drags a hub (primary system) to reposition it — just detaches the card
    // from the packed grid's `layout()`, which doesn't run again afterward
    // (only on initial load/filter change, same as `toggleHub`). The hub's
    // own satellite ring (if expanded) and its spokes/halo follow along in
    // real time because `draw()` derives their position from `hub.x/y` on
    // every call — no other hub moves.
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
            // Only persists if the mousedown actually turned into a drag — a
            // stationary click (opens popover) shouldn't fire a pointless PATCH.
            if (dragging) {
                hub.mapPosition = { x: hub.x, y: hub.y }
                saveHubPosition(hub)
            }
        }

        window.addEventListener('mousemove', onMove)
        window.addEventListener('mouseup', onUp)
    }

    // Silent auto-save — no confirmation button/toast, it's a layout
    // customization, not an action that needs feedback (same "auto-saves
    // itself" pattern as `solution-attributes.js`). A failure only shows up
    // in the status bar so it doesn't interrupt with a modal/toast mid-drag;
    // the position is already correct on screen, it just wasn't persisted —
    // the next drag tries again. `fetch()` only *rejects* on a network-level
    // failure — it resolves normally for a non-2xx status (403, 419, 500),
    // so `!res.ok` must be checked explicitly or a real failure (expired
    // session, revoked permission) is swallowed with no feedback at all.
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
        }).then((res) => {
            if (!res.ok) throw new Error(`Failed to save hub position (HTTP ${res.status})`)
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

    // Distance from the center to the real EDGE of a w×h rectangular card, in
    // direction (nx,ny) — not the circumscribed circle's radius
    // (`hypot(w,h)/2`, used before). For a card that's much wider than tall
    // (the common case here), the circumscribed circle is much bigger than
    // the real edge in the vertical/diagonal direction — the arrow tip
    // stopped well before touching the card, floating loose partway there.
    // This solves the line×rectangle intersection (min of the two axes) + a
    // small gap, so the arrow actually touches the edge.
    function edgeGapToward(w, h, nx, ny, extraGap) {
        const toVertical = nx !== 0 ? w / 2 / Math.abs(nx) : Infinity
        const toHorizontal = ny !== 0 ? h / 2 / Math.abs(ny) : Infinity
        return Math.min(toVertical, toHorizontal) + extraGap
    }

    // Arrow from the hub's center to the satellite, with a gap on both ends
    // (the line invades neither the hub's card nor the satellite's) and a
    // marker following the observed direction of the PAIR (hub is
    // source/target/both).
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

    // Only repositions/shows-hides the satellite cards (already created and
    // measured in `render()`) and redraws the spokes — never recreates
    // elements, so toggling expand/collapse doesn't cost a full DOM reflow.
    function drawRing(hub) {
        hub.badgeEl?.remove()
        hub.badgeEl = null
        drawBadge(hub)

        if (hub.degree === 0) return

        if (!hub.expanded) {
            // `.hidden` (Tailwind) and `.ak-viz-node` (local rule, `display:
            // flex`) tie in specificity (one class each) — whichever comes
            // later in the cascade wins, and this component's `<style>` is
            // injected AFTER the Tailwind bundle in the document, so
            // `display:flex` beat `display:none` and the satellite stayed
            // visible even with the class applied. Inline `style.display`
            // always beats any class, so it genuinely forces it.
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

    // One color per group (hash of the hub's id, not its index in the list —
    // so a group's color doesn't jump to another one on every filter change
    // just because the node order changed) to tell neighboring clusters
    // apart in the grid; without this, every halo came out the same shade
    // and two clusters side by side read as a single blob.
    const HALO_PALETTE = ['#C9D4F7', '#FBD6B0', '#B7EACD', '#F5C2DD', '#BFE3F5', '#E4D2F7', '#FFE49E', '#CFE0B8']
    function haloColor(hub) {
        const key = String(hub.id)
        let hash = 0
        for (let i = 0; i < key.length; i++) hash = (hash * 31 + key.charCodeAt(i)) | 0
        return HALO_PALETTE[Math.abs(hash) % HALO_PALETTE.length]
    }

    // Halo (a very subtle circle, behind the cards) drawn behind each
    // expanded hub + its satellite ring — without it, a hub and its
    // neighbors are just points floating loose in the grid, with nothing
    // but the thin arrow line visually tying the group together. Since the
    // `<svg>` is `world`'s first child (the cards, `<div>`s, are appended
    // afterward), everything drawn here is automatically born BEHIND the
    // cards — no z-index needed.
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
            el.className = 'ak-viz-node ak-eco-hub' + (data.positionUrl ? '' : ' ak-eco-hub--readonly')
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
                // No `positionUrl` (a viewer — server never grants one, see
                // DiagramGraphService::putNode()) — dragging can never
                // persist, so skip it entirely rather than let the hub move
                // for the length of the gesture and snap back on reload.
                if (!hub.positionUrl) return
                startHubDrag(hub, e)
            })
            el.addEventListener('click', (e) => {
                e.stopPropagation()
                // A real drag also fires `click` (natural mouseup) —
                // suppresses opening the popover in that case; only the
                // next "stationary" click opens it again.
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

        // Satellites: one card per (hub, neighbor), created and measured
        // once here — `drawRing()` only repositions/shows-hides afterward.
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
