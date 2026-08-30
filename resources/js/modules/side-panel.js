// Generic side panel — single instance, content loaded via AJAX on open, cleared on close.
//
// Open:  <button data-ak-panel-open data-ak-panel-url="/url">
// Close: <button data-ak-panel-close>  (or clicking the overlay)
// Size:  <button data-ak-panel-open data-ak-panel-size="medium"> — small (default) | medium | large
// Dock:  <button data-ak-panel-open data-ak-panel-dock="host-element-id">
//
// The server endpoint must return JSON: { "content": "<html string>" }
// After injection, initAllModules() is called to re-initialize JS in the new content.
//
// DOCKED mode is the same panel, in flow. Instead of floating over the page at
// z-50, the shell is MOVED into the host element (which must be a flex row) and
// becomes its last column: the page's own content shrinks beside it rather than
// disappearing under it, and the reader can drag the left edge to trade width
// between the two. There is one shell either way — a second, docked-only panel
// would mean a second copy of the fetch/inject/supersede logic below.
//
// It falls back to the floating panel under DOCK_MIN_VIEWPORT: a column that
// can't go below 320px and a phone are not compatible, and the fallback is the
// behavior that screen already had.

import * as ajaxModule from './ajax'

const panel   = () => document.getElementById('side-panel')
const overlay = () => document.getElementById('side-panel-overlay')

const WIDTHS = {
    small:  ['w-96'],
    medium: ['w-1/2'],
    large:  ['w-3/4'],
}
const ALL_WIDTH_CLASSES = Object.values(WIDTHS).flat()

const DOCK_CLASSES = 'relative z-10 flex min-h-0 shrink-0 flex-col self-stretch overflow-hidden border-l border-line bg-surface text-body'
const DOCK_MIN_WIDTH = 320
const DOCK_DEFAULT_WIDTH = 440
const DOCK_MAX_VIEWPORT_RATIO = 0.6
const DOCK_MIN_VIEWPORT = 1024
const DOCK_ANIM_MS = 200
const DOCK_KEY_STEP = 32
const DOCK_STORAGE_KEY = 'ak-panel-dock-width'

// Bumped on every open()/close() and checked when a fetch resolves — a
// single instance can only ever show one record's content at a time, so a
// still-in-flight fetch from a superseded open() (a second "editar" clicked
// before the first one's response landed) must not append its content on
// top of whatever's current. Without this, two stacked
// `[data-panel-content]` wrappers with duplicate DOM ids landed in the
// panel, and only the first was reachable by the getElementById-based JS
// (ajax-post.js, chips.js) that a form submit inside it depends on.
// It doubles as the guard on the docked close, which finishes on a timer.
let requestSeq = 0

// The shell's own class list, straight from layout.blade.php. Captured once,
// before docking rewrites it, so undocking restores the floating panel exactly
// as authored instead of from a copy of the string kept here.
let overlayClassName = null
let docked = false
let grip = null

/* ------------------------------------------------------------------ */
/*  Open / close                                                       */
/* ------------------------------------------------------------------ */

function open(url, options) {
    const p = panel()
    if (!p) return

    if (overlayClassName === null) overlayClassName = p.className

    const seq = ++requestSeq
    const host = dockHost(options)

    if (host) dockInto(p, host)
    else float(p, options)

    // Reset to the loading placeholder before fetching, in case a previous
    // record's content (or an earlier failed load) is still showing.
    clearContent(p)

    if (url) fetchContent(p, url, seq)
}

function close() {
    const p = panel()
    if (!p) return

    const seq = ++requestSeq // supersede any fetch still in flight from this open
    overlay()?.classList.add('opacity-0', 'pointer-events-none')

    if (docked) {
        // Collapse the column first and tear the docked shell down once it has
        // finished, so the page reclaims the width in one motion instead of
        // the panel vanishing and the content snapping sideways after it.
        p.style.width = '0px'
        window.setTimeout(() => {
            if (seq !== requestSeq) return // reopened while collapsing
            float(p, {}, false)
            clearContent(p)
        }, DOCK_ANIM_MS)

        return
    }

    p.classList.add('translate-x-full')
    clearContent(p)
}

