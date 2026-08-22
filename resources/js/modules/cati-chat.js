import * as ajaxModule from './ajax.js'
import {updateSlots} from './ajax-slot.js'
import {setButtonLoading} from './button-loading.js'

/**
 * CATI interview composer — the conversation that fills a submission's
 * sections, and the material it reads from.
 *
 * Built against docs-chat.js's polling/composer pattern, minus the parts that
 * only exist because the documentation assistant sits next to a live editor:
 * there is no editor to lock here, and no separate "resume" marker either.
 * The thread renders inside the page (not a panel), so a reply still
 * generating re-renders its own [data-ak-cati-chat-poll] marker on the next
 * page load and init() picks the polling straight back up.
 *
 * Attaching material is the composer's job, the way it is in the Claude
 * client: a long paste becomes a text attachment, a dropped or picked file is
 * uploaded, a link is registered. All three post to the same endpoint
 * (submissions.sources.store) and come back as slots, so the chips above the
 * textarea, the Material card, the stage strip and the structural checklist
 * all move together.
 *
 * The one thing that is NOT like flowspec-chat.js: there is no staging mode.
 * The conversation always exists by the time this page renders
 * (SubmissionController::chatFor opens it on the GET), and material belongs
 * to the submission rather than to the chat, so everything attaches
 * immediately.
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
/*  Composer config                                                    */
/* ------------------------------------------------------------------ */

function composerOf(el) {
    return el?.closest('[data-ak-cati-composer]') ?? document.querySelector('[data-ak-cati-composer]')
}

function configOf(form) {
    try {
        return JSON.parse(form?.dataset.akCatiComposer || '{}')
    } catch (_) {
        return {}
    }
}

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
        Toast.open({content: await errorMessage(error, 'Não consegui enviar a mensagem.'), title: 'Atenção', type: 'warning'})
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

async function errorMessage(error, fallback) {
    if (!error?.response) return fallback

    try {
        return (await error.response.json()).message ?? fallback
    } catch (_) {
        return fallback
    }
}

/* ------------------------------------------------------------------ */
/*  Attaching material                                                 */
/* ------------------------------------------------------------------ */

/**
 * One entry point for all three kinds. `payload` is whatever
 * StoreSubmissionSourceRequest accepts: `file`, `text` (+ `label`), or `url`.
 *
 * Resolves to whether it landed, so a caller attaching several things in a
 * row can stop at the first refusal instead of firing the rest at a server
 * that has already said no.
 */
async function attach(form, payload) {
    const url = configOf(form).attachUrl
    if (!url) return false

    // No `_token` in the body: ajax.js sends the CSRF token as a header.
    const body = new FormData()
    Object.entries(payload).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') body.append(key, value)
    })

    try {
        const response = await ajaxModule.init('POST', url, body)
        const data = await response.json()
        updateSlots(data)
        if (data.message) Toast.open({content: data.message, type: data.type || 'success'})

        return true
    } catch (error) {
        Toast.show(await errorMessage(error, 'Não foi possível anexar.'), 'warning')

        return false
    }
}

/**
 * One file at a time, awaited — never a request per file fired at once.
 *
 * Same reasoning as the flowSpec composer: batching the whole selection into
 * one body exceeds `post_max_size` long before `max:20480` per file does, so a
 * multi-file pick would start failing as a truncated request instead of as an
 * honest 422. Stopping at the first refusal keeps a rejected batch from
 * earning one identical Toast per file.
 */
async function attachFiles(form, files) {
    for (const file of Array.from(files)) {
        if (!(await attach(form, {file}))) return
    }
}

/** 📎 → file dialog. */
document.addEventListener('click', (e) => {
    if (e.target.closest('[data-ak-cati-open-file]')) {
        e.preventDefault()
        composerOf(e.target)?.querySelector('[data-ak-cati-file-input]')?.click()
    }
})

document.addEventListener('change', async (e) => {
    const input = e.target.closest('[data-ak-cati-file-input]')
    if (!input || !input.files?.length) return

    const form = composerOf(input)
    const files = Array.from(input.files)
    // Cleared BEFORE the upload, not after: the input is inside the composer's
    // own <form>, and leaving the selection on it would re-send the same bytes
    // with anything else that ever posts this form.
    input.value = ''

    await attachFiles(form, files)
})

