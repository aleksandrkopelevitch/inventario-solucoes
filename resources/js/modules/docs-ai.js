import * as ajaxModule from './ajax.js'
import {updateSlots} from './ajax-slot.js'
import {setButtonLoading} from './button-loading.js'
import {renderMarkdownDiff} from './docs-diff.js'
import {setEditorLocked} from './docs-editor.js'

/**
 * "AI Assist" for documentation.
 *
 * On "Gerar rascunho" (side panel), collects the prompt, the checked context
 * documents and the editor's CURRENT Markdown (window.__akDocsGetMarkdown),
 * fires the async generation job and closes the panel. While the job runs the
 * editor is LOCKED (overlay + input blocking + `setEditorLocked()`, which is
 * what also holds back docs-editor.js's autosave) and the status is polled;
 * once done, a REVIEW MODAL shows a diff between the current content and the
 * draft (docs-diff.js) — nothing touches the editor until the user clicks
 * "Aplicar rascunho", so the previous content is never overwritten unseen.
 * "Aplicar" and "Descartar" are the modal's intended exits (Esc is suppressed,
 * though the browser's close watcher still honours a second one — see
 * `openReview()`); whatever closes it, the draft is never lost silently.
 *
 * RESUME: the job finishes on the server regardless of the browser, so if the
 * user navigates away and comes back, init() reads a server-rendered marker
 * ([data-ak-docs-ai-resume]) and picks the flow back up — re-locking + polling
 * a still-pending job, or opening the review for a finished one. A finished
 * generation is marked "consumed" (server) once applied/discarded/acknowledged,
 * so it doesn't resurface on the next load. Consuming is therefore always the
 * LAST step of applying, never the first: a generation consumed before the
 * draft actually reached the editor would be lost with no way back.
 *
 * The lock can never strand the editor: polling gives up after
 * MAX_POLL_ATTEMPTS (and unlocks), the server reaps stale jobs to `failed`
 * (unlocks on the next poll), and a fresh load reaps stale before deciding
 * whether to resume at all.
 */

const POLL_INTERVAL = 2500
const MAX_POLL_ATTEMPTS = 240 // ~10min at 2.5s/tick
let timer = null
let attempts = 0
// Editor's Markdown at submit time — the fallback "before" side of the review
// diff when the server-provided existing_content isn't available.
let submittedSnapshot = ''
// Generated Markdown awaiting the user's decision in the review modal. Applied
// to the editor only on "Aplicar rascunho"; cleared on apply/discard.
let pendingDraft = null
// URL that marks the current generation "consumed" (resolved), so it won't
// resume on reload. Set on generate and on resume; called on apply/discard/fail.
let pendingConsumeUrl = null
let editorLocked = false
let resumeChecked = false

document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-ak-docs-ai-generate]')
    if (!btn) return
    e.preventDefault()
    generate(btn)
})

// Review-modal decisions (buttons live in the cloned template — see
// documentation/edit.blade.php). Applying is the only path that overwrites the
// editor and, in turn, arms its autosave.
document.addEventListener('click', (e) => {
    if (e.target.closest('[data-ak-docs-ai-apply]')) {
        e.preventDefault()
        applyPendingDraft()
    } else if (e.target.closest('[data-ak-docs-ai-discard]')) {
        e.preventDefault()
        pendingDraft = null
        consumeGeneration()
        window.Modal?.close('main-modal')
        Toast.show('Rascunho descartado — seu conteúdo foi mantido.')
    }
})

// While the editor is locked (a generation is running), block every editing
// gesture inside it. The overlay stops pointer input; these capture-phase
// listeners stop keyboard/paste/drag for the case where a block was already
// focused when the lock went up. Installed once, gated by `editorLocked`.
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

// Auto-upload a chosen context document — no separate "Anexar" click. The old
// flow (pick file → click Anexar → generate) was an easy trap: users picked a
// file, wrote the prompt and generated without ever clicking Anexar, so nothing
// was uploaded and the model received no documents. Selecting a file uploads it
// straight away, and it lands in the list (checked) ready for the generation.
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

