import * as ajaxModule from './ajax.js'
import {updateSlots} from './ajax-slot.js'
import {setButtonLoading} from './button-loading.js'
import {renderMarkdownDiff} from './docs-diff.js'
import {setEditorLocked} from './docs-editor.js'

/**
 * Documentation Assistant — a conversation about one page/diagram's
 * documentation (built against flowspec-chat.js's polling/composer pattern,
 * reusing docs-ai.js's editor-lock and diff-review mechanics).
 *
 * SEND: collects the message, the checked context documents and the editor's
 * CURRENT Markdown (window.__akDocsGetMarkdown, since an async snapshot can't
 * go through the generic data-ak-ajax form submission), POSTs, then swaps the
 * thread slot. While the assistant is replying the editor is LOCKED (overlay +
 * input blocking + setEditorLocked(), which also holds back docs-editor.js's
 * autosave) — init() re-derives the lock/poll state from whatever the thread
 * slot renders (a [data-ak-docs-chat-poll] marker), so it's correct after a
 * send, after a poll tick, and on a fresh page load with the thread already
 * open, all from the same place.
 *
 * DRAFT REVIEW: an assistant message that proposes a content change renders a
 * "Ver alterações" button; clicking it diffs the CURRENT editor content
 * (fetched live, not a stale snapshot) against that message's draft
 * (docs-diff.js) in a modal. "Aplicar" loads it into the editor
 * (__akDocsSetMarkdown) — the user still has to Salvar; nothing is written to
 * the page from here. Marking the message applied is a fire-and-forget
 * bookkeeping call, mirroring docs-ai.js's "consume".
 *
 * RESUME: if the user closes the panel or navigates away while a reply is
 * generating, a server-rendered marker ([data-ak-docs-chat-resume], present
 * only when this user has a chat still awaiting a reply for this
 * page/diagram) locks the editor and polls on load, independently of
 * whether the panel/thread is even in the DOM.
 */

const POLL_INTERVAL = 2500
// ~12.5min at 2.5s/tick — comfortably above DocumentationChat::REPLY_STALL_SECONDS
// (660s, server-side), same margin flowspec-chat.js uses for the same reason:
// by then the server already considers the turn resolved or dead.
const MAX_POLL_ATTEMPTS = 300

let panelTimer = null
let panelAttempts = 0
let resumeTimer = null
let resumeAttempts = 0
let resumeActive = false
let resumeChecked = false
let editorLocked = false

let pendingDraft = null
let pendingApplyUrl = null
let pendingButton = null

/* ------------------------------------------------------------------ */
/*  Sending a message                                                  */
/* ------------------------------------------------------------------ */

document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-ak-docs-chat-send]')
    if (!btn) return
    e.preventDefault()
    send(btn)
})

// Enter sends (Shift+Enter = newline), like flowspec's composer.
document.addEventListener('keydown', (e) => {
    const input = e.target.closest('[data-ak-docs-chat-input]')
    if (!input || e.key !== 'Enter' || e.shiftKey) return
    e.preventDefault()
    input.closest('form')?.querySelector('[data-ak-docs-chat-send]')?.click()
})

document.addEventListener('input', (e) => {
    const input = e.target.closest('[data-ak-docs-chat-input]')
    if (input) autoGrow(input)
})

async function send(btn) {
    const form = btn.closest('form') || document
    const input = form.querySelector('[data-ak-docs-chat-input]')
    const message = (input?.value || '').trim()
    if (!message) {
        Toast.show('Escreva uma mensagem para o especialista.', 'warning')
        return
    }

    const mediaIds = Array.from(document.querySelectorAll('[data-ak-context-doc]:checked')).map((c) => c.value)
    const existing = window.__akDocsGetMarkdown ? await window.__akDocsGetMarkdown() : ''

    const formData = new FormData()
    formData.append('message', message)
    formData.append('existing_content', existing)
    mediaIds.forEach((id) => formData.append('media_ids[]', id))

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
                // best-effort — the message still sent successfully
            }
        }
    } catch (error) {
        let message2 = 'Não consegui enviar a mensagem.'
        if (error.response) {
            try {
                message2 = (await error.response.json()).message ?? message2
            } catch (_) {
                // keep the default message
            }
        }
        Toast.open({content: message2, title: 'Atenção', type: 'warning'})
    } finally {
        setButtonLoading(btn, false)
    }
}