function clearContent(p) {
    p.querySelector('[data-panel-content]')?.remove()
    p.querySelector('[data-panel-placeholder]')?.classList.remove('hidden')
}

/* ------------------------------------------------------------------ */
/*  Floating (the original behavior) and docked shells                 */
/* ------------------------------------------------------------------ */

/** @param {boolean} reveal — false leaves the shell closed (used by close()). */
function float(p, options, reveal = true) {
    if (docked) {
        grip?.remove()
        grip = null
        docked = false
        p.style.width = ''
        p.style.transition = ''
        document.body.appendChild(p)
    }

    p.className = overlayClassName

    const size = WIDTHS[options.akPanelSize] ? options.akPanelSize : 'small'
    p.classList.remove(...ALL_WIDTH_CLASSES)
    p.classList.add(...WIDTHS[size])

    if (!reveal) return

    p.classList.remove('translate-x-full')
    if (options.akPanelOverlay !== 'false') {
        overlay()?.classList.remove('opacity-0', 'pointer-events-none')
    }
}

function dockInto(p, host) {
    overlay()?.classList.add('opacity-0', 'pointer-events-none')

    const target = clampDockWidth(storedDockWidth())
    const opening = ! docked

    if (opening) {
        p.className = DOCK_CLASSES
        p.style.transition = ''
        p.style.width = '0px'
        host.appendChild(p)
        ensureGrip(p)
    }

    // Two frames: the first commits `width: 0` as the starting value in the
    // freshly-restyled element, the second animates away from it. One frame is
    // enough in Chrome and not in Firefox, and the panel then simply appears.
    requestAnimationFrame(() => requestAnimationFrame(() => {
        p.style.transition = `width ${DOCK_ANIM_MS}ms ease`
        p.style.width = `${target}px`
    }))

    docked = true
}

function dockHost(options) {
    if (!options.akPanelDock) return null
    if (window.innerWidth < DOCK_MIN_VIEWPORT) return null

    return document.getElementById(options.akPanelDock)
}

/* ------------------------------------------------------------------ */
/*  Resizing the docked column                                         */
/* ------------------------------------------------------------------ */

function maxDockWidth() {
    return Math.max(DOCK_MIN_WIDTH, Math.round(window.innerWidth * DOCK_MAX_VIEWPORT_RATIO))
}

function clampDockWidth(width) {
    return Math.min(Math.max(width, DOCK_MIN_WIDTH), maxDockWidth())
}

function storedDockWidth() {
    try {
        return Number(localStorage.getItem(DOCK_STORAGE_KEY)) || DOCK_DEFAULT_WIDTH
    } catch (_) {
        // Private mode / blocked storage — the default is a fine answer.
        return DOCK_DEFAULT_WIDTH
    }
}

function rememberDockWidth(width) {
    try {
        localStorage.setItem(DOCK_STORAGE_KEY, String(width))
    } catch (_) {
        // Not worth failing a resize over.
    }
}

function setDockWidth(p, width, remember = true) {
    const clamped = clampDockWidth(width)
    p.style.width = `${clamped}px`
    if (remember) rememberDockWidth(clamped)
}

function ensureGrip(p) {
    if (grip?.parentElement === p) return

    grip = document.createElement('div')
    grip.setAttribute('data-ak-panel-dock-grip', '')
    grip.setAttribute('role', 'separator')
    grip.setAttribute('aria-orientation', 'vertical')
    grip.setAttribute('aria-label', 'Redimensionar painel')
    grip.setAttribute('tabindex', '0')
    grip.className = 'absolute inset-y-0 left-0 z-20 w-1.5 -translate-x-1/2 cursor-col-resize bg-transparent transition-colors hover:bg-accent-line focus-visible:bg-accent-line focus-visible:outline-none'
    p.prepend(grip)
}