async function generate(btn) {
    const prompt = (document.querySelector('[data-ak-docs-ai-prompt]')?.value || '').trim()
    if (!prompt) {
        Toast.show('Escreva o que o especialista deve fazer.', 'warning')
        return
    }

    const mediaIds = Array.from(document.querySelectorAll('[data-ak-context-doc]:checked')).map((c) => c.value)
    const existing = window.__akDocsGetMarkdown ? await window.__akDocsGetMarkdown() : ''
    submittedSnapshot = existing

    const formData = new FormData()
    formData.append('prompt', prompt)
    formData.append('existing_content', existing)
    mediaIds.forEach((id) => formData.append('media_ids[]', id))

    setButtonLoading(btn, true)
    try {
        const response = await ajaxModule.init('POST', btn.dataset.action, formData)
        const data = await response.json()

        // This generation supersedes whatever was still unresolved (a review the
        // user walked away from, say). Resolve the old one now — only reachable
        // here, since the server 409s while one is still pending — or it stays
        // unconsumed forever and resurfaces on some later page load, long after
        // it stopped being relevant. Consumed only after the new generation was
        // actually created, so a failed POST doesn't throw the old draft away.
        consumeGeneration()
        pendingDraft = null

        pendingConsumeUrl = data.consumeUrl || null
        closePanel()
        lockEditor()
        showStatus(true)
        startPolling(data.pollUrl)
    } catch (error) {
        let message = 'Não consegui iniciar a geração.'
        if (error.response) {
            try {
                message = (await error.response.json()).message ?? message
            } catch (_) {
                // keep the default message
            }
        }
        Toast.open({content: message, title: 'Atenção', type: 'warning'})
    } finally {
        setButtonLoading(btn, false)
    }
}

function startPolling(pollUrl) {
    stopPolling()
    attempts = 0
    poll(pollUrl) // immediate first check — a finished draft shows without a 2.5s wait
    timer = setInterval(() => poll(pollUrl), POLL_INTERVAL)
}

function stopPolling() {
    if (timer) {
        clearInterval(timer)
        timer = null
    }
}

async function poll(pollUrl) {
    attempts += 1

    if (attempts > MAX_POLL_ATTEMPTS) {
        // Give up, but do NOT consume: the job may still finish server-side, and
        // the next page load will resume it (or reap it if it's gone stale).
        stopPolling()
        unlockEditor()
        showStatus(false)
        Toast.show('A geração está demorando mais que o esperado — tente novamente.', 'warning')
        return
    }

    try {
        const response = await ajaxModule.init('GET', pollUrl)
        const data = await response.json()

        if (data.pending) return

        stopPolling()
        showStatus(false)
        unlockEditor()

        // Resolved (Aplicar/Descartar) from another tab/session already — this
        // poller was still running against the same generation (e.g. two tabs
        // both resumed the same unconsumed marker). Nothing to review anymore;
        // don't reopen a draft the user already acted on elsewhere.
        if (data.consumed) return

        if (data.failed) {
            consumeGeneration()
            Toast.open({content: data.error || 'Falha ao gerar a documentação.', title: 'Atenção', type: 'error'})
            return
        }

        // The draft was generated FROM `existing_content` (authoritative "before").
        // On resume, the submit-time snapshot is gone, so we rely on the server's
        // copy; in the live flow they're identical. If the live editor now differs
        // from that "before", the user edited during generation — the modal flags it.
        const before = (data.existing_content != null ? data.existing_content : submittedSnapshot) || ''
        const current = window.__akDocsGetMarkdown ? await window.__akDocsGetMarkdown() : before
        const concurrentEdit = current.trim() !== before.trim()
        openReview(before, data.result || '', concurrentEdit)
    } catch (error) {
        // transient failure — the next tick retries, up to MAX_POLL_ATTEMPTS
    }
}

