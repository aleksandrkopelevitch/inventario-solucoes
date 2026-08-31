// docs-secret.js — reveals ONE protected value of a documentation page.
//
// `GitbookRenderer` paints a `{% secret %}` as a lock button carrying no
// plaintext, and the editor is fed `[[SECRET-n]]` markers in the same places
// (App\Support\Documentation\SecretText). This module is the only way a reader
// gets the value: it asks the server for one, shows it in a popover, and takes
// it back — on Esc, on a click elsewhere, on a scroll, or after RELOCK_MS.
// Nothing is remembered, so a second value means asking a second time.
//
// It answers TWO shapes, because "reading a page" means two different screens:
//
//  - `[data-ak-secret]`  — a lock in rendered documentation (a viewer, or a
//    visitor on a magic link).
//  - `.ak-secret-mark`   — the same value inside Editor.js, where an EDITOR or
//    an admin spends their time. Its text is the marker, so the ordinal is
//    read out of it.
//
// The popover is appended to `document.body`, never beside the lock, and that
// is load-bearing on the second shape: Editor.js decides a block changed by
// watching its DOM, so injecting a field into the editor would mark the page
// dirty and autosave would write it seconds later (the same trap
// `docs-tools/diagram.js` needs `data-mutation-free` for).
//
// The endpoint is not built here. It arrives as `data-ak-secret-url` with
// `__INDEX__` where the ordinal goes — one template for the authenticated
// reader and the magic link, so this module has no route of its own and cannot
// tell which surface it is running on (the contract `chain-viz.js` keeps).
import * as ajaxModule from './ajax'

const MAX_ATTEMPTS = 5

/** Twelve hours — the same window App\Actions\Documentation\RevealPageSecret enforces. */
const LOCKOUT_MS = 12 * 60 * 60 * 1000

/** How long a revealed value stays on screen before locking itself again. */
const RELOCK_MS = 30 * 1000

const STORAGE_KEY = 'ak-docs-secret-attempts'

/** The marker an editor sees in place of a value it may not read. */
const MARKER = /^\s*\[\[SECRET-(\d+)\]\]\s*$/

/** The one popover on the page. Two open at once would mean two values on screen. */
let popover = null

/* ------------------------------ attempt ledger ------------------------------ */

// Failures are counted PER CADERNO in localStorage, mirroring the server's
// five-per-twelve-hours limit. This copy is a courtesy, not the protection: it
// makes the lockout immediate and legible (a Toast, no request) for whoever
// mistyped, and anyone who knows where to look can clear it. The limit that
// matters is the one in the action.
function readLedger() {
    try {
        return JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}')
    } catch {
        // Private windows, cleared site data, storage blocked outright — a
        // reader with no localStorage must still be able to use the feature.
        return {}
    }
}

function writeLedger(ledger) {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(ledger))
    } catch {
        // Nothing to do and nothing to say: the server is still counting.
    }
}

function lockedUntil(scope) {
    const until = readLedger()[scope]?.until ?? 0

    return until > Date.now() ? until : 0
}

function registerFailure(scope) {
    const ledger = readLedger()
    const fails = (ledger[scope]?.fails ?? 0) + 1

    ledger[scope] = {fails, until: fails >= MAX_ATTEMPTS ? Date.now() + LOCKOUT_MS : 0}
    writeLedger(ledger)

    return ledger[scope]
}

function lockOut(scope) {
    writeLedger({...readLedger(), [scope]: {fails: MAX_ATTEMPTS, until: Date.now() + LOCKOUT_MS}})
}

function clearFailures(scope) {
    const ledger = readLedger()
    delete ledger[scope]
    writeLedger(ledger)
}

function hoursLeft(until) {
    return Math.max(1, Math.ceil((until - Date.now()) / 3600000))
}

/* --------------------------------- popover ---------------------------------- */

function close() {
    if (!popover) return

    clearTimeout(popover.timer)
    popover.node.remove()
    popover = null
}

/**
 * Anchored with `position: fixed` to the lock's own rect — and closed by any
 * scroll, rather than tracked. A value that must disappear in thirty seconds
 * does not need to follow the page around; drifting away from its lock while
 * still on screen would be worse than being dismissed.
 */
function place(node, anchor) {
    const rect = anchor.getBoundingClientRect()

    node.style.top = `${Math.round(rect.bottom + 6)}px`
    node.style.left = `${Math.round(Math.min(rect.left, window.innerWidth - 260))}px`
}

function open(anchor, scope) {
    close()

    const node = document.createElement('div')
    node.dataset.akSecretPopover = ''
    node.className = 'fixed z-50 w-60 rounded-field border border-line bg-surface p-2.5 shadow-xl'
    document.body.appendChild(node)
    place(node, anchor)

    popover = {node, scope, timer: null}

    return node
}

function renderPrompt(node, onSubmit) {
    node.innerHTML = ''

    const label = document.createElement('p')
    label.className = 'mb-1.5 text-[11px] font-medium text-muted'
    label.textContent = 'Código de leitura do caderno'

    const row = document.createElement('div')
    row.className = 'flex items-center gap-1.5'

    const input = document.createElement('input')
    input.type = 'text'
    input.autocomplete = 'off'
    input.spellcheck = false
    // A code is six characters and its case matters (`X6h2dG`), so both
    // conveniences a mobile keyboard applies on its own are wrong here.
    input.autocapitalize = 'off'
    input.setAttribute('aria-label', 'Código de leitura do caderno')
    input.className = 'min-w-0 flex-1 rounded-field border border-line bg-surface px-2 py-1 font-mono text-xs text-ink outline-none focus:border-accent-line'

    const confirm = document.createElement('button')
    confirm.type = 'button'
    confirm.className = 'shrink-0 rounded-field bg-accent px-2 py-1 text-xs font-semibold text-white'
    confirm.textContent = 'Ver'

    row.append(input, confirm)
    node.append(label, row)

    confirm.addEventListener('click', () => onSubmit(input))
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault()
            onSubmit(input)
        }
    })

    input.focus()

    return input
}

