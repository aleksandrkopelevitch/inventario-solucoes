/**
 * What the three chat composers share — the documentation assistant
 * (docs-chat.js), the Especialista em Integrações (flowspec-chat.js) and the
 * committee interview (cati-chat.js). Each used to carry its own copy of all of
 * this; what stays in each module is only what really differs: where a paste
 * or a file goes (a caderno's documents, a conversation's context, a
 * submission's material) and the screen's own extras.
 *
 * A helper, not a registered module: nothing here binds to the page on import
 * except what a caller asks for (sendOnEnter, bindFileDrop).
 */

import * as ajaxModule from './ajax.js'
import {updateSlots} from './ajax-slot.js'

/* ------------------------------------------------------------------ */
/*  The textarea                                                       */
/* ------------------------------------------------------------------ */

/**
 * Enter sends, Shift+Enter breaks the line — by clicking the form's own send
 * button, so a disabled (busy) button ignores it like a click. Not while an
 * IME is composing: there Enter confirms a character, not the message.
 */
export function sendOnEnter(inputSelector, sendSelector) {
    document.addEventListener('keydown', (e) => {
        const input = e.target.closest?.(inputSelector)
        if (!input || e.key !== 'Enter' || e.shiftKey || e.isComposing) return
        e.preventDefault()
        input.closest('form')?.querySelector(sendSelector)?.click()
    })
}

// A size picked by dragging the native resize handle is remembered, or the
// next keystroke's auto-grow would undo it: `userHeight` is the floor the box
// never shrinks below, set when the observed height differs from the one this
// function last wrote.
const userHeight = new WeakMap()
const autoHeight = new WeakMap()
const resizeObserved = new WeakSet()

/** Grows the textarea with its content, up to `max` px (then it scrolls). */
export function autoGrow(el, max = Infinity) {
    if (!el) return
    observeUserResize(el)

    el.style.height = 'auto'
    const height = Math.max(userHeight.get(el) ?? 0, Math.min(el.scrollHeight, max))
    el.style.height = `${height}px`
    autoHeight.set(el, height)
}

function observeUserResize(el) {
    if (resizeObserved.has(el) || typeof ResizeObserver === 'undefined') return
    resizeObserved.add(el)

    new ResizeObserver(() => {
        const height = el.offsetHeight
        // 0 = the panel is closed/hidden, not a choice somebody made.
        if (height === 0 || Math.abs(height - (autoHeight.get(el) ?? height)) <= 1) return

        userHeight.set(el, height)
        autoHeight.set(el, height)
    }).observe(el)
}

/* ------------------------------------------------------------------ */
/*  Talking to the server                                              */
/* ------------------------------------------------------------------ */

/** The server's own message for a failed request, or `fallback`. */
export async function responseMessage(error, fallback) {
    if (!error?.response) return fallback

    try {
        return (await error.response.json()).message ?? fallback
    } catch (_) {
        return fallback
    }
}

/**
 * The `js` a send endpoint answers with (resetting the composer, which lives
 * outside the swapped slot). Best-effort: the message was already sent.
 */
export function runServerJs(data) {
    if (!data?.js) return
    try {
        // eslint-disable-next-line no-new-func
        new Function(data.js)()
    } catch (_) {
        // the request itself succeeded
    }
}

/**
 * POSTs `body`, swaps the slots it answers with and says what the server said
 * — or the refusal. Resolves to whether it landed, so a caller adding several
 * things can stop at the first "no". No `_token` in the body: ajax.js sends
 * the CSRF token as a header.
 */
export async function postForSlots(url, body, fallback = 'Não foi possível anexar.') {
    try {
        const response = await ajaxModule.init('POST', url, body)
        const data = await response.json()
        updateSlots(data)
        if (data.message) Toast.open({content: data.message, type: data.type || 'success'})

        return true
    } catch (error) {
        Toast.show(await responseMessage(error, fallback), 'warning')

        return false
    }
}

/**
 * One at a time, awaited, stopping at the first refusal. Never a request per
 * file fired at once: each answer repaints a slot from the state before its
 * siblings landed, and a limit checked per request (a context budget) is
 * measured against a state none of them changed yet. Never one request for
 * all of them either: `post_max_size` is far below what the per-file `max`
 * allows. And once the server says no, the rest would each earn the same Toast.
 */
export async function eachUntilRefused(items, attachOne) {
    for (const item of Array.from(items)) {
        if (!(await attachOne(item))) return false
    }

    return true
}

/** First non-blank line of a paste, so four pasted blocks are tellable apart. */
export function labelForPaste(text) {
    const first = (text.split('\n').find((line) => line.trim() !== '') || '').trim()
    if (!first) return 'Texto colado'

    return first.length > 60 ? `${first.slice(0, 57)}…` : first
}

/* ------------------------------------------------------------------ */
/*  Dropping files                                                     */
/* ------------------------------------------------------------------ */

const carriesFiles = (e) => Array.from(e.dataTransfer?.types ?? []).includes('Files')

/**
 * A file dragged over `zoneSelector` lights the zone (`onActive(zone, true)`),
 * and dropping it hands the files over (`onFiles(zone, files)`).
 *
 * `dragenter`/`dragleave` fire for every child the pointer crosses, so the
 * zone keeps a depth counter: it is left only when the counter is back to 0.
 * A drag cancelled with Esc, or dropped outside the window, never reaches
 * `drop`, which is what `dragend` is for.
 */
export function bindFileDrop(zoneSelector, {onFiles, onActive = () => {}}) {
    const depth = new Map()

    const leave = (zone) => {
        depth.delete(zone)
        onActive(zone, false)
    }

    document.addEventListener('dragenter', (e) => {
        const zone = e.target.closest?.(zoneSelector)
        if (!zone || !carriesFiles(e)) return
        e.preventDefault()
        depth.set(zone, (depth.get(zone) ?? 0) + 1)
        onActive(zone, true)
    })

    document.addEventListener('dragover', (e) => {
        if (!e.target.closest?.(zoneSelector) || !carriesFiles(e)) return
        // Without this the browser treats the zone as a non-target and, on
        // drop, navigates to the file.
        e.preventDefault()
        e.dataTransfer.dropEffect = 'copy'
    })

    document.addEventListener('dragleave', (e) => {
        const zone = e.target.closest?.(zoneSelector)
        if (!zone || !depth.has(zone)) return
        const next = depth.get(zone) - 1
        if (next <= 0) leave(zone)
        else depth.set(zone, next)
    })

    document.addEventListener('drop', (e) => {
        const zone = e.target.closest?.(zoneSelector)
        if (!zone || !carriesFiles(e)) return
        e.preventDefault()
        leave(zone)

        const files = Array.from(e.dataTransfer?.files ?? [])
        if (files.length) onFiles(zone, files)
    })

    document.addEventListener('dragend', () => {
        Array.from(depth.keys()).forEach(leave)
    })
}