// Opens the review modal (reuses #main-modal from the layout) with a diff
// between the current content and the generated draft. Nothing touches the
// editor here — that happens only on "Aplicar rascunho". Falls back to
// applying directly (with a confirm on concurrent edits) if the modal shell
// isn't present for some reason.
function openReview(before, after, concurrentEdit) {
    pendingDraft = after

    const template = document.querySelector('[data-ak-docs-ai-review-template]')
    const modal = document.getElementById('main-modal')
    if (!template || !modal || !window.Modal) {
        applyDirect(after, concurrentEdit)
        return
    }

    const node = template.content.cloneNode(true)
    const {hasChanges, html} = renderMarkdownDiff(before, after)

    const body = node.querySelector('[data-ak-docs-ai-review-body]')
    if (hasChanges) {
        body.innerHTML =
            '<div class="overflow-hidden rounded-field border border-line font-mono text-xs leading-relaxed">' + html + '</div>'
    } else {
        body.innerHTML =
            '<div class="rounded-field border border-dashed border-line px-4 py-10 text-center text-sm text-muted">' +
            'O especialista não sugeriu alterações em relação ao conteúdo atual.</div>'
        node.querySelector('[data-ak-docs-ai-apply]')?.classList.add('hidden')
    }

    if (concurrentEdit) node.querySelector('[data-ak-docs-ai-review-warning]')?.classList.remove('hidden')

    modal.querySelector('[data-content]').replaceChildren(node)
    modal.querySelector('[data-loading]')?.classList.add('hidden')
    // closeOnEsc = false makes the first Esc a no-op (modal.js's module-level
    // `cancel` listener calls preventDefault), because Esc wipes [data-content] and
    // there's no way to reopen a review in the same page — "Aplicar" and
    // "Descartar" should be the two exits.
    //
    // It is NOT a guarantee, and don't write code that assumes it is: verified
    // in headless Chrome (2026-07-25) that REPEATED Esc presses close the
    // dialog anyway — it took a 3rd press there, and the exact count is
    // browser-internal, so never rely on a specific number. That's the
    // close-watcher anti-trap rule — repeated close requests with no user
    // activation in between stop honouring preventDefault, by design, so a
    // page can't imprison the user. Same for browser chrome.
    window.Modal.open('main-modal', false)
    // Hence this is the real guarantee, not a corner case: whenever the dialog
    // closes without going through either button, the draft must not vanish
    // silently. Both buttons null pendingDraft before the dialog actually
    // closes (Modal.close animates for 200ms), so a draft still pending here
    // means the modal went away on its own.
    modal.addEventListener('close', onReviewModalClosed, {once: true})
}

// Unexpected close (see above): keep the generation UNCONSUMED so the next page
// load resumes the review, and tell the user where the draft went. Verified:
// no `consume` request fires on this path, and the reload reopens the same diff.
function onReviewModalClosed() {
    if (pendingDraft == null) return
    pendingDraft = null
    Toast.show('Rascunho não aplicado — recarregue a página para revisá-lo novamente.', 'warning')
}

// The order here is deliberate: the generation is only marked consumed AFTER
// the draft is really in the editor. Consuming first would make any failure
// unrecoverable — a consumed generation never resumes, so the draft would be
// gone for good with nothing on screen to show for it.
async function applyPendingDraft() {
    const draft = pendingDraft
    if (draft == null) return

    // The editor mounts asynchronously (Editor.js is a dynamic import), and on
    // RESUME this modal opens on page load — it can be a beat ahead of the
    // editor being ready to receive the draft.
    if (!window.__akDocsSetMarkdown) {
        Toast.show('O editor ainda está carregando — tente aplicar em instantes.', 'warning')
        return
    }

    try {
        await window.__akDocsSetMarkdown(draft)
    } catch (_) {
        // Modal stays open (and the generation unconsumed) so the user can retry.
        Toast.open({content: 'Não consegui aplicar o rascunho no editor.', title: 'Atenção', type: 'error'})
        return
    }

    pendingDraft = null
    consumeGeneration()
    window.Modal?.close('main-modal')
    Toast.show('Rascunho aplicado — revise e salve.')
}

