import * as ajaxModule from './ajax.js'
import { updateSlots } from './ajax-slot.js'
import {fold} from './fold.js'

/**
 * Especialista em Integrações chat (F8) — generates Digibee flowSpec JSON.
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
 * Composer — components/flowspec/composer.blade.php
 *
 * There are exactly TWO kinds of context, and both arrive through this module:
 * documentation already in the inventory (the picker panel) and material the
 * user brings (a file from disk, or a long paste that becomes a text
 * attachment, the way the Claude client does it).
 *
 * Everything here branches on ONE thing: whether a conversation exists yet.
 *
 * - It does (`chatId` set): attach immediately over AJAX. The server answers
 *   with the context panel's slot, so the list and the token meter are always
 *   server-truth — never a client guess.
 * - It doesn't (the new-chat screen): STAGE it. Staged context is rendered as
 *   pills plus real hidden inputs inside the form, so the ordinary
 *   `new FormData(form)` in ajax-post.js carries it along with the first
 *   message and no custom submit path is needed.
 * ------------------------------------------------------------------------- */

const SUGGEST_DEBOUNCE_MS = 600
const SUGGEST_MIN_LENGTH = 3

// form -> { files: File[], texts: [{content, label}], documents: [{ref, label}] }
const staged = new WeakMap()
// form -> pending suggestion debounce timer
const suggestTimers = new WeakMap()
// form -> id of the most recent suggestion request, so a slow response to an
// earlier keystroke can't repaint over a newer one (same guard as chips.js).
const suggestSeq = new WeakMap()

function configOf(form) {
    try {
        return JSON.parse(form?.dataset.akFsComposer || '{}')
    } catch {
        return {}
    }
}

function stagedOf(form) {
    if (!staged.has(form)) staged.set(form, { files: [], texts: [], documents: [] })
    return staged.get(form)
}

function closeAttachMenu(form) {
    form?.querySelector('[data-ak-fs-menu]')?.classList.add('hidden')
}

function autoGrow(el) {
    el.style.height = 'auto'
    el.style.height = `${el.scrollHeight}px`
}

function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')
}

/** Room left in the context window, as the server last reported it. */
function attachableTokens(form) {
    try {
        return JSON.parse(form?.querySelector('[data-ak-fs-context]')?.dataset.akFsContext || '{}').attachable ?? 0
    } catch {
        return 0
    }
}

/**
 * Refuses locally what the server would refuse anyway, so a 20MB upload isn't
 * sent just to be told there's no room. The server check is still the real one
 * — this only saves the round trip.
 */
function contextIsFull(form) {
    if (attachableTokens(form) > 0) return false

    Toast.show('O contexto desta conversa está no limite. Remova algum documento ou arquivo antes de anexar outro.', 'warning')
    return true
}

/* ----------------------------------- staging ------------------------------ */

/**
 * Files can't be assigned to a file input one by one — `input.files` is a
 * read-only FileList. A DataTransfer is the one writable way to build one, and
 * rebuilding it from the staged array on every add/remove is what lets
 * `new FormData(form)` pick the staged files up natively.
 */
function syncStagedFiles(form) {
    const input = form.querySelector('[data-ak-fs-file-input]')
    if (!input) return

    const transfer = new DataTransfer()
    stagedOf(form).files.forEach((file) => transfer.items.add(file))
    input.files = transfer.files
}

function renderStaged(form) {
    const wrapper = form.querySelector('[data-ak-fs-pending]')
    if (!wrapper) return

    const state = stagedOf(form)
    const pills = []

    state.documents.forEach((doc, i) => {
        pills.push(pillHtml(doc.label, `document:${i}`, [
            `<input type="hidden" name="documents[]" value="${escapeHtml(doc.ref)}">`,
        ]))
    })

    state.files.forEach((file, i) => {
        // No hidden input: the file input itself carries these (syncStagedFiles).
        pills.push(pillHtml(file.name, `file:${i}`, []))
    })

    state.texts.forEach((text, i) => {
        pills.push(pillHtml(text.label, `text:${i}`, [
            `<input type="hidden" name="texts[${i}][content]" value="${escapeHtml(text.content)}">`,
            `<input type="hidden" name="texts[${i}][label]" value="${escapeHtml(text.label)}">`,
        ]))
    })

    wrapper.innerHTML = pills.join('')
    wrapper.classList.toggle('pt-3', pills.length > 0)
}

