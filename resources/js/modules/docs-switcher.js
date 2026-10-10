// docs-switcher.js — the filter field over the knowledge base's list of
// cadernos: inside the top bar's switcher (`x-docs.notebook-switcher`, past six
// cadernos) and in the landing's rail (`x-docs.notebooks-rail`, always). One
// module for both, so the two answer a query the same way.
//
// Opening and closing the popover is toggle.js's, like every other popover in
// the app; this module owns narrowing the list as somebody types, and Enter,
// which opens the first caderno still listed — the field is a way to GO
// somewhere, and typing "dig" + Enter should land in the Digibee manual.
//
// Folded on both sides (`fold.js`), which is the rule every client-side filter
// in this app follows: a list narrowed in the browser has to answer a query the
// same way the database does, or "solucoes" finds a caderno on one screen and
// not on another.

import { fold } from './fold.js'

document.addEventListener('input', (e) => {
    const input = e.target.closest('[data-ak-docs-switcher-input]')
    if (!input) return

    const root = input.closest('[data-ak-docs-switcher]')
    if (!root) return

    const term = fold(input.value.trim())
    let visible = 0

    root.querySelectorAll('[data-ak-docs-switcher-item]').forEach((item) => {
        // The caderno's name AND the systems it documents, which is the same
        // pair the catalog searches — somebody looking for the Digibee manual
        // types "Digibee", not whatever the caderno was called.
        const hit = term === '' || fold(item.dataset.akDocsSwitcherLabel || '').includes(term)
        item.hidden = !hit
        if (hit) visible++
    })

    const empty = root.querySelector('[data-ak-docs-switcher-empty]')
    if (empty) empty.hidden = visible > 0
})

document.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' || e.isComposing) return

    const input = e.target.closest('[data-ak-docs-switcher-input]')
    const root = input?.closest('[data-ak-docs-switcher]')
    if (!root) return

    const first = [...root.querySelectorAll('[data-ak-docs-switcher-item]')].find((item) => !item.hidden)
    if (!first) return

    e.preventDefault()
    window.location.assign(first.href)
})

// Pure delegation — nothing to mount, and the popover's markup arrives with the
// page rather than through a slot swap.
export function init() {}