// Fallback for when the review modal shell is unavailable: preserves the
// original behavior — a confirm on concurrent edits, then a wholesale replace.
// Same "consume only after it landed" rule as applyPendingDraft().
async function applyDirect(after, concurrentEdit) {
    if (!window.__akDocsSetMarkdown) {
        // Not consumed on purpose: the next page load resumes this generation.
        Toast.show('O editor ainda está carregando — recarregue a página para ver o rascunho.', 'warning')
        return
    }
    if (concurrentEdit && !window.confirm(
        'Você editou o conteúdo enquanto o especialista gerava o rascunho. Substituir suas edições pelo rascunho gerado?',
    )) {
        pendingDraft = null
        consumeGeneration()
        Toast.show('Rascunho descartado — suas edições foram mantidas.')
        return
    }

    try {
        await window.__akDocsSetMarkdown(after)
    } catch (_) {
        Toast.open({content: 'Não consegui aplicar o rascunho no editor.', title: 'Atenção', type: 'error'})
        return
    }

    pendingDraft = null
    consumeGeneration()
    Toast.show('Rascunho gerado — revise e salve.')
}

// Marks the current generation resolved server-side so it won't resume on
// reload. ajax.js attaches the CSRF header, so no body is needed. Idempotent
// and fire-and-forget — a failure here only means it may resume once more.
function consumeGeneration() {
    const url = pendingConsumeUrl
    pendingConsumeUrl = null
    if (url) ajaxModule.init('POST', url).catch(() => {})
}

function closePanel() {
    document.querySelector('[data-ak-panel-close]')?.click()
}

/* ------------------------------------------------------------------ */
/*  Editor lock while a generation runs                               */
/*  Overlay blocks pointer input; the capture listeners above block   */
/*  keyboard/paste/drag. Unlocking is idempotent and happens on every  */
/*  terminal outcome, so the editor is never stranded.                */
/* ------------------------------------------------------------------ */

function lockEditor() {
    const holder = document.querySelector('[data-ak-docs-editor]')
    if (!holder || editorLocked) return
    editorLocked = true

    // Drop focus out of the editor so keystrokes stop landing in a block.
    if (holder.contains(document.activeElement)) document.activeElement.blur()

    if (!holder.style.position) holder.style.position = 'relative'
    if (!holder.querySelector('[data-ak-docs-ai-lock]')) {
        const overlay = document.createElement('div')
        overlay.setAttribute('data-ak-docs-ai-lock', '')
        overlay.className = 'absolute inset-0 z-20 flex items-start justify-center rounded-field bg-surface/70 cursor-not-allowed'
        overlay.innerHTML =
            '<span class="mt-8 inline-flex items-center gap-2 rounded-full border border-accent-line bg-accent-soft px-3.5 py-1.5 text-xs font-medium text-accent shadow-sm">' +
            '<span class="size-3 animate-spin rounded-full border-2 border-accent border-t-transparent"></span>' +
            'Gerando com o especialista… edição bloqueada</span>'
        holder.appendChild(overlay)
    }

    // Saving is docs-editor.js's business — it owns the button AND the pending
    // autosave timer, which would otherwise fire mid-generation and re-enable
    // the button on its way out (setButtonLoading clears `disabled`).
    setEditorLocked(true)
    document.querySelector('[data-ak-docs-ai-trigger]')?.setAttribute('disabled', 'disabled')
}

function unlockEditor() {
    editorLocked = false
    document.querySelector('[data-ak-docs-editor] [data-ak-docs-ai-lock]')?.remove()
    setEditorLocked(false)
    document.querySelector('[data-ak-docs-ai-trigger]')?.removeAttribute('disabled')
}

// Resume an in-flight/finished generation after a page (re)load. The marker is
// server-rendered only when this user has an unresolved generation for the
// target (see AssistsDocumentation::aiResumeFor). Runs once per page.
function resumeIfPending() {
    if (resumeChecked) return
    resumeChecked = true

    const marker = document.querySelector('[data-ak-docs-ai-resume]')
    const pollUrl = marker?.dataset.pollUrl
    if (!pollUrl) return

    pendingConsumeUrl = marker.dataset.consumeUrl || null

    if (marker.dataset.pending === '1') {
        lockEditor()
        showStatus(true)
    }
    startPolling(pollUrl)
}

function showStatus(on) {
    const el = document.querySelector('[data-ak-docs-ai-status]')
    if (!el) return
    el.classList.toggle('hidden', !on)
    el.classList.toggle('inline-flex', on)
}

// Clicks are handled by module-level delegation; init() only kicks off the
// resume check on (re)load. Guarded to run once even if called repeatedly.
export function init() {
    resumeIfPending()
}