function pillHtml(label, key, inputs) {
    return `<span class="inline-flex max-w-full items-center gap-1.5 rounded-full border border-dashed border-line-2 bg-raised py-1 pl-2.5 pr-1 text-xs font-medium text-body">
        <span class="truncate" title="${escapeHtml(label)}">${escapeHtml(label)}</span>
        <button type="button" data-ak-fs-unstage="${escapeHtml(key)}" aria-label="Remover ${escapeHtml(label)}"
                class="ml-0.5 rounded-full px-1 leading-none text-muted hover:bg-hot-soft hover:text-hot">&times;</button>
        ${inputs.join('')}
    </span>`
}

/* ---------------------------------- attaching ----------------------------- */

/**
 * One entry point for both kinds of context and both destinations. `payload` is
 * whatever the attach endpoint accepts (`file`, `text`+`label`, `documents[]`);
 * `stage` describes the same thing for the staged list.
 *
 * Resolves to whether the context is now there, so a caller attaching several
 * things in a row can stop at the first refusal instead of firing the rest at
 * a server that has already said no.
 */
async function addContext(form, { payload, stage }) {
    const config = configOf(form)

    if (!config.chatId) {
        const state = stagedOf(form)
        if (stage.kind === 'file') state.files.push(stage.file)
        if (stage.kind === 'text') state.texts.push({ content: stage.content, label: stage.label })
        if (stage.kind === 'documents') {
            // Deduped here as well as server-side (AttachFlowspecDocuments):
            // picking the same page twice is an ordinary thing to do — the
            // picker and the assistant's suggestion buttons point at the same
            // references — and two identical pills read as a bug.
            const already = new Set(state.documents.map((doc) => doc.ref))
            stage.documents
                .filter((doc) => !already.has(doc.ref))
                .forEach((doc) => state.documents.push(doc))
        }

        renderStaged(form)
        syncStagedFiles(form)
        return true
    }

    // No `_token` in the body: ajax.js sends the CSRF token as a header.
    const body = new FormData()
    Object.entries(payload).forEach(([key, value]) => {
        if (Array.isArray(value)) value.forEach((item) => body.append(`${key}[]`, item))
        else if (value !== null && value !== undefined) body.append(key, value)
    })

    try {
        const response = await ajaxModule.init('POST', config.attachUrl, body)
        const data = await response.json()
        updateSlots(data)
        if (data.message) Toast.open({ content: data.message, type: data.type || 'success' })

        return true
    } catch (error) {
        let message = 'Não foi possível anexar.'
        if (error.response) {
            try {
                message = (await error.response.json()).message ?? message
            } catch {
                /* keep the fallback */
            }
        }
        Toast.show(message, 'warning')

        return false
    }
}

/**
 * One file at a time, awaited — never a request per file fired at once.
 *
 * The context ceiling is enforced per REQUEST (GuardsFlowspecContext), against
 * the conversation as it stands when that request is validated. Firing the
 * whole selection in parallel means every one of them is measured against the
 * state before ANY of them landed, so files that each fit can collectively
 * blow a limit all of them passed — and the last response to arrive repaints
 * the context panel from a snapshot taken before its siblings committed.
 *
 * Batching them into a single `files[]` request would also make the check
 * atomic, but it puts the whole selection in one body: `post_max_size` is well
 * below what `max:20480` per file allows, so a multi-file pick would start
 * failing as a truncated request instead of as an honest 422.
 */
