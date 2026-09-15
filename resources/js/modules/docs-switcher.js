// docs-switcher.js — the filter field inside the knowledge base's caderno
// switcher (`x-docs.notebook-switcher`).
//
// Opening and closing the popover is toggle.js's, like every other popover in
// the app; this module owns ONE thing, which is narrowing the list as somebody
// types. It only exists at all past six cadernos — below that the component
// renders no field and this never runs.
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

// Pure delegation — nothing to mount, and the popover's markup arrives with the
// page rather than through a slot swap.
export function init() {}