// Clears the composer after a successful send (server-dispatched — see
// AssistsDocumentation::sendChatMessage()). The composer isn't inside the
// swapped thread slot, so it isn't reset by updateSlots() on its own.
document.addEventListener('ak:docs-chat-composer-reset', (e) => {
    const form = document.getElementById(e.detail?.formId)
    if (!form) return
    const input = form.querySelector('[data-ak-docs-chat-input]')
    if (input) {
        input.value = ''
        autoGrow(input)
    }
})

function autoGrow(el) {
    el.style.height = 'auto'
    el.style.height = `${el.scrollHeight}px`
}

/* ------------------------------------------------------------------ */
/*  Context document upload (verbatim behavior from the retired        */
/*  one-shot flow — auto-uploads on selection, no separate "Anexar").   */
/* ------------------------------------------------------------------ */

document.addEventListener('change', (e) => {
    const input = e.target.closest('[data-ak-context-upload]')
    if (!input || !input.files?.length) return
    uploadContextDoc(input)
})

async function uploadContextDoc(input) {
    const action = input.dataset.action
    if (!action) return

    const formData = new FormData()
    formData.append('file', input.files[0])

    setContextUploading(true)
    try {
        const response = await ajaxModule.init('POST', action, formData)
        const data = await response.json()
        updateSlots(data) // refreshes the context-documents list (new doc, checked)
        Toast.open({content: data.message, title: data.title || 'Alerta', type: data.type || 'success'})
    } catch (error) {
        let message = 'Não consegui anexar o documento.'
        if (error.response) {
            try {
                message = (await error.response.json()).message ?? message
            } catch (_) {
                // keep the default message
            }
        }
        Toast.open({content: message, title: 'Atenção', type: 'warning'})
    } finally {
        setContextUploading(false)
        input.value = '' // let the same file be re-selected (e.g. after a failure)
    }
}

function setContextUploading(on) {
    const status = document.querySelector('[data-ak-context-uploading]')
    if (status) {
        status.classList.toggle('hidden', !on)
        status.classList.toggle('inline-flex', on)
    }
    document.querySelectorAll('[data-ak-context-upload]').forEach((input) => {
        input.disabled = on
        input.classList.toggle('opacity-50', on)
        input.classList.toggle('pointer-events-none', on)
    })
}

/* ------------------------------------------------------------------ */
/*  Draft review — diffs the LIVE editor content against a message's   */
/*  draft, on demand (no stale "before" snapshot to reconcile).        */
/* ------------------------------------------------------------------ */

document.addEventListener('click', (e) => {
    const viewBtn = e.target.closest('[data-ak-docs-chat-view-draft]')
    if (viewBtn) {
        e.preventDefault()
        openReview(viewBtn)
        return
    }

    if (e.target.closest('[data-ak-docs-chat-review-close]')) {
        e.preventDefault()
        window.Modal?.close('main-modal')
        return
    }

    if (e.target.closest('[data-ak-docs-chat-apply]')) {
        e.preventDefault()
        applyPendingDraft()
    }
})

