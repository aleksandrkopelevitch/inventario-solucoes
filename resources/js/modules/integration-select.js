// Selects an integration in the solution detail list
// (`Solutions\IntegrationsMap`). Highlights the clicked row (aria-pressed)
// and fires `ak:integration-selected` (bubbling) with that integration's
// resolved graph — the actual drawing is done by `integration-viz.js`,
// keeping this module responsible only for selection. Pure event
// delegation — clicks on the delete button (which fires AJAX) are ignored
// so they don't also select the row.

document.addEventListener('click', (e) => {
    const row = e.target.closest('[data-ak-integration-select]')
    if (!row) return
    if (e.target.closest('button, a')) return // delete button/creation form don't select

    selectRow(row)
})

document.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' && e.key !== ' ') return

    const row = e.target?.closest?.('[data-ak-integration-select]')
    if (!row || e.target !== row) return

    e.preventDefault()
    selectRow(row)
})

function selectRow(row) {
    const list = row.closest('[data-ak-integration-list]') ?? document
    list.querySelectorAll('[data-ak-integration-select]').forEach((el) => el.setAttribute('aria-pressed', 'false'))
    row.setAttribute('aria-pressed', 'true')

    const name = row.getAttribute('data-integration-name') ?? ''
    const slug = row.getAttribute('data-ak-integration-select') ?? ''
    let graph = null
    const raw = row.getAttribute('data-integration-graph')
    if (raw) {
        try {
            graph = JSON.parse(raw)
        } catch {
            graph = null
        }
    }

    row.dispatchEvent(new CustomEvent('ak:integration-selected', {
        bubbles: true,
        detail: { name, slug, graph },
    }))
}

export function init() {} // no-op — listeners are module-level (delegation)
