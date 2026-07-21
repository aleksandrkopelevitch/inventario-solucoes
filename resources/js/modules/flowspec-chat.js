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
// ~12.5min at 2.5s/tick. Must stay ABOVE the server's stall window
// (FlowspecChat::REPLY_STALL_SECONDS, 660s): by then status() reports the turn
// resolved (a reply arrived, or the generation is declared dead and the
// composer reopens) and the slot swap removes the poll marker, so the client
// stops on its own. A lower ceiling gave up mid-window and left a slow-but-live
// reply invisible until a manual refresh. This ceiling is now only a backstop
// for a status endpoint that never resolves at all.
const MAX_POLL_ATTEMPTS = 300
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

/* ----------------------------------------------------------------------------
 * Composer (ChatGPT/Claude-style box — components/flowspec/composer.blade.php).
 * The 📎 attach menu reuses the existing context plumbing (no file upload):
 * it opens the systems/documents chips overlays and reveals the reference
 * panel; selections render as pills above the textarea.
 * ------------------------------------------------------------------------- */

function chipsFieldByName(form, name) {
    return Array.from((form || document).querySelectorAll('[data-ak-chips]')).find((el) => {
        try {
            return JSON.parse(el.dataset.akChips || '{}').name === name
        } catch {
            return false
        }
    })
}

function closeAttachMenu(form) {
    form?.querySelector('[data-ak-fs-menu]')?.classList.add('hidden')
}

// Collapse the pill row's padding/gap to zero when empty so an empty composer
// shows no band — WITHOUT display:none, which would hide the chips' fixed
// search overlays nested inside this wrapper (see composer.blade.php).
function syncPills(wrapper) {
    if (!wrapper) return
    const refPill = wrapper.querySelector('[data-ak-fs-reference-pill]')
    const hasChips = !!wrapper.querySelector('[data-ak-chip]')
    const hasReference = refPill && !refPill.classList.contains('hidden')
    const show = hasChips || hasReference
    wrapper.classList.toggle('pt-3', show)
    wrapper.classList.toggle('gap-1.5', show)
}

function autoGrow(el) {
    el.style.height = 'auto'
    el.style.height = `${el.scrollHeight}px`
}

document.addEventListener('click', (e) => {
    // 📎 menu → open a chips overlay via its (hidden) trigger.
    const openBtn = e.target.closest('[data-ak-fs-open]')
    if (openBtn) {
        const form = openBtn.closest('form')
        chipsFieldByName(form, openBtn.dataset.akFsOpen)?.querySelector('[data-ak-chips-trigger]')?.click()
        closeAttachMenu(form)
        return
    }

    // 📎 menu → open the reference-flowSpec modal (autofocus handles the caret).
    const refToggle = e.target.closest('[data-ak-fs-toggle-reference]')
    if (refToggle) {
        window.Modal?.open(refToggle.dataset.akFsToggleReference)
        closeAttachMenu(refToggle.closest('form'))
        return
    }

    // Reference pill × → clear the reference textarea.
    const refClear = e.target.closest('[data-ak-fs-reference-clear]')
    if (refClear) {
        const form = refClear.closest('form')
        const input = form?.querySelector('[data-ak-fs-reference-input]')
        if (input) {
            input.value = ''
            input.dispatchEvent(new Event('input', { bubbles: true }))
        }
        return
    }
})

document.addEventListener('input', (e) => {
    const ref = e.target.closest('[data-ak-fs-reference-input]')
    if (ref) {
        const form = ref.closest('form')
        form?.querySelector('[data-ak-fs-reference-pill]')?.classList.toggle('hidden', ref.value.trim() === '')
        syncPills(form?.querySelector('[data-ak-fs-pills]'))
        return
    }

    const input = e.target.closest('[data-ak-fs-input]')
    if (input) autoGrow(input)
})

// Enter sends (Shift+Enter = newline), like ChatGPT/Claude.
document.addEventListener('keydown', (e) => {
    const input = e.target.closest('[data-ak-fs-input]')
    if (!input || e.key !== 'Enter' || e.shiftKey) return

    e.preventDefault()
    input.closest('form')?.querySelector('[data-ak-fs-send]')?.click()
})

// Reset the composer after a message is sent (server-dispatched — see
// FlowspecMessageController). The composer lives outside the swapped thread
// slot, so it isn't re-rendered; reset it explicitly.
document.addEventListener('ak:flowspec-composer-reset', (e) => {
    const form = document.getElementById(e.detail?.formId)
    if (!form) return

    const message = form.querySelector('[data-ak-fs-input]')
    if (message) {
        message.value = ''
        autoGrow(message)
    }

    const reference = form.querySelector('[data-ak-fs-reference-input]')
    if (reference) reference.value = ''

    form.querySelector('[data-ak-fs-reference-pill]')?.classList.add('hidden')
    form.querySelector('[data-ak-fs-menu]')?.classList.add('hidden')
    syncPills(form.querySelector('[data-ak-fs-pills]'))
})

const observedPills = new WeakSet()

export function init() {
    // Keep each composer's pill row in sync with chip add/remove and the
    // reference pill (both mutated by other modules / server JS).
    document.querySelectorAll('[data-ak-fs-pills]').forEach((wrapper) => {
        syncPills(wrapper)
        if (observedPills.has(wrapper)) return
        observedPills.add(wrapper)
        new MutationObserver(() => syncPills(wrapper)).observe(wrapper, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['class'],
        })
    })

    document.querySelectorAll('[data-ak-fs-input]').forEach(autoGrow)

    // Keep the newest message in view (initial load + after each slot swap).
    const scroll = document.querySelector('[data-ak-fs-scroll]')
    if (scroll) scroll.scrollTop = scroll.scrollHeight

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
