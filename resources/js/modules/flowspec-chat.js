import * as ajaxModule from './ajax.js'
import { updateSlots } from './ajax-slot.js'

/**
 * flowSpec generator chat (F8).
 *
 * Polling: while the thread renders the [data-ak-flowspec-poll] marker
 * (last message is from the user — the GenerateFlowspecReply job is still
 * running), polls the status route; once `pending` turns false, swaps the
 * thread's slot for the response. The post-slot-update init() tears down
 * the timer once the marker disappears. Gives up after MAX_POLL_ATTEMPTS
 * (stuck queue, worker down, expired session) instead of retrying forever
 * with no signal to the user.
 *
 * Copy: [data-ak-flowspec-copy="pre-id"] copies the textContent (not
 * innerHTML — the JSON has `&&` in jsonPath, which would turn into
 * &amp;&amp;).
 */

const POLL_INTERVAL = 2500
const MAX_POLL_ATTEMPTS = 240 // ~10min at 2.5s/tick — well above the expected "a few minutes"
let timer = null
let attempts = 0

document.addEventListener('click', async (e) => {
    const trigger = e.target.closest('[data-ak-flowspec-copy]')
    if (!trigger) return

    const source = document.getElementById(trigger.dataset.akFlowspecCopy)
    if (!source) return

    await navigator.clipboard.writeText(source.textContent)
    Toast.show('JSON copiado — cole no canvas da Digibee.')
})

export function init() {
    const marker = document.querySelector('[data-ak-flowspec-poll]')

    if (!marker) {
        stopPolling()
        return
    }

    if (timer) return

    attempts = 0
    timer = setInterval(poll, POLL_INTERVAL)
}

function stopPolling() {
    if (timer) {
        clearInterval(timer)
        timer = null
    }
}

async function poll() {
    const marker = document.querySelector('[data-ak-flowspec-poll]')

    if (!marker) {
        stopPolling()
        return
    }

    attempts += 1

    if (attempts > MAX_POLL_ATTEMPTS) {
        stopPolling()
        Toast.show('A geração está demorando mais que o esperado — atualize a página para conferir o status.', 'warning')
        return
    }

    try {
        const response = await ajaxModule.init('GET', marker.dataset.akFlowspecPoll)
        const data = await response.json()

        if (!data.pending) {
            updateSlots(data) // re-initializes the modules; init() stops the timer
        }
    } catch (error) {
        // transient failure — the next tick retries, up to MAX_POLL_ATTEMPTS
    }
}