let dragStartX = 0
let dragStartWidth = 0

document.addEventListener('pointerdown', (e) => {
    const handle = e.target.closest('[data-ak-panel-dock-grip]')
    const p = panel()
    if (!handle || !p || !docked) return

    e.preventDefault()
    dragStartX = e.clientX
    dragStartWidth = p.offsetWidth

    // The transition is for opening and closing; during a drag it would make
    // the edge lag 200ms behind the cursor.
    p.style.transition = 'none'
    handle.setPointerCapture(e.pointerId)
    handle.dataset.dragging = 'true'
    document.body.classList.add('select-none')
})

document.addEventListener('pointermove', (e) => {
    const handle = e.target.closest('[data-ak-panel-dock-grip]')
    const p = panel()
    if (!handle?.dataset.dragging || !p) return

    // The grip is on the LEFT edge, so dragging left widens the panel.
    setDockWidth(p, dragStartWidth + (dragStartX - e.clientX), false)
})

document.addEventListener('pointerup', (e) => {
    const handle = e.target.closest('[data-ak-panel-dock-grip]')
    const p = panel()
    if (!handle?.dataset.dragging || !p) return

    delete handle.dataset.dragging
    handle.releasePointerCapture?.(e.pointerId)
    document.body.classList.remove('select-none')
    p.style.transition = `width ${DOCK_ANIM_MS}ms ease`
    rememberDockWidth(p.offsetWidth)
})

// Keyboard equivalent — a separator that only answers to a pointer is not one.
document.addEventListener('keydown', (e) => {
    const handle = e.target.closest('[data-ak-panel-dock-grip]')
    const p = panel()
    if (!handle || !p || !docked) return

    const step = {ArrowLeft: DOCK_KEY_STEP, ArrowRight: -DOCK_KEY_STEP}[e.key]
    if (step === undefined) return

    e.preventDefault()
    setDockWidth(p, p.offsetWidth + step)
})

// A narrower window can leave the docked column wider than its own ceiling.
window.addEventListener('resize', () => {
    const p = panel()
    if (!docked || !p) return

    setDockWidth(p, p.offsetWidth, false)
})

/* ------------------------------------------------------------------ */

function fetchContent(p, url, requestId) {
    ajaxModule.init('GET', url)
        .then((response) => response.json())
        .then((data) => {
            if (requestId !== requestSeq) return // a later open()/close() already superseded this
            if (!data.content) throw new Error('Side panel response has no content')

            const wrapper = document.createElement('div')
            wrapper.setAttribute('data-panel-content', '')
            // `flex-1 min-h-0` rather than `h-full`: the docked shell's height
            // comes from stretching inside the host row, not from a definite
            // one to take a percentage of.
            wrapper.className = 'flex min-h-0 flex-1 flex-col'
            wrapper.innerHTML = data.content

            p.querySelector('[data-panel-placeholder]')?.classList.add('hidden')
            p.appendChild(wrapper)

            window.initAllModules?.()
        })
        .catch(() => {
            if (requestId !== requestSeq) return
            // Without this, a 403/422/network failure left the 3-dot
            // loading placeholder spinning forever with no feedback at all.
            Toast.show('Não foi possível carregar o painel.', 'error')
            close()
        })
}

// Listener at module level — init() is a no-op so multiple initAllModules() calls are safe
document.addEventListener('click', (e) => {
    const opener = e.target.closest('[data-ak-panel-open]')
    if (opener) {
        e.preventDefault()
        open(opener.dataset.akPanelUrl ?? null, opener.dataset)
        return
    }

    if (e.target.closest('[data-ak-panel-close]')) {
        e.preventDefault()
        close()
    }
})

export function init() {}
