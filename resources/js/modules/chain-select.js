// Selects a diagram row. Highlights it (aria-pressed) and fires
// `ak:diagram-selected` (bubbling) with that diagram's resolved
// graph — the actual drawing is done by `chain-viz.js`, keeping this
// module responsible only for selection. Pure event delegation — clicks on
// the delete button (which fires AJAX) are ignored so they don't also
// select the row.
//
// Every page that mounts the canvas (`Diagrams\Workspace` — a diagram's own
// page, or the Diagrama tab of a documentation page pointing at it) renders
// exactly one row, hidden: there's nothing else to pick from, so init()
// auto-selects it instead of waiting for a click.

document.addEventListener('click', (e) => {
    const row = e.target.closest('[data-ak-chain-select]')
    if (!row) return
    if (e.target.closest('button, a')) return // delete button/creation form don't select

    selectRow(row)
})

document.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' && e.key !== ' ') return

    const row = e.target?.closest?.('[data-ak-chain-select]')
    if (!row || e.target !== row) return

    e.preventDefault()
    selectRow(row)
})

function selectRow(row) {
    const list = row.closest('[data-ak-diagram-list]') ?? document
    list.querySelectorAll('[data-ak-chain-select]').forEach((el) => el.setAttribute('aria-pressed', 'false'))
    row.setAttribute('aria-pressed', 'true')

    const name = row.getAttribute('data-diagram-name') ?? ''
    const slug = row.getAttribute('data-ak-chain-select') ?? ''
    let graph = null
    const raw = row.getAttribute('data-ak-chain-graph')
    if (raw) {
        try {
            graph = JSON.parse(raw)
        } catch {
            graph = null
        }
    }

    row.dispatchEvent(new CustomEvent('ak:diagram-selected', {
        bubbles: true,
        detail: { name, slug, graph },
    }))
}

export function init() {
    // Deferred to a microtask: `initAllModules()` calls every module's
    // init() synchronously in one pass, and `chain-viz.js`'s own
    // init() (which mounts the canvas and populates the roots this event
    // draws into) may run before OR after this one depending on registration
    // order in app.js. Queuing the dispatch guarantees it fires only once
    // that whole synchronous pass has finished, regardless of order.
    const rows = document.querySelectorAll('[data-ak-chain-select]')
    if (rows.length !== 1 || rows[0].getAttribute('aria-pressed') === 'true') return

    queueMicrotask(() => selectRow(rows[0]))
}
