// Generic side panel — single instance, content loaded via AJAX on open, cleared on close.
//
// Open:  <button data-ak-panel-open data-ak-panel-url="/url">
// Close: <button data-ak-panel-close>  (or clicking the overlay)
// Size:  <button data-ak-panel-open data-ak-panel-size="medium"> — small (default) | medium | large
//
// The server endpoint must return JSON: { "content": "<html string>" }
// After injection, initAllModules() is called to re-initialize JS in the new content.

import * as ajaxModule from './ajax'

const panel   = () => document.getElementById('side-panel')
const overlay = () => document.getElementById('side-panel-overlay')

const WIDTHS = {
    small:  ['w-96'],
    medium: ['w-1/2'],
    large:  ['w-3/4'],
}
const ALL_WIDTH_CLASSES = Object.values(WIDTHS).flat()

// Bumped on every open()/close() and checked when a fetch resolves — a
// single instance can only ever show one record's content at a time, so a
// still-in-flight fetch from a superseded open() (a second "editar" clicked
// before the first one's response landed) must not append its content on
// top of whatever's current. Without this, two stacked
// `[data-panel-content]` wrappers with duplicate DOM ids landed in the
// panel, and only the first was reachable by the getElementById-based JS
// (ajax-post.js, chips.js) that a form submit inside it depends on.
let requestSeq = 0

function open(url, options) {
    const p = panel()
    const o = overlay()
    if (!p) return

    const size = WIDTHS[options.akPanelSize] ? options.akPanelSize : 'small'
    p.classList.remove(...ALL_WIDTH_CLASSES)
    p.classList.add(...WIDTHS[size])

    p.classList.remove('translate-x-full')
    if (options.akPanelOverlay !== 'false')
        o?.classList.remove('opacity-0', 'pointer-events-none')

    // Reset to the loading placeholder before fetching, in case a previous
    // record's content (or an earlier failed load) is still showing.
    p.querySelector('[data-panel-content]')?.remove()
    p.querySelector('[data-panel-placeholder]')?.classList.remove('hidden')

    if (url) fetchContent(p, url, ++requestSeq)
}

function close() {
    const p = panel()
    const o = overlay()
    if (!p) return

    requestSeq++ // supersede any fetch still in flight from this open

    p.classList.add('translate-x-full')
    o?.classList.add('opacity-0', 'pointer-events-none')

    // Remove injected content — restores the loading placeholder
    p.querySelector('[data-panel-content]')?.remove()
    p.querySelector('[data-panel-placeholder]')?.classList.remove('hidden')
}

function fetchContent(p, url, requestId) {
    ajaxModule.init('GET', url)
        .then((response) => response.json())
        .then((data) => {
            if (requestId !== requestSeq) return // a later open()/close() already superseded this
            if (!data.content) throw new Error('Side panel response has no content')

            const wrapper = document.createElement('div')
            wrapper.setAttribute('data-panel-content', '')
            wrapper.className = 'flex flex-col h-full'
            wrapper.innerHTML = data.content

            p.querySelector('[data-panel-placeholder]')?.classList.add('hidden')
            p.appendChild(wrapper)

            window.initAllModules?.()
        })
        .catch(() => {
            if (requestId !== requestSeq) return
            // Without this, a 403/422/network failure left the 3-dot
            // loading placeholder spinning forever with no feedback at all.
            Toast.show('Não foi possível carregar o painel.', 'error')
            close()
        })
}

// Listener at module level — init() is a no-op so multiple initAllModules() calls are safe
document.addEventListener('click', (e) => {
    const opener = e.target.closest('[data-ak-panel-open]')
    if (opener) {
        e.preventDefault()
        open(opener.dataset.akPanelUrl ?? null, opener.dataset)
        return
    }

    if (e.target.closest('[data-ak-panel-close]')) {
        e.preventDefault()
        close()
    }
})

export function init() {}
