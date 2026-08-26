import {getURLWithoutSearchParams} from "./search-params"
import {updateSlots} from "./ajax-slot"
import {showWarning} from "./ajax-post"
import * as ajaxModule from './ajax'

// Matches the `duration-150` transition on `[data-ak-chip]` in
// components/solutions/filter-chips.blade.php — the whole chips slot is
// replaced wholesale by the next AJAX response, so there's no DOM node left
// to animate out by then; instead we play the fade/shrink first and only
// fire the actual filter change once it has visibly finished.
const CHIP_EXIT_MS = 150

// Matches the `$activeClass` string baked server-side into `<x-forms.select>`
// via `filled($filters[...]) ? $activeClass : ''` (solutions/companies/
// diagrams/people index pages). That class is only ever computed at
// render time, so picking a filter value via AJAX left the dropdown
// visually unmarked until a full reload re-rendered the Blade view with the
// new $filters — this mirrors the same classes client-side so the highlight
// applies instantly on `change`, no reload needed.
const ACTIVE_FILTER_CLASSES = ['!border-accent', '!bg-accent-soft', '!text-accent', '!font-semibold']

/** Highlights a filter `<select>` the moment its value changes. Selects that
 *  represent an optional filter always carry a blank `<option value="">`
 *  placeholder (e.g. "Categoria"); the sort select doesn't, so it's
 *  naturally excluded from this treatment — matching the server-side
 *  `filled($filters[...])` check, which is likewise never applied to sort. */
function syncSelectActiveState(select) {
    if (!(select instanceof HTMLSelectElement)) return
    if (!select.querySelector('option[value=""]')) return

    const isActive = select.value !== ''
    ACTIVE_FILTER_CLASSES.forEach((cls) => select.classList.toggle(cls, isActive))
}

// Delegated (not per-element init()) — chips are re-rendered on every AJAX
// slot swap, so a fresh node is always present without needing re-registration.
document.addEventListener('click', (event) => {
    const clearOne = event.target.closest('[data-ak-filters-clear]')
    if (clearOne) {
        const data = JSON.parse(clearOne.dataset.akFiltersClear)
        animateChipsOutThen(clearOne.closest('[data-ak-chip]'), () => clearFilterField(data.formId, data.field, data.url))
        return
    }

    const clearAll = event.target.closest('[data-ak-filters-clear-all]')
    if (clearAll) {
        const data = JSON.parse(clearAll.dataset.akFiltersClearAll)
        const chips = clearAll.parentElement ? clearAll.parentElement.querySelectorAll('[data-ak-chip]') : []
        animateChipsOutThen(chips, () => clearAllFilterFields(data.formId, data.url))
    }
})

// Filter/search forms have no method/action — they're AJAX-only. Without
// this, pressing Enter in the search input natively submits the form (GET,
// current URL, every field serialized), a full-page navigation that bypasses
// applyFilters()/history.replaceState() entirely.
document.addEventListener('submit', (event) => {
    if (event.target.querySelector('[data-ak-filters], [data-ak-search]')) {
        event.preventDefault()
    }
})

function animateChipsOutThen(chipOrChips, done) {
    const chips = chipOrChips instanceof Element ? [chipOrChips] : Array.from(chipOrChips || [])

    if (!chips.length) {
        done()
        return
    }

    chips.forEach((chip) => chip.classList.add('scale-90', 'opacity-0'))
    setTimeout(done, CHIP_EXIT_MS)
}

export function init() {
    let filterTriggers = document.querySelectorAll('[data-ak-filters]')
    if (filterTriggers[0]) {
        filterTriggers.forEach((trigger) => {

            let data = trigger.dataset.akFilters ? JSON.parse(trigger.dataset.akFilters) : {}

            if (data.clearFilters === true) {
                if (trigger.clearFiltersEventAdded !== true) {
                    trigger.addEventListener(data.event || 'click', (event) => {
                            clearFiltersAndSubmit()
                    }, {once: true})
                    trigger.clearFiltersEventAdded = true
                    return
                }
            }

            if (data.clearOneFilter === true) {
                if (trigger.clearOneFilterEventAdded !== true) {
                    trigger.addEventListener(data.event || 'click', (event) => {
                        clearOneFilter(data.filterName)
                    }, {once: true})
                    trigger.clearOneFilterEventAdded = true
                    return
                }
            }

            if (!data.formId) {
                return false
            }

            let options = {
                once: data.once || false,
            }

            if (trigger.filtersEventAdded !== true) {

                trigger.addEventListener(data.event || 'click', (event) => {

                    syncSelectActiveState(trigger)

                    if (data.event === 'keypress' || data.event === 'keyup') {
                        let key = event.key
                        if (key === 13 || key === 'Enter') {
                            executeFilters(data.formId, data.url, event)
                        }
                    } else {
                        executeFilters(data.formId, data.url, event)
                    }

                }, options)
                trigger.filtersEventAdded = true
            }
        })
    }
}

