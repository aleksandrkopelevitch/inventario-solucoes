// Multi-select chips with an optional per-chip role ("papel") and an optional
// server-backed autocomplete (search-url — see resources/views/components/forms/chips.blade.php).
// Pure event delegation — registered once at module load, works with dynamic content.
// Markup contract: see resources/views/components/forms/chips.blade.php

import * as ajaxModule from './ajax'

const SEARCH_DEBOUNCE_MS = 350
const SEARCH_MIN_LENGTH = 2

// container -> pending debounce timer id
const searchTimers = new WeakMap()
// container -> index of the highlighted result row
const highlightedIndex = new WeakMap()
// container -> id of the most recent search request. Cancelling the debounce
// timer only stops requests not yet SENT; without this, a slow response to an
// earlier keystroke could land after a fast response to a later one and
// repaint the list with stale suggestions — which Enter would then turn into
// a chip the user never saw themselves pick.
const searchSeq = new WeakMap()

/** Abandons whatever search is in flight, so its response is ignored on arrival. */
function invalidateSearch(container) {
    searchSeq.set(container, (searchSeq.get(container) ?? 0) + 1)
}

function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')
}

function configOf(container) {
    try {
        return JSON.parse(container.dataset.akChips || '{}')
    } catch {
        return {}
    }
}

function nextIndex(container) {
    const n = parseInt(container.dataset.akChipsNext || '0', 10)
    container.dataset.akChipsNext = String(n + 1)
    return n
}

function roleSelectHtml(name, idx, roles, selected, form) {
    if (!Array.isArray(roles) || !roles.length) return ''
    const options = roles
        .map((r) => `<option value="${escapeHtml(r.value)}" ${r.value === selected ? 'selected' : ''}>${escapeHtml(r.label)}</option>`)
        .join('')
    const formAttr = form ? ` form="${escapeHtml(form)}"` : ''
    return `<select name="${escapeHtml(name)}[${idx}][role]"${formAttr} class="rounded bg-transparent text-xs text-ink focus:outline-none">${options}</select>`
}

function chipHtml(name, idx, value, label, roles, form) {
    const v = escapeHtml(value)
    const l = escapeHtml(label)
    const selectedRole = Array.isArray(roles) && roles.length ? roles[0].value : null
    const formAttr = form ? ` form="${escapeHtml(form)}"` : ''
    return `<span data-ak-chip class="inline-flex items-center gap-1 rounded-full bg-accent-soft py-1 pl-2.5 pr-1 text-xs font-semibold text-ink ring-1 ring-accent-line">
        <span>${l}</span>
        ${roleSelectHtml(name, idx, roles, selectedRole, form)}
        <button type="button" data-ak-chip-remove class="ml-0.5 rounded-full px-1 leading-none text-muted hover:bg-accent-line hover:text-ink" aria-label="Remover">&times;</button>
        <input type="hidden" name="${escapeHtml(name)}[${idx}][value]"${formAttr} value="${v}">
        <input type="hidden" name="${escapeHtml(name)}[${idx}][label]"${formAttr} value="${l}">
    </span>`
}

function existingValues(container) {
    return Array.from(container.querySelectorAll('[data-ak-chip] input[type="hidden"][name$="[value]"]')).map((i) => i.value)
}

// Called after every add/remove — can't rely on CSS `:empty` here (see
// comment in chips.blade.php), so list visibility is decided in JS by
// checking whether any `[data-ak-chip]` is still left.
function syncListVisibility(list) {
    list.classList.toggle('hidden', !list.querySelector('[data-ak-chip]'))
}

function addChip(container, cfg, value, label) {
    const value_ = String(value).trim()
    if (!value_ || existingValues(container).includes(value_)) return

    const list = container.querySelector('[data-ak-chips-list]')
    if (!list) return

    const idx = nextIndex(container)
    list.insertAdjacentHTML('beforeend', chipHtml(cfg.name, idx, value_, label, cfg.roles, cfg.form))
    syncListVisibility(list)
}

function resultsContainerOf(container) {
    return container.querySelector('[data-ak-chips-results]')
}

function hideResults(container) {
    const results = resultsContainerOf(container)
    if (!results) return

    results.classList.add('hidden')
    results.innerHTML = ''
    highlightedIndex.set(container, -1)
}