async function attachFiles(form, files) {
    const selection = Array.from(files)
    if (!selection.length || contextIsFull(form)) return

    for (const file of selection) {
        // Stops at the first refusal: once the server has said the context is
        // full, the remaining files would each earn their own identical Toast.
        const attached = await addContext(form, { payload: { file }, stage: { kind: 'file', file } })
        if (!attached) return
    }
}

function attachText(form, content, label) {
    if (contextIsFull(form)) return

    addContext(form, {
        payload: { text: content, label },
        stage: { kind: 'text', content, label },
    })
}

function attachDocuments(form, documents) {
    if (!documents.length || contextIsFull(form)) return

    addContext(form, {
        payload: { documents: documents.map((doc) => doc.ref) },
        stage: { kind: 'documents', documents },
    })
}

/** First line of a paste, so four pasted blocks are tellable apart. */
function labelForPaste(text) {
    const first = text.trim().split('\n')[0].trim()
    if (!first) return 'Texto colado'
    return first.length > 60 ? `${first.slice(0, 57)}…` : first
}

/* ---------------------------------- events -------------------------------- */

document.addEventListener('click', (e) => {
    // 📎 menu → open the file dialog. (The picker item opens the side panel on
    // its own via data-ak-panel-open, with no code in between.)
    const fileBtn = e.target.closest('[data-ak-fs-open-file]')
    if (fileBtn) {
        const form = fileBtn.closest('form')
        closeAttachMenu(form)
        if (!contextIsFull(form)) form?.querySelector('[data-ak-fs-file-input]')?.click()
        return
    }

    // Closes the 📎 menu behind the picker. Deliberately does NOT return or
    // preventDefault: side-panel.js is listening for this same click, and it is
    // what actually opens the panel.
    const pickerBtn = e.target.closest('[data-ak-fs-open-picker]')
    if (pickerBtn) closeAttachMenu(pickerBtn.closest('form'))

    // Remove a persisted attachment from the conversation's context.
    const detach = e.target.closest('[data-ak-fs-detach]')
    if (detach) {
        removeAttachment(detach)
        return
    }

    // Remove a staged (not yet persisted) item.
    const unstage = e.target.closest('[data-ak-fs-unstage]')
    if (unstage) {
        const form = unstage.closest('form')
        const [kind, index] = unstage.dataset.akFsUnstage.split(':')
        const state = stagedOf(form)
        const bucket = kind === 'file' ? 'files' : kind === 'text' ? 'texts' : 'documents'
        state[bucket].splice(Number(index), 1)
        renderStaged(form)
        syncStagedFiles(form)
        return
    }

    // "adicionar ao contexto" — from a suggestion row or an assistant reply.
    const suggestion = e.target.closest('[data-ak-fs-suggest-add]')
    if (suggestion) {
        const form = document.querySelector('[data-ak-fs-composer]')
        const { ref, label } = JSON.parse(suggestion.dataset.akFsSuggestAdd)
        attachDocuments(form, [{ ref, label }])
        suggestion.remove()
        return
    }

    // Picker: "Marcar visíveis" — only the rows the current filter left showing,
    // never the whole group. A GitBook-imported solution has hundreds of pages,
    // and checking all of them would blow both the attachment cap and the
    // context limit in one click.
    const visibleBtn = e.target.closest('[data-ak-fs-picker-visible]')
    if (visibleBtn) {
        e.preventDefault()
        const group = visibleBtn.closest('[data-ak-fs-picker-group]')
        group.open = true
        group.querySelectorAll('[data-ak-fs-picker-row]:not(.hidden) [data-ak-fs-picker-item]:not(:disabled)')
            .forEach((box) => { box.checked = true })
        syncPickerFooter(group.closest('[data-ak-fs-picker-panel]'))
        return
    }

    const apply = e.target.closest('[data-ak-fs-picker-apply]')
    if (apply) {
        const panel = apply.closest('[data-ak-fs-picker-panel]')
        const form = document.querySelector('[data-ak-fs-composer]')
        const documents = Array.from(panel.querySelectorAll('[data-ak-fs-picker-item]:checked:not(:disabled)'))
            .map((box) => ({ ref: box.value, label: box.dataset.pickerLabel }))

        attachDocuments(form, documents)
        document.querySelector('[data-ak-panel-close]')?.click()
    }
})

