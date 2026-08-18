import * as ajaxModule from './ajax.js'
import {updateSlots} from './ajax-slot.js'
import {setButtonLoading} from './button-loading.js'

/**
 * CATI interview composer — the conversation that fills a submission's
 * sections.
 *
 * Built against docs-chat.js's polling/composer pattern, minus the parts that
 * only exist because the documentation assistant sits next to a live editor:
 * there is no editor to lock here, and no separate "resume" marker either.
 * The thread renders inside the page (not a panel), so a reply still
 * generating re-renders its own [data-ak-cati-chat-poll] marker on the next
 * page load and init() picks the polling straight back up.
 *
 * Applying a draft is a plain data-ak-ajax button in the thread — it writes
 * the sections server-side and swaps the slots — so nothing about it lives in
 * this module.
 */

const POLL_INTERVAL = 2500
// ~12.5min at 2.5s/tick — comfortably past SubmissionChat::REPLY_STALL_SECONDS
// (660s), by which point the server already considers the turn dead.
const MAX_POLL_ATTEMPTS = 300

let timer = null
let attempts = 0

/* ------------------------------------------------------------------ */
/*  Sending                                                            */
/* ------------------------------------------------------------------ */

document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-ak-cati-chat-send]')
    if (!btn) return
    e.preventDefault()
    send(btn)
})

// Enter sends, Shift+Enter breaks the line.
document.addEventListener('keydown', (e) => {
    const input = e.target.closest('[data-ak-cati-chat-input]')
    if (!input || e.key !== 'Enter' || e.shiftKey) return
    e.preventDefault()
    input.closest('form')?.querySelector('[data-ak-cati-chat-send]')?.click()
})

document.addEventListener('input', (e) => {
    const input = e.target.closest('[data-ak-cati-chat-input]')
    if (input) autoGrow(input)
})

async function send(btn) {
    const form = btn.closest('form') || document
    const input = form.querySelector('[data-ak-cati-chat-input]')
    const message = (input?.value || '').trim()

    if (!message) {
        Toast.show('Escreva alguma coisa antes de enviar.', 'warning')
        return
    }

    const formData = new FormData()
    formData.append('message', message)

    setButtonLoading(btn, true)
    try {
        const response = await ajaxModule.init('POST', btn.dataset.action, formData)
        const data = await response.json()
        updateSlots(data) // re-initializes modules; init() below picks up the new poll marker
        if (data.js) {
            try {
                // eslint-disable-next-line no-new-func
                new Function(data.js)()
            } catch (_) {
                // best-effort — the message still sent
            }
        }
    } catch (error) {
        let text = 'Não consegui enviar a mensagem.'
        if (error.response) {
            try {
                text = (await error.response.json()).message ?? text
            } catch (_) {
                // keep the default
            }
        }
        Toast.open({content: text, title: 'Atenção', type: 'warning'})
    } finally {
        setButtonLoading(btn, false)
    }
}

// The composer lives outside the swapped thread slot, so updateSlots() doesn't
// clear it on its own (server-dispatched — see SubmissionChatController::store).
document.addEventListener('ak:cati-chat-composer-reset', () => {
    document.querySelectorAll('[data-ak-cati-chat-input]').forEach((input) => {
        input.value = ''
        autoGrow(input)
    })
})

function autoGrow(el) {
    el.style.height = 'auto'
    el.style.height = `${el.scrollHeight}px`
}

/* ------------------------------------------------------------------ */
/*  Polling while the thread says a reply is being generated           */
/* ------------------------------------------------------------------ */

function stopPoll() {
    if (timer) {
        clearInterval(timer)
        timer = null
    }
}

async function poll(url) {
    const marker = document.querySelector('[data-ak-cati-chat-poll]')
    if (!marker) {
        stopPoll()
        return
    }

    attempts += 1
    if (attempts > MAX_POLL_ATTEMPTS) {
        // Never poll forever in silence: if the worker is down, say so.
        stopPoll()
        Toast.show('A resposta está demorando mais que o esperado — atualize a página para conferir.', 'warning')
        return
    }

    try {
        const response = await ajaxModule.init('GET', url)
        const data = await response.json()
        if (!data.pending) {
            stopPoll()
            updateSlots(data)
            scrollToEnd()
        }
    } catch (_) {
        // transient failure — the next tick retries, up to MAX_POLL_ATTEMPTS
    }
}

function scrollToEnd() {
    const scroll = document.querySelector('[data-ak-cati-chat-scroll]')
    if (scroll) scroll.scrollTop = scroll.scrollHeight
}

// Called on load and after every slot swap. Derives the polling state from
// whatever is in the DOM right now, so it's correct after a send, after a
// tick, and on a fresh load with a reply still in flight.
export function init() {
    const marker = document.querySelector('[data-ak-cati-chat-poll]')

    if (marker) {
        if (!timer) {
            attempts = 0
            timer = setInterval(() => poll(marker.dataset.akCatiChatPoll), POLL_INTERVAL)
        }
    } else {
        stopPoll()
    }

    scrollToEnd()
    document.querySelectorAll('[data-ak-cati-chat-input]').forEach(autoGrow)
}