// Centered overlay (`centered` config — chips.blade.php). Only exists
// when the field was rendered with `centered`; opens on a trigger click,
// never via keyboard shortcut.
function overlayOf(container) {
    return container.querySelector('[data-ak-chips-overlay]')
}

function openOverlay(container) {
    const overlay = overlayOf(container)
    if (!overlay) return

    overlay.style.display = 'flex'

    const input = container.querySelector('[data-ak-chips-input]')
    if (input) {
        input.value = ''
        requestAnimationFrame(() => input.focus())
    }
    hideResults(container)
}

function closeOverlay(container) {
    const overlay = overlayOf(container)
    if (!overlay) return

    overlay.style.display = 'none'
    hideResults(container)
}

function renderResults(container, items) {
    const results = resultsContainerOf(container)
    if (!results) return

    if (!items.length) {
        results.innerHTML = '<p class="px-3 py-1.5 text-xs text-faint">Nenhum resultado</p>'
        results.classList.remove('hidden')
        highlightedIndex.set(container, -1)
        return
    }

    results.innerHTML = items
        .map((item, i) => `<button type="button" data-ak-chips-result data-id="${escapeHtml(item.id)}" data-name="${escapeHtml(item.name)}" class="flex w-full items-center justify-between gap-3 px-3 py-1.5 text-left text-sm text-ink hover:bg-accent-soft ${i === 0 ? 'bg-accent-soft' : ''}">
            <span class="truncate">${escapeHtml(item.name)}</span>
            ${item.meta ? `<span class="shrink-0 text-xs text-muted">${escapeHtml(item.meta)}</span>` : ''}
        </button>`)
        .join('')
    results.classList.remove('hidden')
    highlightedIndex.set(container, 0)
}

function highlightAt(container, index) {
    const buttons = resultsContainerOf(container)?.querySelectorAll('[data-ak-chips-result]')
    if (!buttons || !buttons.length) return

    const clamped = Math.max(0, Math.min(index, buttons.length - 1))
    buttons.forEach((b, i) => b.classList.toggle('bg-accent-soft', i === clamped))
    highlightedIndex.set(container, clamped)
}

// Picks the highlighted result (or the first one) and turns it into a chip.
function selectHighlighted(container, cfg) {
    const buttons = resultsContainerOf(container)?.querySelectorAll('[data-ak-chips-result]')
    if (!buttons || !buttons.length) return false

    const btn = buttons[highlightedIndex.get(container) ?? 0]
    if (!btn) return false

    addChip(container, cfg, btn.dataset.id, btn.dataset.name)
    return true
}

async function search(container, cfg, term) {
    invalidateSearch(container)
    const requestId = searchSeq.get(container)

    try {
        const response = await ajaxModule.init('GET', cfg.searchUrl + '?q=' + encodeURIComponent(term))
        const data = await response.json()
        if (searchSeq.get(container) !== requestId) return // a later keystroke already superseded this
        renderResults(container, Array.isArray(data.results) ? data.results : [])
    } catch {
        if (searchSeq.get(container) !== requestId) return
        hideResults(container)
    }
}

// Debounced search-as-you-type — only when the field was rendered with search-url.
document.addEventListener('input', (e) => {
    const input = e.target.closest('[data-ak-chips-input]')
    if (!input) return

    const container = input.closest('[data-ak-chips]')
    if (!container) return

    const cfg = configOf(container)
    if (!cfg.searchUrl) return

    clearTimeout(searchTimers.get(container))

    const term = input.value.trim()
    if (term.length < SEARCH_MIN_LENGTH) {
        // Also drops an in-flight request: deleting a character back down to
        // 1 hides the list, and without this the previous term's response
        // would land a moment later and pop it open again.
        invalidateSearch(container)
        hideResults(container)
        return
    }

    searchTimers.set(container, setTimeout(() => search(container, cfg, term), SEARCH_DEBOUNCE_MS))
})