/** 📎 → "Anexar" next to the link field. */
document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-ak-cati-link-add]')
    if (!btn) return
    e.preventDefault()

    const form = composerOf(btn)
    const input = form?.querySelector('[data-ak-cati-link-input]')
    const url = (input?.value || '').trim()

    if (!url) {
        Toast.show('Cole o endereço antes de anexar.', 'warning')
        return
    }

    setButtonLoading(btn, true)
    try {
        if (await attach(form, {url})) input.value = ''
    } finally {
        setButtonLoading(btn, false)
    }
})

/**
 * A long paste becomes a text attachment instead of a wall of text in the
 * composer — the Claude client's behaviour, and the reason it is worth the
 * code: pasted into the message, an old architecture document would bury the
 * conversation, be re-sent verbatim as history on every later turn, and leave
 * nothing to detach afterwards. As material it is a row someone can read,
 * check for credentials, and remove.
 *
 * A pasted IMAGE or file goes through the same door as one picked from disk.
 */
document.addEventListener('paste', (e) => {
    const input = e.target.closest('[data-ak-cati-chat-input]')
    if (!input) return

    const form = composerOf(input)
    const files = Array.from(e.clipboardData?.files ?? [])

    if (files.length) {
        e.preventDefault()
        attachFiles(form, files)
        return
    }

    const text = e.clipboardData?.getData('text/plain') ?? ''
    const config = configOf(form)
    const threshold = config.pasteThreshold || 2000

    if (text.length <= threshold) return

    e.preventDefault()

    // Refused here as well as server-side, because the server's 422 arrives
    // after the whole payload was uploaded and the paste is already gone from
    // the clipboard's point of view.
    if (config.maxPastedChars && text.length > config.maxPastedChars) {
        Toast.show('O texto colado é grande demais. Anexe como arquivo.', 'warning')
        return
    }

    // No Toast here: the server's own message says a paste became material
    // (SubmissionSourceController::confirmation), and announcing it twice for
    // one gesture stacks two notifications.
    attach(form, {text, label: labelForPaste(text)})
})

/** First non-blank line of a paste, so four pasted blocks are tellable apart. */
function labelForPaste(text) {
    const first = (text.split('\n').find((line) => line.trim() !== '') || '').trim()
    if (!first) return 'Texto colado'
    return first.length > 60 ? `${first.slice(0, 57)}…` : first
}

/* ---------------------------- drag & drop --------------------------------- */

// `dragging` on the form drives the drop highlight (a data-attribute variant
// in composer.blade.php, so there is no class list to keep in step here).
document.addEventListener('dragover', (e) => {
    const form = e.target.closest?.('[data-ak-cati-composer]')
    if (!form || !e.dataTransfer?.types?.includes('Files')) return

    e.preventDefault()
    form.dataset.dragging = 'true'
})

document.addEventListener('dragleave', (e) => {
    const form = e.target.closest?.('[data-ak-cati-composer]')
    // Only when the pointer actually left the composer — dragging across a
    // child fires dragleave for the child on the way out of it.
    if (form && !form.contains(e.relatedTarget)) delete form.dataset.dragging
})

document.addEventListener('drop', (e) => {
    const form = e.target.closest?.('[data-ak-cati-composer]')
    if (!form) return

    const files = Array.from(e.dataTransfer?.files ?? [])
    if (!files.length) return

    e.preventDefault()
    delete form.dataset.dragging
    attachFiles(form, files)
})

/* ------------------------------------------------------------------ */
/*  Jumping from the progress rail to a section                        */
/* ------------------------------------------------------------------ */

// The section cards live on the "Documento" tab, which is display:none until
// its trigger is clicked — so switch the tab first, then scroll. Clicking the
// trigger (rather than calling tabs.js) keeps the active/inactive class
// bookkeeping in the one place that owns it.
document.addEventListener('click', (e) => {
    const trigger = e.target.closest('[data-ak-cati-goto-section]')
    if (!trigger) return

    document.querySelectorAll('[data-ak-tabs]').forEach((tab) => {
        try {
            if (JSON.parse(tab.dataset.akTabs || '{}').targetId === 'submission-tab-document') tab.click()
        } catch (_) {
            // not our tab group
        }
    })

    document
        .getElementById(`submission-section-${trigger.dataset.akCatiGotoSection}`)
        ?.scrollIntoView({behavior: 'smooth', block: 'start'})
})

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
