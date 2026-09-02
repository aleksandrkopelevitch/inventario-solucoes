import * as ajaxModule from './ajax.js'
import {updateSlots} from './ajax-slot.js'
import {setButtonLoading} from './button-loading.js'
import {fold} from './fold.js'
import {renderMarkdownDiff} from './docs-diff.js'
import {setEditorLocked} from './docs-editor.js'

/**
 * Documentation Assistant — a conversation about one page/diagram's
 * documentation (built against flowspec-chat.js's polling/composer pattern,
 * reusing docs-ai.js's editor-lock and diff-review mechanics).
 *
 * SEND: collects the message, the checked context documents, the picked context
 * PAGES (see below) and the editor's CURRENT Markdown
 * (window.__akDocsGetMarkdown, since an async snapshot can't go through the
 * generic data-ak-ajax form submission), POSTs, then swaps the thread slot. While the assistant is replying the editor is LOCKED (overlay +
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

/**
 * Other documentation pages picked as context for this conversation —
 * `id -> {title, notebook}`.
 *
 * Client-side state, deliberately, and the difference from the context
 * DOCUMENTS beside it is what makes that right: a document is uploaded and
 * belongs to the caderno from then on (so the server renders its chips), while
 * a page already exists and picking one is a statement about this conversation.
 * It is recorded on the MESSAGE (`context_page_ids`) when one is sent.
 *
 * Kept across sends within a panel session — the pages someone reached for while
 * writing are almost always the same on the next turn, and the chips say so
 * plainly. Reset when a freshly rendered panel arrives (see `init()`).
 */
const contextPages = new Map()
const seenPageContainers = new WeakSet()

let pageCatalogPromise = null
let pageCatalogFetchedAt = 0
let pageCatalogFailed = false

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
    contextPages.forEach((_, id) => formData.append('page_ids[]', id))

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

    try {
        await postContextDoc(action, formData)
    } finally {
        input.value = '' // let the same file be re-selected (e.g. after a failure)
    }
}

/**
 * A long paste becomes a context document instead of a wall of text in the
 * composer — the same gesture the Especialista em Integrações composer has
 * (flowspec-chat.js), and the reason it is here: the material people bring to a
 * documentation conversation is the same material, a pipeline JSON or a spec
 * that has no business being a message body.
 *
 * Where it lands differs, and deliberately: F8 attaches to the CONVERSATION,
 * while a caderno's context documents belong to the NOTEBOOK and are shared by
 * every page in it. So this paste outlives the conversation and is there while
 * documenting the next page — see App\Actions\Documentation\AttachContextText.
 *
 * The text is posted RAW rather than as a synthesized file: the server is what
 * recognizes a pipeline and minifies it, and it names the document.
 */
document.addEventListener('paste', (e) => {
    const input = e.target.closest('[data-ak-docs-chat-input]')
    if (!input) return

    const config = composerConfig(input)
    if (!config.contextStoreUrl) return

    const text = e.clipboardData?.getData('text/plain') ?? ''
    if (text.length <= (config.pasteThreshold || 2000)) return

    e.preventDefault()

    const formData = new FormData()
    formData.append('text', text)

    postContextDoc(config.contextStoreUrl, formData)
})

/** The composer form's own config, parsed once per call — it is a tiny object. */
function composerConfig(input) {
    const form = input.closest('form')
    try {
        return JSON.parse(form?.dataset.akDocsChatComposer ?? '{}')
    } catch (_) {
        return {}
    }
}

/**
 * One POST for both ways a context document arrives (a picked file, a long
 * paste), so the uploading indicator, the slot swap and the error handling
 * cannot drift between them.
 */
