// docs-search.js — search + filters over the public documentation link.
//
// Drives the panel that sits above the documentation (`x-documentation.search-panel`),
// which asks `public.docs.search` for one updatable slot: chips, count, hits
// and totals arrive as HTML rendered by Blade, so this module builds no markup
// at all — it owns the debounce, the keyboard and what the panel hides.
//
// It was a ⌘K modal first. The filters are why it isn't one any more: a facet
// nobody can see is a facet nobody uses. ⌘K survives as a shortcut TO the
// field, not as a way to summon it.
//
// Three details worth keeping:
//
//   - The query input lives OUTSIDE the swapped slot. Replacing it on every
//     keystroke would drop the caret mid-word.
//   - Responses are matched against a sequence number instead of aborted:
//     ajax.js returns a Promise with no signal (see § ajax.js in AGENTS.md),
//     so a slow early request must be recognised as stale on arrival rather
//     than cancelled in flight — otherwise typing "sap" fast can end with the
//     results for "s" on screen.
//   - Everything below the input is delegated at the document level, because
//     the slot node is REPLACED (not refilled) on every request; a listener
//     bound to the old node would be swapped away with it.

import * as ajaxModule from './ajax'
import { updateSlots } from './ajax-slot'

const DEBOUNCE_MS = 140

const state = { q: '', section: '', tag: '' }

let sequence = 0
let debounce = null
let activeIndex = 0

const bound = new WeakSet()

function panel() {
    return document.querySelector('[data-ak-docs-search]')
}

function input() {
    return panel()?.querySelector('[data-ak-docs-search-input]') ?? null
}

function items() {
    return Array.from(panel()?.querySelectorAll('[data-ak-docs-search-item]') ?? [])
}

/*------------------------------------------------
    Fetching
--------------------------------------------------*/

function spinner(visible) {
    panel()?.querySelector('[data-ak-docs-search-spinner]')?.classList.toggle('hidden', !visible)
}

// While a search is narrowing the corpus, the results ARE the page — the three
// pane reading shell would otherwise sit under a screen of hits with nothing
// to do. The server decides (`data-ak-docs-search-active` on the slot), so the
// panel and the shell can never disagree about whether a search is running.
function syncShell() {
    const shell = document.querySelector('[data-ak-docs-shell]')
    if (!shell) return

    shell.classList.toggle('hidden', !!panel()?.querySelector('[data-ak-docs-search-active]'))
}

function request() {
    const container = panel()
    if (!container) return

    const url = new URL(container.dataset.akDocsSearchUrl, window.location.origin)
    if (state.q) url.searchParams.set('q', state.q)
    if (state.section) url.searchParams.set('filter[section]', state.section)
    if (state.tag) url.searchParams.set('filter[tag]', state.tag)

    const ticket = ++sequence
    spinner(true)

    ajaxModule.init('GET', url.toString())
        .then((response) => response.json())
        .then((data) => {
            // A response from a keystroke the user has already typed past.
            if (ticket !== sequence) return

            spinner(false)
            updateSlots(data)
            activeIndex = 0
            highlightActive({ scroll: false })
            syncShell()
        })
        .catch(() => {
            if (ticket !== sequence) return
            spinner(false)
            window.Toast?.show('Não foi possível buscar agora. Tente de novo.', 'warning')
        })
}

function scheduleRequest() {
    clearTimeout(debounce)
    debounce = setTimeout(request, DEBOUNCE_MS)
}

function clearSearch() {
    const field = input()
    if (field) field.value = ''

    state.q = ''
    state.section = ''
    state.tag = ''
    clearTimeout(debounce)
    request()
    field?.focus()
}

/*------------------------------------------------
    Keyboard selection
--------------------------------------------------*/

function highlightActive({ scroll = true } = {}) {
    const list = items()
    if (!list.length) return

    activeIndex = Math.max(0, Math.min(activeIndex, list.length - 1))

    list.forEach((item, index) => {
        const active = index === activeIndex
        item.setAttribute('aria-selected', active ? 'true' : 'false')
        if (active && scroll) item.scrollIntoView({ block: 'nearest' })
    })
}

function move(step) {
    const list = items()
    if (!list.length) return

    activeIndex = (activeIndex + step + list.length) % list.length
    highlightActive()
}

/*------------------------------------------------
    Delegated listeners (module level — the slot is replaced, not refilled)
--------------------------------------------------*/

// Facet chips: one value per axis, and clicking the active chip clears it —
// so a filter never needs a separate "remove" affordance.
document.addEventListener('click', (event) => {
    const chip = event.target.closest('[data-ak-docs-search-facet]')
    if (chip) {
        event.preventDefault()
        const axis = chip.dataset.akDocsSearchFacet
        const value = chip.dataset.akDocsSearchValue ?? ''

        state[axis] = state[axis] === value ? '' : value
        request()

        // The chip is inside the slot, so the swap that follows would leave
        // focus on a detached node — and until it does, Enter would re-fire the
        // chip instead of opening the highlighted hit.
        input()?.focus()

        return
    }

    if (event.target.closest('[data-ak-docs-search-clear]')) {
        event.preventDefault()
        clearSearch()
    }
})

document.addEventListener('keydown', (event) => {
    const field = input()
    if (!field) return

    // ⌘K / Ctrl+K anywhere, or "/" when the visitor isn't already typing,
    // jumps to the field wherever the page has been scrolled to.
    const typing = event.target.matches?.('input, textarea, select, [contenteditable="true"]')
    if (((event.key === 'k' || event.key === 'K') && (event.metaKey || event.ctrlKey)) || (event.key === '/' && !typing)) {
        event.preventDefault()
        field.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
        field.focus()
        field.select()

        return
    }

    // The rest of the keyboard belongs to the field itself — arrow keys are
    // for scrolling the page everywhere else on it.
    if (event.target !== field) return

    if (event.key === 'ArrowDown') {
        event.preventDefault()
        move(1)
    } else if (event.key === 'ArrowUp') {
        event.preventDefault()
        move(-1)
    } else if (event.key === 'Enter') {
        const target = items()[activeIndex]
        if (target) {
            event.preventDefault()
            window.location.href = target.href
        }
    } else if (event.key === 'Escape') {
        event.preventDefault()
        clearSearch()
    }
})

// Pointer and keyboard share one selection, so hovering a hit and pressing
// Enter opens what the visitor is actually looking at.
//
// `mouseover`, not `mousemove`: this module is in the global bundle, so its
// listeners are live on every page in the app — and a `mousemove` handler
// running a DOM query on every pixel of every pointer movement, everywhere,
// is a real cost for a panel that exists on the public link alone.
document.addEventListener('mouseover', (event) => {
    const item = event.target.closest?.('[data-ak-docs-search-item]')
    if (!item) return

    const index = Number(item.dataset.akDocsSearchItem)
    if (index === activeIndex) return

    activeIndex = index
    highlightActive({ scroll: false })
})

/*------------------------------------------------
    Per-element init (the input is never replaced, so bind it once)
--------------------------------------------------*/

export function init() {
    const field = input()
    if (!field || bound.has(field)) return

    bound.add(field)
    field.addEventListener('input', () => {
        state.q = field.value.trim()
        scheduleRequest()
    })

    // The chips are rendered with the page whenever the index is already warm.
    // When it isn't, the panel shipped a placeholder and this is what fills it
    // in — building the index in the background instead of inside the render.
    if (panel()?.querySelector('[data-ak-docs-search-pending]')) request()
}