async function removeAttachment(trigger) {
    // A real DELETE, not a POST with `_method` spoofing: the route is registered
    // as DELETE and ajax.js already carries the CSRF token in a header, so
    // there is nothing a body would be needed for.
    try {
        const response = await ajaxModule.init('DELETE', trigger.dataset.akFsDetach)
        updateSlots(await response.json())
    } catch {
        Toast.show('Não foi possível remover do contexto.', 'warning')
    }
}

document.addEventListener('change', (e) => {
    const fileInput = e.target.closest('[data-ak-fs-file-input]')
    if (fileInput) {
        const form = fileInput.closest('form')

        attachFiles(form, fileInput.files)

        // With a conversation the files upload right away, so the input has
        // done its job and must be emptied — otherwise the next message would
        // resend the same bytes to an endpoint that ignores them. With no
        // conversation the input IS the staging area, and syncStagedFiles() owns
        // its contents, so clearing it here would throw the selection away.
        //
        // Safe to clear while attachFiles() is still uploading: it copies the
        // FileList before its first `await`, and the File objects it kept stay
        // readable after the input that produced them is emptied.
        if (configOf(form).chatId) fileInput.value = ''
        return
    }

    const pickerItem = e.target.closest('[data-ak-fs-picker-item]')
    if (pickerItem) syncPickerFooter(pickerItem.closest('[data-ak-fs-picker-panel]'))
})

document.addEventListener('input', (e) => {
    const search = e.target.closest('[data-ak-fs-picker-search]')
    if (search) {
        filterPicker(search.closest('[data-ak-fs-picker-panel]'), search.value)
        return
    }

    const input = e.target.closest('[data-ak-fs-input]')
    if (input) {
        autoGrow(input)
        scheduleSuggestions(input.closest('form'), input.value)
    }
})

/**
 * A long paste becomes a text attachment instead of a wall of text in the
 * composer — the Claude client's behavior, and the reason the standalone
 * "flowSpec de referência" editor could go away: a pasted pipeline is just a
 * paste, and the server recognizes it for what it is (AttachFlowspecText).
 *
 * A pasted IMAGE or file goes through the same door as one picked from disk.
 */
document.addEventListener('paste', (e) => {
    const input = e.target.closest('[data-ak-fs-input]')
    if (!input) return

    const form = input.closest('form')
    const files = Array.from(e.clipboardData?.files ?? [])

    if (files.length) {
        e.preventDefault()
        attachFiles(form, files)
        return
    }

    const text = e.clipboardData?.getData('text/plain') ?? ''
    const threshold = configOf(form).pasteThreshold || 2000

    if (text.length <= threshold) return

    e.preventDefault()
    attachText(form, text, labelForPaste(text))
    Toast.show('Texto longo anexado ao contexto da conversa.')
})

// Enter sends (Shift+Enter = newline), like ChatGPT/Claude.
document.addEventListener('keydown', (e) => {
    const input = e.target.closest('[data-ak-fs-input]')
    if (!input || e.key !== 'Enter' || e.shiftKey) return

    e.preventDefault()
    input.closest('form')?.querySelector('[data-ak-fs-send]')?.click()
})

/**
 * Reset after a message is sent (server-dispatched — see
 * FlowspecMessageController). Only the textarea: the conversation's context
 * deliberately survives the send, which is the whole point of it living on the
 * chat instead of on the message.
 */
document.addEventListener('ak:flowspec-composer-reset', (e) => {
    const form = document.getElementById(e.detail?.formId)
    if (!form) return

    const message = form.querySelector('[data-ak-fs-input]')
    if (message) {
        message.value = ''
        autoGrow(message)
    }

    form.querySelector('[data-ak-fs-suggestions]')?.replaceChildren()
    closeAttachMenu(form)
})

/* --------------------------------- suggestions ---------------------------- */