async function postContextDoc(action, formData) {
    if (!action) return

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
/*  Context PAGES — other documentation pages handed to the assistant  */
/*  as reference for this conversation.                                */
/* ------------------------------------------------------------------ */

/** Same short-TTL refetch as the link picker's, and for the same reason: a page
 *  created minutes ago has to be offerable without reloading the screen. */
const PAGE_CATALOG_TTL = 20000

function loadPageCatalog(url) {
    if (!url) return Promise.resolve([])

    if (!pageCatalogPromise || Date.now() - pageCatalogFetchedAt > PAGE_CATALOG_TTL) {
        pageCatalogFetchedAt = Date.now()
        pageCatalogFailed = false
        pageCatalogPromise = ajaxModule
            .init('GET', url)
            .then((r) => r.json())
            .then((d) => d.groups || [])
            .catch(() => {
                pageCatalogFailed = true
                // Not cached: a failed request must not be the answer for the
                // next twenty seconds.
                pageCatalogPromise = null
                pageCatalogFetchedAt = 0

                return []
            })
    }

    return pageCatalogPromise
}

document.addEventListener('click', (e) => {
    const add = e.target.closest('[data-ak-context-page-add]')
    if (add) {
        e.preventDefault()
        openPagePicker(add.dataset.action)

        return
    }

    const remove = e.target.closest('[data-ak-context-page-remove]')
    if (remove) {
        e.preventDefault()
        contextPages.delete(Number(remove.dataset.akContextPageRemove))
        renderContextPages()
    }
})

/** The chips, rebuilt from `contextPages` — one place decides what is shown. */
function renderContextPages() {
    const host = document.querySelector('[data-ak-context-pages]')
    const empty = document.querySelector('[data-ak-context-pages-empty]')
    if (!host) return

    host.replaceChildren()
    if (empty) empty.classList.toggle('hidden', contextPages.size > 0)

    contextPages.forEach((page, id) => {
        const chip = document.createElement('div')
        chip.className =
            'inline-flex max-w-full items-center gap-1.5 rounded-full border border-accent-line bg-accent-soft py-1 pl-2.5 pr-1 text-xs shadow-sm'

        const label = document.createElement('span')
        label.className = 'max-w-[9rem] truncate font-medium text-ink'
        label.textContent = page.title
        // The caderno is in the tooltip rather than the chip: two pages named
        // the same is common across cadernos, and the chip has no room to say
        // both without truncating the half that identifies it.
        label.title = page.notebook ? `${page.title} · ${page.notebook}` : page.title
        chip.append(label)

        const remove = document.createElement('button')
        remove.type = 'button'
        remove.dataset.akContextPageRemove = String(id)
        remove.className =
            'shrink-0 cursor-pointer rounded-full px-1 leading-none text-muted transition-colors hover:bg-crit-soft hover:text-crit'
        remove.setAttribute('aria-label', 'Remover página do contexto')
        remove.title = 'Remover do contexto'
        remove.textContent = '\u00d7'
        chip.append(remove)

        host.append(chip)
    })
}

function openPagePicker(url) {
    const modal = document.getElementById('main-modal')
    if (!modal || !window.Modal) {
        Toast.show('Não consegui abrir o seletor de páginas.', 'warning')

        return
    }

    const shell = document.createElement('div')
    shell.className = 'flex max-h-[82vh] flex-col'

    const header = document.createElement('div')
    header.className = 'border-b border-line px-6 py-4'
    header.innerHTML =
        '<h2 class="text-base font-bold text-ink">Páginas de contexto</h2>' +
        '<p class="mt-0.5 text-xs text-muted">Escolha outras páginas da documentação para o especialista ' +
        'ler como referência. Elas não são alteradas — só a página atual é reescrita.</p>'

    const search = document.createElement('input')
    search.type = 'search'
    search.placeholder = 'Filtrar por página ou caderno…'
    search.className =
        'mx-6 mt-4 rounded-field border border-line-2 bg-surface px-3 py-2 text-sm text-ink outline-none focus:border-accent'

    const list = document.createElement('div')
    list.className = 'mt-3 min-h-0 flex-1 overflow-y-auto px-4 pb-4'
    list.textContent = 'Carregando…'

    const footer = document.createElement('div')
    footer.className = 'flex items-center justify-end border-t border-line px-6 py-3'
    const done = document.createElement('button')
    done.type = 'button'
    done.className =
        'cursor-pointer rounded-field bg-btn px-4 py-2 text-sm font-semibold text-white shadow-btn transition-colors hover:bg-btn-hover'
    done.textContent = 'Pronto'
    done.addEventListener('click', () => window.Modal?.close('main-modal'))
    footer.append(done)

    shell.append(header, search, list, footer)
    modal.querySelector('[data-content]').replaceChildren(shell)
    modal.querySelector('[data-loading]')?.classList.add('hidden')
    window.Modal.open('main-modal', true)

    loadPageCatalog(url).then((groups) => {
        list.replaceChildren()

        if (!groups.length) {
            list.className = 'px-6 pb-6 pt-2 text-sm text-muted'
            // An unreachable catalog and an empty one must not read the same.
            list.textContent = pageCatalogFailed
                ? 'Não consegui carregar a lista de páginas. Tente de novo em instantes.'
                : 'Nenhuma página com conteúdo ainda.'

            return
        }

        groups.forEach((group) => list.append(renderPageGroup(group)))
        search.focus()
    })

    search.addEventListener('input', () => {
        const term = fold(search.value.trim())

        list.querySelectorAll('[data-ak-ctx-group]').forEach((group) => {
            const groupHit = !term || fold(group.dataset.akCtxGroup).includes(term)
            let any = false

            group.querySelectorAll('[data-ak-ctx-page]').forEach((row) => {
                const hit = groupHit || fold(row.dataset.akCtxPage).includes(term)
                row.classList.toggle('hidden', !hit)
                any = any || hit
            })

            group.classList.toggle('hidden', !any)
        })
    })
}

/** One caderno: its name, then a toggle row per page with content. */
function renderPageGroup(group) {
    const wrap = document.createElement('div')
    wrap.dataset.akCtxGroup = group.notebook
    wrap.className = 'mb-3'

    const title = document.createElement('div')
    title.className = 'flex items-baseline gap-2 px-2 pb-1 pt-2'
    const name = document.createElement('span')
    name.className = 'truncate text-xs font-bold uppercase tracking-wide text-muted'
    name.textContent = group.notebook
    title.append(name)
    if (group.current) {
        const badge = document.createElement('span')
        badge.className = 'shrink-0 rounded-full bg-accent-soft px-1.5 py-0.5 text-[10px] font-semibold text-accent'
        badge.textContent = 'caderno atual'
        title.append(badge)
    }
    wrap.append(title)

    group.pages.forEach((page) => {
        const row = document.createElement('button')
        row.type = 'button'
        row.dataset.akCtxPage = page.title
        row.className =
            'flex w-full cursor-pointer items-center gap-2 rounded-field px-2 py-1.5 text-left text-sm text-body transition-colors hover:bg-raised hover:text-ink'

        const check = document.createElement('span')
        check.className = 'shrink-0 text-accent'
        const label = document.createElement('span')
        label.className = 'min-w-0 flex-1 truncate'
        label.textContent = page.title

        const paint = () => {
            const on = contextPages.has(page.id)
            check.textContent = on ? '\u2713' : '\u00a0'
            row.classList.toggle('bg-accent-soft/50', on)
            row.classList.toggle('font-semibold', on)
        }

        // A toggle, not an "add": the modal stays open so several pages can be
        // picked in one go, and unpicking one is the same gesture as picking it.
        row.addEventListener('click', () => {
            if (contextPages.has(page.id)) contextPages.delete(page.id)
            else contextPages.set(page.id, {title: page.title, notebook: group.notebook})
            paint()
            renderContextPages()
        })

        row.append(check, label)
        paint()
        wrap.append(row)
    })

    return wrap
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

    // A panel that was just rendered by the server is a new conversation
    // session as far as the picked pages go — the container is a brand new node,
    // which is exactly what distinguishes "the panel reopened" from "the thread
    // slot was swapped after a send".
    const pageHost = document.querySelector('[data-ak-context-pages]')
    if (pageHost && !seenPageContainers.has(pageHost)) {
        seenPageContainers.add(pageHost)
        contextPages.clear()
    }
    if (pageHost) renderContextPages()

    const scroll = document.querySelector('[data-ak-docs-chat-scroll]')
    if (scroll) scroll.scrollTop = scroll.scrollHeight

    document.querySelectorAll('[data-ak-docs-chat-input]').forEach(autoGrow)
}