export function executeFilters(formId, url, e) {

    let form = document.getElementById(formId) || false

    if (form) {

        let formData         = new FormData(form)
        let filteredFormData = new FormData()

        for (let [key, value] of formData.entries()) {
            if (key.startsWith('filter')) {
                filteredFormData.append(key, value)
            }
        }

        if (!filteredFormData.entries().next().done) {

            let browserSearchParams = new URLSearchParams(location.search)
            browserSearchParams     = clearFiltersAndReturn(browserSearchParams)

            let filterQuery = new URLSearchParams(filteredFormData)

            for (let [key, value] of filterQuery.entries()) {
                browserSearchParams.append(key, value)
            }

            let newUrl = getURLWithoutSearchParams() + '?' + browserSearchParams.toString()
            history.replaceState(null, null, newUrl)
            applyFilters(browserSearchParams, url)
            e.stopPropagation()
        }
    }
}

/** Clears a single `filter[...]` field (individual chip) and resubmits via AJAX. */
export function clearFilterField(formId, field, url) {
    const form = document.getElementById(formId)
    if (!form) return

    const el = form.elements.namedItem(field)
    if (!el) return

    if (el instanceof RadioNodeList) {
        el.forEach((node) => resetFilterField(node))
    } else {
        resetFilterField(el)
    }

    executeFilters(formId, url, {stopPropagation() {}})
}

/** Clears every `filter[...]` field in the form, except sorting, and resubmits via AJAX. */
export function clearAllFilterFields(formId, url) {
    const form = document.getElementById(formId)
    if (!form) return

    Array.from(form.elements).forEach((el) => {
        if (el.name && el.name.startsWith('filter') && el.name !== 'filter[sort]') {
            resetFilterField(el)
        }
    })

    executeFilters(formId, url, {stopPropagation() {}})
}

function resetFilterField(el) {
    if (el.type === 'checkbox' || el.type === 'radio') {
        el.checked = false
    } else {
        el.value = ''
        syncSelectActiveState(el)
    }
}

/** Shows/hides spinners (`data-ak-filters-loading`) and dims results
 *  (`data-ak-filters-dim`) while a search/filter request is in flight. */
function setFiltersLoading(isLoading) {
    document.querySelectorAll('[data-ak-filters-loading]').forEach((el) => {
        el.classList.toggle('hidden', !isLoading)
    })
    document.querySelectorAll('[data-ak-filters-dim]').forEach((el) => {
        el.classList.toggle('opacity-50', isLoading)
        el.classList.toggle('pointer-events-none', isLoading)
    })
}

export function clearOneFilterAndReturn(searchParamsObject, filterName) {
    for (let [key, value] of searchParamsObject.entries()) {
        if (key === filterName) {
            searchParamsObject.delete(key)
        }
    }
    return searchParamsObject
}

export function clearFiltersAndReturn(searchParamsObject) {
    let newSearchParamsObject = new URLSearchParams()
    for (let [key, value] of searchParamsObject.entries()) {

        if (!key.startsWith('filter')) {
            newSearchParamsObject.append(key, value)
        }

        if (key.startsWith('page')){
            newSearchParamsObject.delete('page')
        }

    }
    return newSearchParamsObject
}

export function clearFiltersAndSubmit() {
    let filterQuery = new URLSearchParams(location.search)

    filterQuery = clearFiltersAndReturn(filterQuery)

    window.location.search = filterQuery
}

export function clearOneFilter(filterName) {
    let filterQuery = new URLSearchParams(location.search)

    filterQuery = clearOneFilterAndReturn(filterQuery, filterName)

    window.location.search = filterQuery
}

// Sequence number of the last request fired: responses from older requests
// that arrive AFTER a newer one (fast-typed debounced search, two filter
// clicks in a row) are discarded — without this, the late response would
// overwrite the slot with stale results while the URL (already updated via
// replaceState) says something else.
let latestFilterRequest = 0

export function applyFilters(formData, url) {
    let searchParams = new URLSearchParams(formData)
    const requestId = ++latestFilterRequest

    setFiltersLoading(true)

    ajaxModule.init('GET', url + '?' + searchParams)
        .then((response) => response.json())
        .then((data) => {
            if (requestId !== latestFilterRequest) return
            updateSlots(data)
        })
        .catch((error) => {
            if (requestId !== latestFilterRequest) return
            console.error('Error applying filters:', error)
            showWarning({message: 'Não foi possível aplicar o filtro. Tente novamente.'})
        })
        .finally(() => {
            // Only the most recent request controls the spinner — the 1st of
            // two in flight would finish first and clear loading while the 2nd is still pending.
            if (requestId === latestFilterRequest) setFiltersLoading(false)
        })
}
