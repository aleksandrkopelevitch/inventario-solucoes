// docs-search.js — search + filters over the public documentation link.
//
// Drives the panel that sits above the documentation (`x-documentation.search-panel`),
// which asks `public.docs.search` for one updatable slot: chips, count, hits
// and totals arrive as HTML rendered by Blade, so this module builds no markup
// at all — it owns the debounce, the keyboard and what the panel hides.
//
// It is a ⌘K palette (`<dialog>`), was one, then wasn't, and is again. What
// changed is what the controls MEAN: the inline version existed because its
// facets ("results that CONTAIN a table") were the feature and had to be
// visible. The palette's scope switches answer "search INSIDE tables / code /
// prose" and live beside the field, on screen the whole time a query is — so
// the objection that took it out of a modal doesn't apply to them.
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
//
// On `/docs` the palette also has a CADERNO: "Todos os cadernos" or one of them,
// sent as `notebook` (absent = all). It is client state like the scopes, set by
// the palette's select or by a caderno chip, and the two are kept in step here.
// The magic link renders no select and never sends it.

import * as ajaxModule from './ajax'
import { updateSlots } from './ajax-slot'

const DEBOUNCE_MS = 140

// While cadernos are still being indexed (a cold "Todos os cadernos"), the
// server answers with what it has and the palette asks again — each request
// indexes a few more. Bounded, so a stuck index ends in a Toast rather than in
// polling forever.
const INDEXING_RETRY_MS = 1500
const MAX_INDEXING_RETRIES = 20

// `scopes` is a Set the CLIENT owns: the switches sit outside the swapped slot
// (like the query field), so nothing server-rendered can contradict them.
const state = { q: '', section: '', tag: '', notebook: '', scopes: new Set(['prose', 'table', 'code']) }

let sequence = 0
let debounce = null
let activeIndex = 0
let indexingRetries = 0
let indexingTimer = null

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

/*------------------------------------------------
    Opening and closing
--------------------------------------------------*/

// The index is built on FIRST OPEN, never on page render: a visitor who never
// searches never pays the cost of parsing the corpus (six seconds on the
// largest one measured). `data-ak-docs-search-pending` is the server saying it
// shipped a placeholder because the index was cold.
function open() {
    const dialog = panel()
    if (!dialog || dialog.open) return

    dialog.showModal()
    input()?.focus()
    input()?.select()

    if (dialog.querySelector('[data-ak-docs-search-pending]')) request()
}

function close() {
    panel()?.close()
}

function notebookSelect() {
    return panel()?.querySelector('[data-ak-docs-search-notebook-select]') ?? null
}

// A different caderno is a different corpus: its sections are not this one's,
// so a section chosen in the last one is dropped with it.
function chooseNotebook(slug) {
    state.notebook = slug
    state.section = ''

    const select = notebookSelect()
    if (select) select.value = slug
}

function request() {
    const container = panel()
    if (!container) return

    clearTimeout(indexingTimer)

    const url = new URL(container.dataset.akDocsSearchUrl, window.location.origin)
    if (state.notebook) url.searchParams.set('notebook', state.notebook)
    if (state.q) url.searchParams.set('q', state.q)
    if (state.section) url.searchParams.set('filter[section]', state.section)
    if (state.tag) url.searchParams.set('filter[tag]', state.tag)

    // Sent only when it is a real narrowing. All three on is the default, and
    // the server reads an absent selection as "everywhere" — so the common
    // case keeps the URL (and the response) exactly what it was.
    if (state.scopes.size < 3) {
        [...state.scopes].forEach((scope) => url.searchParams.append('filter[scopes][]', scope))
    }

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
            followIndexing()
        })
        .catch(() => {
            if (ticket !== sequence) return
            spinner(false)
            window.Toast?.show('Não foi possível buscar agora. Tente de novo.', 'warning')
        })
}