async function openReview(trigger) {
    const id = trigger.dataset.akDocsChatViewDraft
    const source = document.querySelector(`[data-ak-docs-chat-draft="${id}"]`)
    if (!source) return

    const after = source.value
    const before = window.__akDocsGetMarkdown ? await window.__akDocsGetMarkdown() : ''

    const template = document.querySelector('[data-ak-docs-chat-review-template]')
    const modal = document.getElementById('main-modal')
    if (!template || !modal || !window.Modal) return

    pendingDraft = after
    pendingApplyUrl = trigger.dataset.applyUrl || null
    pendingButton = trigger

    const node = template.content.cloneNode(true)
    const {hasChanges, html} = renderMarkdownDiff(before, after)

    const body = node.querySelector('[data-ak-docs-chat-review-body]')
    if (hasChanges) {
        body.innerHTML =
            '<div class="overflow-hidden rounded-field border border-line font-mono text-xs leading-relaxed">' + html + '</div>'
    } else {
        body.innerHTML =
            '<div class="rounded-field border border-dashed border-line px-4 py-10 text-center text-sm text-muted">' +
            'Não há diferenças em relação ao conteúdo atual do editor.</div>'
        node.querySelector('[data-ak-docs-chat-apply]')?.classList.add('hidden')
    }

    modal.querySelector('[data-content]').replaceChildren(node)
    modal.querySelector('[data-loading]')?.classList.add('hidden')
    window.Modal.open('main-modal', false)
}

async function applyPendingDraft() {
    const draft = pendingDraft
    if (draft == null) return

    if (!window.__akDocsSetMarkdown) {
        Toast.show('O editor ainda está carregando — tente aplicar em instantes.', 'warning')
        return
    }

    try {
        await window.__akDocsSetMarkdown(draft)
    } catch (_) {
        Toast.open({content: 'Não consegui aplicar as alterações no editor.', title: 'Atenção', type: 'error'})
        return
    }

    // Bookkeeping only, fire-and-forget — the draft already reached the
    // editor above regardless of whether this call succeeds.
    if (pendingApplyUrl) ajaxModule.init('POST', pendingApplyUrl).catch(() => {})

    if (pendingButton) {
        pendingButton.textContent = 'Aplicado'
        pendingButton.disabled = true
        pendingButton.classList.add('opacity-60', 'pointer-events-none')
    }

    pendingDraft = null
    pendingApplyUrl = null
    pendingButton = null
    window.Modal?.close('main-modal')
    Toast.show('Alterações aplicadas — revise e salve.')
}

/* ------------------------------------------------------------------ */
/*  Editor lock while a reply is generating                            */
/* ------------------------------------------------------------------ */

;['keydown', 'beforeinput', 'paste', 'cut', 'drop', 'dragstart'].forEach((type) => {
    document.addEventListener(
        type,
        (e) => {
            if (!editorLocked) return
            if (!e.target.closest?.('[data-ak-docs-editor]')) return
            e.preventDefault()
            e.stopPropagation()
        },
        true,
    )
})

function lockEditor() {
    const holder = document.querySelector('[data-ak-docs-editor]')
    if (!holder || editorLocked) return
    editorLocked = true

    if (holder.contains(document.activeElement)) document.activeElement.blur()

    if (!holder.style.position) holder.style.position = 'relative'
    if (!holder.querySelector('[data-ak-docs-chat-lock]')) {
        const overlay = document.createElement('div')
        overlay.setAttribute('data-ak-docs-chat-lock', '')
        overlay.className = 'absolute inset-0 z-20 flex items-start justify-center rounded-field bg-surface/70 cursor-not-allowed'
        overlay.innerHTML =
            '<span class="mt-8 inline-flex items-center gap-2 rounded-full border border-accent-line bg-accent-soft px-3.5 py-1.5 text-xs font-medium text-accent shadow-sm">' +
            '<span class="size-3 animate-spin rounded-full border-2 border-accent border-t-transparent"></span>' +
            'Gerando com o especialista… edição bloqueada</span>'
        holder.appendChild(overlay)
    }

    setEditorLocked(true)
    document.querySelector('[data-ak-docs-chat-trigger]')?.setAttribute('disabled', 'disabled')
}

function unlockEditor() {
    editorLocked = false
    document.querySelector('[data-ak-docs-editor] [data-ak-docs-chat-lock]')?.remove()
    setEditorLocked(false)
    document.querySelector('[data-ak-docs-chat-trigger]')?.removeAttribute('disabled')
}