document.addEventListener('keydown', (e) => {
    const input = e.target.closest('[data-ak-chips-input]')
    if (!input) return

    const container = input.closest('[data-ak-chips]')
    if (!container) return

    const cfg = configOf(container)

    // With search-url, chips can only come from a picked result — no
    // free-text Enter, since the value must be a real record id.
    if (cfg.searchUrl) {
        if (e.key === 'ArrowDown') {
            e.preventDefault()
            highlightAt(container, (highlightedIndex.get(container) ?? -1) + 1)
        } else if (e.key === 'ArrowUp') {
            e.preventDefault()
            highlightAt(container, (highlightedIndex.get(container) ?? 0) - 1)
        } else if (e.key === 'Escape') {
            invalidateSearch(container)
            if (cfg.centered) closeOverlay(container)
            else hideResults(container)
        } else if (e.key === 'Enter') {
            e.preventDefault()
            if (selectHighlighted(container, cfg)) {
                invalidateSearch(container)
                input.value = ''
                hideResults(container)
            }
        }
        return
    }

    if (e.key !== 'Enter') return
    e.preventDefault()

    const value = input.value.trim()
    if (!value) return

    addChip(container, cfg, value, value)
    input.value = ''
})

document.addEventListener('click', (e) => {
    // External "add documentation" button (e.g. flowSpec suggestions in
    // thread.blade.php) — finds the chips field by `name` (not by DOM: the
    // button lives in the message bubble, the field lives in the composer,
    // elsewhere on the page) and adds it by reusing addChip().
    const addBtn = e.target.closest('[data-ak-chips-add]')
    if (addBtn) {
        let payload = {}
        try {
            payload = JSON.parse(addBtn.dataset.akChipsAdd || '{}')
        } catch {
            payload = {}
        }

        const container = Array.from(document.querySelectorAll('[data-ak-chips]')).find((el) => configOf(el).name === payload.name)
        if (container && payload.value) {
            addChip(container, configOf(container), payload.value, payload.label ?? payload.value)
            addBtn.disabled = true
        }
        return
    }

    const trigger = e.target.closest('[data-ak-chips-trigger]')
    if (trigger) {
        const container = trigger.closest('[data-ak-chips]')
        if (container) openOverlay(container)
        return
    }

    const overlayCloseBtn = e.target.closest('[data-ak-chips-overlay-close]')
    if (overlayCloseBtn) {
        const container = overlayCloseBtn.closest('[data-ak-chips]')
        if (container) closeOverlay(container)
        return
    }

    // Clicking the backdrop itself (not the panel) closes it — same pattern
    // as `data-eco-search-overlay` in ecosystem-map.js.
    const overlay = e.target.closest('[data-ak-chips-overlay]')
    if (overlay && e.target === overlay) {
        const container = overlay.closest('[data-ak-chips]')
        if (container) closeOverlay(container)
        return
    }

    const resultBtn = e.target.closest('[data-ak-chips-result]')
    if (resultBtn) {
        const container = resultBtn.closest('[data-ak-chips]')
        if (container) {
            addChip(container, configOf(container), resultBtn.dataset.id, resultBtn.dataset.name)
            const input = container.querySelector('[data-ak-chips-input]')
            if (input) {
                input.value = ''
                input.focus()
            }
            hideResults(container)
        }
        return
    }

    const removeBtn = e.target.closest('[data-ak-chip-remove]')
    if (removeBtn) {
        const list = removeBtn.closest('[data-ak-chips-list]')
        removeBtn.closest('[data-ak-chip]')?.remove()
        if (list) syncListVisibility(list)
        return
    }

    // Close any open results dropdown when clicking outside its chips field.
    document.querySelectorAll('[data-ak-chips-results]:not(.hidden)').forEach((results) => {
        const container = results.closest('[data-ak-chips]')
        if (container && !container.contains(e.target)) {
            hideResults(container)
        }
    })
})

// Clears the chips of one or more fields (by `name`) — e.g. after sending a
// flowSpec message, whose context only applies to that message ("next
// message", see flowspec/show.blade.php), otherwise the systems/documents
// chosen for a question would be resent on every following one. Without
// `detail.names`, clears every chips field on the page.
document.addEventListener('ak:chips-reset', (e) => {
    const names = e.detail?.names
    document.querySelectorAll('[data-ak-chips]').forEach((container) => {
        if (Array.isArray(names) && names.length && !names.includes(configOf(container).name)) return

        const list = container.querySelector('[data-ak-chips-list]')
        if (!list) return

        list.querySelectorAll('[data-ak-chip]').forEach((chip) => chip.remove())
        syncListVisibility(list)
    })
})

// No-op — delegation is registered at module load above.
export function init() {}
