/**
 * Watches a running Digibee lifecycle and swaps the panel as each round lands.
 *
 * It is `flowspec-chat.js`'s polling shape with one difference that matters:
 * there, the poll waits for ONE resolution and swaps once; here the run
 * publishes progress cycle by cycle, so the slot is swapped repeatedly while
 * the run is still going. The server decides when there is something new — the
 * marker carries how many rounds this client has already rendered and sends it
 * as `seen`, so a tick that changed nothing costs a query and no render.
 *
 * The marker lives INSIDE the slot, so the swap that settles a run removes it
 * and the loop stops itself.
 */

import * as ajaxModule from './ajax.js'
import { updateSlots } from './ajax-slot.js'

const POLL_INTERVAL = 4000

// ~20min at 4s/tick, comfortably past the server's own reaping window
// (PipelineRun::STALE_AFTER_SECONDS, 1800s): by then the status endpoint has
// declared a silent run dead and the swap removes the marker, so the client
// stops on its own. This ceiling is only a backstop for an endpoint that never
// resolves at all — a run really can take many minutes, because every round is
// a real deployment plus a battery.
const MAX_POLL_ATTEMPTS = 300

let timer = null
let attempts = 0

function stopPolling() {
    if (timer) clearInterval(timer)
    timer = null
    attempts = 0
}

async function poll() {
    const marker = document.querySelector('[data-ak-lifecycle-poll]')

    if (!marker) {
        stopPolling()
        return
    }

    attempts += 1

    if (attempts > MAX_POLL_ATTEMPTS) {
        stopPolling()
        Toast.show('A execução está demorando mais que o esperado — atualize a página para conferir.', 'warning')
        return
    }

    const url = marker.dataset.akLifecyclePoll
    const seen = marker.dataset.akLifecycleSeen ?? '0'

    try {
        const response = await ajaxModule.init('GET', `${url}?seen=${encodeURIComponent(seen)}`)
        const data = await response.json()

        // Swapped whenever the server sent markup — a new round while the run
        // continues, or the final state. `updateSlots` re-runs init(), which
        // re-reads the marker (or stops when it is gone).
        if (data.updatableSlots) updateSlots(data)
    } catch (error) {
        // transient failure — the next tick retries, up to MAX_POLL_ATTEMPTS
    }
}

export function init() {
    const marker = document.querySelector('[data-ak-lifecycle-poll]')

    if (!marker) {
        stopPolling()
        return
    }

    if (timer) return

    attempts = 0
    timer = setInterval(poll, POLL_INTERVAL)
}