function showStatus(on) {
    const el = document.querySelector('[data-ak-docs-chat-status]')
    if (!el) return
    el.classList.toggle('hidden', !on)
    el.classList.toggle('inline-flex', on)
}

/* ------------------------------------------------------------------ */
/*  Polling while the thread's own marker is present (panel open)      */
/* ------------------------------------------------------------------ */

function stopPanelPoll() {
    if (panelTimer) {
        clearInterval(panelTimer)
        panelTimer = null
    }
}

async function pollPanel(url) {
    const marker = document.querySelector('[data-ak-docs-chat-poll]')
    if (!marker) {
        stopPanelPoll()
        return
    }

    panelAttempts += 1
    if (panelAttempts > MAX_POLL_ATTEMPTS) {
        stopPanelPoll()
        unlockEditor()
        showStatus(false)
        Toast.show('A resposta está demorando mais que o esperado — atualize a página para conferir.', 'warning')
        return
    }

    try {
        const response = await ajaxModule.init('GET', url)
        const data = await response.json()
        if (!data.pending) {
            stopPanelPoll()
            updateSlots(data) // swaps the thread slot; init() re-derives lock/poll state
        }
    } catch (_) {
        // transient failure — the next tick retries, up to MAX_POLL_ATTEMPTS
    }
}

/* ------------------------------------------------------------------ */
/*  Resume — a chat still awaiting a reply for this target, from a     */
/*  page load where the panel isn't even open (see docs-chat-resume).  */
/* ------------------------------------------------------------------ */

function resumeIfPending() {
    if (resumeChecked) return
    resumeChecked = true

    const marker = document.querySelector('[data-ak-docs-chat-resume]')
    const url = marker?.dataset.statusUrl
    if (!url) return

    resumeActive = true
    lockEditor()
    showStatus(true)
    resumeAttempts = 0
    pollResume(url)
    resumeTimer = setInterval(() => pollResume(url), POLL_INTERVAL)
}

function stopResumePoll() {
    if (resumeTimer) {
        clearInterval(resumeTimer)
        resumeTimer = null
    }
    resumeActive = false
}

async function pollResume(url) {
    resumeAttempts += 1
    if (resumeAttempts > MAX_POLL_ATTEMPTS) {
        stopResumePoll()
        if (!document.querySelector('[data-ak-docs-chat-poll]')) {
            unlockEditor()
            showStatus(false)
        }
        return
    }

    try {
        const response = await ajaxModule.init('GET', url)
        const data = await response.json()
        if (!data.pending) {
            stopResumePoll()
            // The panel may have been opened meanwhile and now manages its own
            // awaiting marker — don't unlock out from under it.
            if (!document.querySelector('[data-ak-docs-chat-poll]')) {
                unlockEditor()
                showStatus(false)
            }
        }
    } catch (_) {
        // transient failure — the next tick retries, up to MAX_POLL_ATTEMPTS
    }
}

/* ------------------------------------------------------------------ */

// Called on load and after every slot swap (initAllModules()). Derives the
// lock/poll state from whatever's currently in the DOM, so it's correct
// whether we just sent a message, just got a poll tick, or just loaded the
// page with the panel already open from a previous session.
export function init() {
    const marker = document.querySelector('[data-ak-docs-chat-poll]')

    if (marker) {
        lockEditor()
        showStatus(true)
        if (!panelTimer) {
            panelAttempts = 0
            panelTimer = setInterval(() => pollPanel(marker.dataset.akDocsChatPoll), POLL_INTERVAL)
        }
    } else {
        stopPanelPoll()
        // Don't unlock out from under a page-level resume poll still in charge.
        if (!resumeActive) {
            unlockEditor()
            showStatus(false)
        }
    }

    resumeIfPending()

    const scroll = document.querySelector('[data-ak-docs-chat-scroll]')
    if (scroll) scroll.scrollTop = scroll.scrollHeight

    document.querySelectorAll('[data-ak-docs-chat-input]').forEach(autoGrow)
}
