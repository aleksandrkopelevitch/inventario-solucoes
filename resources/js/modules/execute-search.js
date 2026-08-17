import {applyFilters} from "./execute-filters"
import {getURLWithoutSearchParams} from "./search-params"

const DEBOUNCE_MS = 350
const MIN_LENGTH = 3

// Module-level timer (not per-element): only one search input triggers this at
// a time in practice, and a single timer avoids leaking one per re-init.
let debounceTimer

export function init() {
    let searchTriggers = document.querySelectorAll('[data-ak-search]')
    if (searchTriggers[0]) {
        searchTriggers.forEach((trigger) => {

            let data = trigger.dataset.akSearch ? JSON.parse(trigger.dataset.akSearch) : {}

            if (!data.inputId || trigger.searchEventAdded === true) {
                return
            }

            trigger.addEventListener('keyup', (event) => {
                clearTimeout(debounceTimer)

                if (event.key === 'Enter') {
                    handleSearchInput(data.inputId, data.url)
                    return
                }

                debounceTimer = setTimeout(() => handleSearchInput(data.inputId, data.url), DEBOUNCE_MS)
            })
            trigger.searchEventAdded = true
        })
    }
}

function handleSearchInput(inputId, url) {
    const input = document.getElementById(inputId)
    if (!input) return

    const hint = document.querySelector(`[data-ak-search-hint="${inputId}"]`)
    const term = input.value.trim()

    if (term.length > 0 && term.length < MIN_LENGTH) {
        // Terse on purpose: the hint used to sit on its own reserved line under
        // the field and can now afford a sentence no longer. It renders INSIDE
        // the field, at its right edge (see x-ui.filter-search), which is only
        // free of typed text because this branch is exactly the 1..2-character
        // case. A full sentence here would run under the caret.
        if (hint) hint.textContent = `mín. ${MIN_LENGTH} letras`
        return
    }

    if (hint) hint.textContent = ''

    sendSearchToBrowser(input, url)
}

export function sendSearchToBrowser(input, url) {
    let browserSearchParams = new URLSearchParams(location.search)

    // Preserve any active filters (already in the URL) and add the search term
    // under the configured param name (default filter[search]).
    const paramName = input.dataset.akSearchParam || 'filter[search]'
    const term = input.value.trim()

    if (term) {
        browserSearchParams.set(paramName, term)
    } else {
        browserSearchParams.delete(paramName)
    }

    let newUrl = getURLWithoutSearchParams() + '?' + browserSearchParams.toString()
    history.replaceState(null, null, newUrl)
    applyFilters(browserSearchParams, url)
}