function scheduleSuggestions(form, text) {
    const config = configOf(form)
    if (!config.suggestUrl) return

    clearTimeout(suggestTimers.get(form))

    const target = form.querySelector('[data-ak-fs-suggestions]')
    if (text.trim().length < SUGGEST_MIN_LENGTH) {
        target?.replaceChildren()
        return
    }

    suggestTimers.set(form, setTimeout(() => fetchSuggestions(form, text), SUGGEST_DEBOUNCE_MS))
}

async function fetchSuggestions(form, text) {
    const config = configOf(form)
    const seq = (suggestSeq.get(form) ?? 0) + 1
    suggestSeq.set(form, seq)

    const url = `${config.suggestUrl}${config.suggestUrl.includes('?') ? '&' : '?'}q=${encodeURIComponent(text)}`

    try {
        const response = await ajaxModule.init('GET', url)
        const data = await response.json()
        if (seq !== suggestSeq.get(form)) return // a later keystroke already won
        renderSuggestions(form, data.suggestions ?? [])
    } catch {
        /* a suggestion nobody asked for isn't worth an error message */
    }
}

function renderSuggestions(form, suggestions) {
    const target = form.querySelector('[data-ak-fs-suggestions]')
    if (!target) return

    if (!suggestions.length) {
        target.replaceChildren()
        return
    }

    const buttons = suggestions.map((doc) => {
        const payload = escapeHtml(JSON.stringify({ ref: `${doc.type}:${doc.id}`, label: doc.label }))
        return `<button type="button" data-ak-fs-suggest-add="${payload}"
            class="inline-flex items-center gap-1 rounded-full border border-accent-line bg-accent-soft px-2.5 py-1 text-xs font-medium text-ink hover:bg-accent-line">
            + ${escapeHtml(doc.label)}
        </button>`
    })

    target.innerHTML = `<div class="flex flex-wrap items-center gap-1.5 pb-1">
        <span class="text-[11px] text-faint">Anexar documentação?</span>
        ${buttons.join('')}
    </div>`
}

/* ----------------------------------- picker ------------------------------- */

/**
 * Client-side filtering, with matching groups opened automatically. The list is
 * rendered whole (see the panel's own note) precisely so narrowing it costs
 * nothing but a loop.
 */
function filterPicker(panel, term) {
    if (!panel) return

    const needle = fold(term.trim())
    let anyVisible = false

    panel.querySelectorAll('[data-ak-fs-picker-group]').forEach((group) => {
        let visible = 0

        group.querySelectorAll('[data-ak-fs-picker-row]').forEach((row) => {
            const match = needle === '' || (row.dataset.search ?? '').includes(needle)
            row.classList.toggle('hidden', !match)
            if (match) visible++
        })

        group.classList.toggle('hidden', visible === 0)
        group.querySelector('[data-ak-fs-picker-group-count]').textContent = visible
        // Only a real search opens groups: with an empty box they stay as the
        // user left them, so clearing the filter doesn't expand 600 rows.
        if (needle !== '') group.open = visible > 0
        if (visible > 0) anyVisible = true
    })

    panel.querySelector('[data-ak-fs-picker-empty]')?.classList.toggle('hidden', anyVisible)
}

function syncPickerFooter(panel) {
    if (!panel) return

    const count = panel.querySelectorAll('[data-ak-fs-picker-item]:checked:not(:disabled)').length
    const label = panel.querySelector('[data-ak-fs-picker-count]')
    const apply = panel.querySelector('[data-ak-fs-picker-apply]')

    if (label) {
        label.textContent = count === 0
            ? 'Nenhum selecionado'
            : `${count} ${count === 1 ? 'selecionado' : 'selecionados'}`
    }
    if (apply) apply.disabled = count === 0
}

/* ------------------------------------ init -------------------------------- */

export function init() {
    document.querySelectorAll('[data-ak-fs-input]').forEach(autoGrow)
    document.querySelectorAll('[data-ak-fs-picker-panel]').forEach(syncPickerFooter)

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