function renderValue(node, value) {
    node.innerHTML = ''

    const label = document.createElement('p')
    label.className = 'mb-1.5 text-[11px] font-medium text-muted'
    label.textContent = 'Valor protegido'

    const shown = document.createElement('p')
    shown.className = 'break-all rounded-field bg-raised px-2 py-1 font-mono text-xs text-ink select-all'
    // textContent, never innerHTML: this is authored content coming back from
    // the server, and it is the one string on the page nobody has escaped.
    shown.textContent = value

    const foot = document.createElement('div')
    foot.className = 'mt-1.5 flex items-center justify-between gap-2'

    const hint = document.createElement('span')
    hint.className = 'text-[11px] text-faint'
    hint.textContent = 'Oculta em 30s'

    const copy = document.createElement('button')
    copy.type = 'button'
    copy.className = 'text-[11px] font-medium text-accent hover:underline'
    copy.textContent = 'Copiar'
    copy.addEventListener('click', () => {
        navigator.clipboard.writeText(value)
            .then(() => Toast.show('Valor copiado.'))
            .catch(() => Toast.show('Selecione e copie manualmente.', 'warning'))
    })

    foot.append(hint, copy)
    node.append(label, shown, foot)

    popover.timer = setTimeout(close, RELOCK_MS)
}

/* ---------------------------------- reveal ---------------------------------- */

function request({url, scope, node, input}) {
    const body = new FormData()

    if (input) {
        const code = input.value.trim()

        if (code === '') {
            input.focus()

            return
        }

        body.append('code', code)
    }

    ajaxModule
        .init('POST', url, body)
        .then((response) => response.json())
        .then((data) => {
            clearFailures(scope)
            if (popover?.node === node) renderValue(node, data.value)
        })
        .catch(async (error) => {
            const response = error.response

            if (!response) {
                Toast.show('Não foi possível verificar o código.', 'error')

                return
            }

            const data = await response.json().catch(() => ({}))

            // 422 is a wrong code — the only outcome that counts against the
            // reader. A 404 (the page changed under this render) or a 419
            // (expired session) is not a failed guess and must not spend an
            // attempt.
            if (response.status === 422) {
                const {until} = registerFailure(scope)
                Toast.show(data.message ?? 'Código incorreto.', 'warning')

                if (until) {
                    close()
                } else {
                    input?.select()
                }

                return
            }

            if (response.status === 429) {
                // The server is the authority on the window: adopt its verdict
                // even if this browser's ledger was cleared.
                lockOut(scope)
                close()
            }

            Toast.show(data.message ?? 'Não foi possível revelar o valor.', 'error')
        })
}

/**
 * The lock's ordinal — the index of the value in the page's text.
 *
 * A rendered lock carries it in `data-ak-secret`; in the editor it is inside
 * the marker itself. A marked span whose text is NOT a marker holds a value
 * somebody just typed, and there is nothing to ask the server for.
 */
function ordinalOf(trigger) {
    if (trigger.dataset.akSecret) return trigger.dataset.akSecret

    return trigger.textContent.match(MARKER)?.[1] ?? null
}

document.addEventListener('click', (e) => {
    const trigger = e.target.closest('[data-ak-secret], .ak-secret-mark')

    if (!trigger) {
        // Anywhere else re-locks what is open — `closest` on the popover itself
        // so typing in its field doesn't dismiss it.
        if (popover && !e.target.closest('[data-ak-secret-popover]')) close()

        return
    }

    const host = trigger.closest('[data-ak-secret-url]')
    const ordinal = ordinalOf(trigger)

    // No endpoint means no way to ask (a lock rendered outside a reader that
    // knows its page — the flowSpec chat renders `.html-content` too), and no
    // ordinal means there is nothing to ask for. Inert either way: the value is
    // still not on the page.
    if (!host || ordinal === null) return

    const scope = host.dataset.akSecretScope ?? 'default'
    const until = lockedUntil(scope)

    if (until) {
        Toast.show(
            `Muitas tentativas incorretas. Tente novamente em aproximadamente ${hoursLeft(until)}h.`,
            'error',
        )

        return
    }

    const url = host.dataset.akSecretUrl.replace('__INDEX__', ordinal)
    const node = open(trigger, scope)

    // An admin needs no code, and the server enforces that either way — this
    // flag only decides whether they are ASKED for one. Setting it by hand
    // buys nothing: the request still arrives without a code and is refused.
    if (host.dataset.akSecretUnlocked === '1') {
        node.textContent = 'Revelando…'
        node.className += ' text-xs text-muted'
        request({url, scope, node})

        return
    }

    renderPrompt(node, (field) => request({url, scope, node, input: field}))
})

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') close()
})

// Any scroll, including the reader's own container (hence the capture phase).
document.addEventListener('scroll', () => close(), true)

export function init() {}