// The answer named cadernos it has not indexed yet. Ask again while somebody
// is looking at the palette; a closed palette does not keep the server busy.
function followIndexing() {
    const dialog = panel()
    if (!dialog?.querySelector('[data-ak-docs-search-indexing]')) {
        indexingRetries = 0

        return
    }

    if (!dialog.open) return

    if (++indexingRetries > MAX_INDEXING_RETRIES) {
        indexingRetries = 0
        window.Toast?.show('A indexação dos cadernos está demorando. Os resultados podem estar incompletos — tente de novo em instantes.', 'warning')

        return
    }

    indexingTimer = setTimeout(request, INDEXING_RETRY_MS)
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
    // The scopes and the caderno are deliberately NOT reset: they are how and
    // where this person searches, not what they searched for. Clearing them
    // with the query would undo the setup on every new question.
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

        // A caderno chip PICKS that caderno, like the select does, and "Todos"
        // goes back to all of them. Re-clicking the active one is a no-op
        // rather than a toggle: there is nothing "off" for a corpus to be.
        if (axis === 'notebook') {
            chooseNotebook(value)
        } else {
            state[axis] = state[axis] === value ? '' : value
        }
        request()

        // The chip is inside the slot, so the swap that follows would leave
        // focus on a detached node — and until it does, Enter would re-fire the
        // chip instead of opening the highlighted hit.
        input()?.focus()

        return
    }

    // Choosing a result closes the palette, and it has to be done HERE rather
    // than left to the navigation. A hit on the page already open is an
    // anchor-only URL: the browser changes the hash and never reloads, so the
    // dialog would sit over the very heading it just jumped to. (That is why it
    // looked like it "worked the first time" — the first hit usually goes to
    // another page, and the reload took the dialog with it.)
    if (event.target.closest('[data-ak-docs-search-item]')) {
        close()

        return
    }

    if (event.target.closest('[data-ak-docs-search-open]')) {
        event.preventDefault()
        open()

        return
    }

    if (event.target.closest('[data-ak-docs-search-close]')) {
        event.preventDefault()
        close()

        return
    }

    if (event.target.closest('[data-ak-docs-search-clear]')) {
        event.preventDefault()
        clearSearch()
    }
})

// The caderno select.
document.addEventListener('change', (event) => {
    const select = event.target.closest?.('[data-ak-docs-search-notebook-select]')
    if (!select) return

    chooseNotebook(select.value)
    request()
    input()?.focus()
})

// The scope switches. `change`, not `click`: a checkbox is also toggled by the
// keyboard, and binding the click would leave those two out of step.
document.addEventListener('change', (event) => {
    const box = event.target.closest?.('[data-ak-docs-search-scope]')
    if (!box) return

    const scope = box.dataset.akDocsSearchScope
    box.checked ? state.scopes.add(scope) : state.scopes.delete(scope)

    // Unticking the last one reads as "search nowhere", which would answer
    // every query with silence. The server treats an empty selection as all
    // three; the boxes are put back so the screen says the same thing.
    if (state.scopes.size === 0) {
        state.scopes = new Set(['prose', 'table', 'code'])
        panel()?.querySelectorAll('[data-ak-docs-search-scope]').forEach((other) => { other.checked = true })
    }

    request()
    input()?.focus()
})

// Clicking the backdrop closes it — a <dialog> reports that as a click on the
// dialog element itself, outside its own content box.
document.addEventListener('click', (event) => {
    const dialog = panel()
    if (!dialog?.open || event.target !== dialog) return

    const box = dialog.getBoundingClientRect()
    const outside = event.clientX < box.left || event.clientX > box.right
        || event.clientY < box.top || event.clientY > box.bottom

    if (outside) close()
})

document.addEventListener('keydown', (event) => {
    const field = input()
    if (!field) return

    // ⌘K / Ctrl+K anywhere, or "/" when the visitor isn't already typing,
    // OPENS the palette — it used to scroll to a field sitting in the page.
    const typing = event.target.matches?.('input, textarea, select, [contenteditable="true"]')
    if (((event.key === 'k' || event.key === 'K') && (event.metaKey || event.ctrlKey)) || (event.key === '/' && !typing)) {
        event.preventDefault()
        open()

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
            // Same reason as the click path: an anchor-only jump never reloads.
            close()
            window.location.href = target.href
        }
    } else if (event.key === 'Escape' && field.value !== '') {
        // First Escape clears a typed query, second one closes the palette —
        // the <dialog>'s own close watcher handles that second press, so this
        // deliberately does NOT preventDefault on an empty field.
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

    // Where the palette starts: the caderno being read, or '' (all of them).
    state.notebook = panel()?.dataset.akDocsSearchNotebook ?? ''

    field.addEventListener('input', () => {
        state.q = field.value.trim()
        scheduleRequest()
    })

    // The chips are rendered with the page whenever the index is already warm.
    // When it isn't, the panel shipped a placeholder and this is what fills it
    // in — building the index in the background instead of inside the render.
    if (panel()?.querySelector('[data-ak-docs-search-pending]')) request()
}
