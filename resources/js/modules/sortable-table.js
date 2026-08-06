// sortable-table.js — click-to-sort table column headers (Solutions
// catalog). Toggles the hidden `filter[sort]` field (`name`, `-name`,
// `category`, ...) then re-fires the existing filter pipeline
// (`execute-filters.js`'s `executeFilters()`) — same URL-state + AJAX
// slot-swap mechanism as every other `filter[...]` field, just triggered by
// a table header instead of a form control. Pure delegation, so headers
// re-rendered by a slot swap work without re-initialization.

import { executeFilters } from './execute-filters.js'

document.addEventListener('click', (e) => {
    const trigger = e.target.closest('[data-ak-sort]')
    if (!trigger) return

    const { column, formId, url } = JSON.parse(trigger.dataset.akSort)
    const form = document.getElementById(formId)
    const input = form?.elements.namedItem('filter[sort]')
    if (!input) return

    input.value = input.value === column ? `-${column}` : column

    executeFilters(formId, url, e)
})

export function init() {} // no-op — pure delegation
