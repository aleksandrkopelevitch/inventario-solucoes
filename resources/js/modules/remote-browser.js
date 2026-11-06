/**
 * The server's sign-in browser, drawn on "Sessão Digibee" and driven from it.
 *
 *   <div data-ak-remote-browser='{"frameUrl","inputUrl","width","height"}' tabindex="0">
 *       <img data-ak-remote-browser-frame>
 *   </div>
 *
 * Two loops, deliberately not marker-poll.js: that module waits for a JOB's
 * outcome and swaps a slot; this is a video feed (a JPEG every ~400 ms) plus
 * the person's input going the other way. The run's state — started, signed
 * in, failed — still arrives through marker-poll.js, whose slot swap removes
 * this element and with it both loops.
 *
 * Input is sent in order, one request at a time, so "abc" never arrives as
 * "acb". Nothing typed is logged or kept here: a password passes through.
 */

import * as ajaxModule from './ajax.js'

const FRAME_INTERVAL = 400

/** Keys the bridge accepts as presses; any other single character is typed. */
const KEYS = new Set(['Enter', 'Tab', 'Backspace', 'Delete', 'Escape', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'])

const mounted = new WeakSet()

export function init() {
    document.querySelectorAll('[data-ak-remote-browser]').forEach((root) => {
        if (mounted.has(root)) return
        mounted.add(root)
        mount(root)
    })
}

function mount(root) {
    const config = JSON.parse(root.dataset.akRemoteBrowser)
    const img = root.querySelector('[data-ak-remote-browser-frame]')
    let frameTimer = null
    let objectUrl = null

    // ── frames ──────────────────────────────────────────────────────────
    async function nextFrame() {
        if (!root.isConnected) {
            if (objectUrl) URL.revokeObjectURL(objectUrl)
            return
        }

        try {
            const response = await fetch(config.frameUrl, {cache: 'no-store', headers: {Accept: 'image/jpeg'}})

            if (response.status === 200) {
                const url = URL.createObjectURL(await response.blob())
                img.src = url
                if (objectUrl) URL.revokeObjectURL(objectUrl)
                objectUrl = url
            }
        } catch (_) {
            // The browser closing is the normal end of a run; the status poll says so.
        }

        frameTimer = setTimeout(nextFrame, FRAME_INTERVAL)
    }

    function frameSoon() {
        clearTimeout(frameTimer)
        frameTimer = setTimeout(nextFrame, 120)
    }

    // ── input, in order ─────────────────────────────────────────────────
    let queue = Promise.resolve()

    function send(event) {
        queue = queue
            .then(() => {
                if (!root.isConnected) return
                const data = new FormData()
                Object.entries(event).forEach(([key, value]) => data.append(key, String(value)))
                return ajaxModule.init('POST', config.inputUrl, data)
            })
            .catch(() => {})
            .then(frameSoon)
    }

    // Characters are gathered for a moment and sent as one run of typing:
    // one request per key would make a password a dozen round trips.
    let typed = ''
    let typeTimer = null

    function flushTyped() {
        clearTimeout(typeTimer)
        if (typed === '') return
        send({type: 'type', text: typed})
        typed = ''
    }

    function type(text) {
        typed += text
        if (typed.length >= 400) {
            flushTyped()
            return
        }
        clearTimeout(typeTimer)
        typeTimer = setTimeout(flushTyped, 60)
    }

    img.addEventListener('click', (e) => {
        root.focus()
        const box = img.getBoundingClientRect()
        flushTyped()
        send({
            type: 'click',
            x: Math.round((e.clientX - box.left) * config.width / box.width),
            y: Math.round((e.clientY - box.top) * config.height / box.height),
        })
    })

    root.addEventListener('keydown', (e) => {
        if (e.ctrlKey || e.metaKey || e.altKey) return // Ctrl+V arrives as a paste event

        if (e.key === 'Tab') {
            e.preventDefault()
            flushTyped()
            send({type: 'press', key: e.shiftKey ? 'Shift+Tab' : 'Tab'})
            return
        }

        if (KEYS.has(e.key)) {
            e.preventDefault()
            flushTyped()
            send({type: 'press', key: e.key})
            return
        }

        if (e.key.length === 1) {
            e.preventDefault()
            type(e.key)
        }
    })

    root.addEventListener('paste', (e) => {
        const text = e.clipboardData?.getData('text/plain') ?? ''
        if (text === '') return
        e.preventDefault()
        type(text)
        flushTyped()
    })

    let wheelDelta = 0
    let wheelTimer = null

    root.addEventListener('wheel', (e) => {
        e.preventDefault()
        wheelDelta += e.deltaY
        clearTimeout(wheelTimer)
        wheelTimer = setTimeout(() => {
            send({type: 'scroll', dy: Math.round(Math.max(-2000, Math.min(2000, wheelDelta)))})
            wheelDelta = 0
        }, 80)
    }, {passive: false})

    nextFrame()
    root.focus({preventScroll: true})
}
