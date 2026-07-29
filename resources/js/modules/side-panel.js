// Generic side panel — single instance, content loaded via AJAX on open, cleared on close.
//
// Open:  <button data-ak-panel-open data-ak-panel-url="/url">
// Close: <button data-ak-panel-close>  (or clicking the overlay)
// Size:  <button data-ak-panel-open data-ak-panel-size="medium"> — small (default) | medium | large
//
// The server endpoint must return JSON: { "content": "<html string>" }
// After injection, initAllModules() is called to re-initialize JS in the new content.

const panel   = () => document.getElementById('side-panel')
const overlay = () => document.getElementById('side-panel-overlay')

const WIDTHS = {
    small:  ['w-96'],
    medium: ['w-1/2'],
    large:  ['w-3/4'],
}
const ALL_WIDTH_CLASSES = Object.values(WIDTHS).flat()

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

    if (url) fetchContent(p, url)
}

function close() {
    const p = panel()
    const o = overlay()
    if (!p) return

    p.classList.add('translate-x-full')
    o?.classList.add('opacity-0', 'pointer-events-none')

    // Remove injected content — restores the loading placeholder
    p.querySelector('[data-panel-content]')?.remove()
    p.querySelector('[data-panel-placeholder]')?.classList.remove('hidden')
}

function fetchContent(p, url) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content

    fetch(url, {
        headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrf,
        },
    })
    .then(r => r.json())
    .then(data => {
        if (!data.content) return

        const wrapper = document.createElement('div')
        wrapper.setAttribute('data-panel-content', '')
        wrapper.className = 'flex flex-col h-full'
        wrapper.innerHTML = data.content

        p.querySelector('[data-panel-placeholder]')?.classList.add('hidden')
        p.appendChild(wrapper)

        window.initAllModules?.()
    })
    .catch(() => close())
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
