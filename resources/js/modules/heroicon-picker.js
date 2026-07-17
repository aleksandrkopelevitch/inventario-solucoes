// heroicon-picker.js — popover for picking a heroicon outline, used by the
// documentation's callouts (hint) to choose the highlighted icon.
//
// Icon source: GET /heroicons/outline (HeroiconController@outline,
// authenticated only) — fetched once and cached in the module.
//
// Imperative usage (not via a data-ak-* attribute, since the trigger is the
// hint block's badge, mounted by Editor.js):
//   import {openHeroiconPicker, getHeroiconSvg} from './heroicon-picker'
//   openHeroiconPicker({anchorEl, current: 'light-bulb', onSelect: (name) => …})

let iconsPromise = null // Promise<[{name, svg}]> — single fetch
let iconMap = null // {name: svg} once resolved
let popover = null // singleton element (appended to body)
let grid = null
let input = null
let activeOnSelect = null
let activeAnchor = null
let currentName = ''

function fetchIcons() {
    if (!iconsPromise) {
        iconsPromise = fetch('/heroicons/outline', {headers: {Accept: 'application/json'}})
            .then((r) => {
                if (!r.ok) throw new Error('heroicons fetch failed')
                return r.json()
            })
            .then((list) => {
                iconMap = {}
                list.forEach((i) => (iconMap[i.name] = i.svg))
                return list
            })
            .catch((e) => {
                iconsPromise = null // allows a retry on the next open
                throw e
            })
    }
    return iconsPromise
}

/** SVG (string) of an outline icon by name, ensuring the list is loaded. */
export async function getHeroiconSvg(name) {
    if (!name) return null
    try {
        await fetchIcons()
    } catch {
        return null
    }
    return iconMap[name] || null
}

function buildPopover() {
    if (popover) return popover

    popover = document.createElement('div')
    popover.className = 'ak-icon-picker hidden fixed z-50 w-72 rounded-field border border-line bg-surface p-2 shadow-xl'
    popover.innerHTML = `
        <input type="text" data-picker-search
            class="mb-2 h-8 w-full rounded-md border border-line bg-canvas px-2.5 text-sm text-ink outline-none placeholder:text-muted focus:border-accent-line"
            placeholder="Buscar ícone…" aria-label="Buscar ícone">
        <div data-picker-grid role="listbox"
            class="grid max-h-56 grid-cols-6 gap-1 overflow-y-auto"></div>`

    document.body.appendChild(popover)

    input = popover.querySelector('[data-picker-search]')
    grid = popover.querySelector('[data-picker-grid]')

    input.addEventListener('input', () => renderGrid(input.value.trim().toLowerCase()))

    grid.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-icon-name]')
        if (!btn) return
        const name = btn.dataset.iconName
        const cb = activeOnSelect
        close()
        if (cb) cb(name)
    })

    return popover
}

function renderGrid(filter = '') {
    if (!grid) return

    const entries = Object.entries(iconMap || {})
    const matches = filter ? entries.filter(([name]) => name.includes(filter)) : entries

    if (matches.length === 0) {
        grid.innerHTML = '<p class="col-span-6 px-1 py-4 text-center text-xs text-muted">Nenhum ícone encontrado.</p>'
        return
    }

    grid.innerHTML = matches
        .map(([name, svg]) => {
            const active = name === currentName
                ? ' bg-accent-soft text-accent ring-1 ring-accent-line'
                : ' text-muted hover:bg-raised hover:text-ink'
            return `<button type="button" data-icon-name="${name}" title="${name}" aria-label="${name}"
                class="flex size-9 items-center justify-center rounded-md [&>svg]:size-5${active}">${svg}</button>`
        })
        .join('')
}

function position(anchorEl) {
    const r = anchorEl.getBoundingClientRect()
    const pw = 288 // w-72
    const ph = popover.offsetHeight || 300
    let left = r.left
    let top = r.bottom + 6
    if (left + pw > window.innerWidth - 8) left = window.innerWidth - pw - 8
    if (top + ph > window.innerHeight - 8) top = Math.max(8, r.top - ph - 6)
    popover.style.left = `${Math.max(8, left)}px`
    popover.style.top = `${top}px`
}

function close() {
    if (popover) popover.classList.add('hidden')
    activeOnSelect = null
    activeAnchor = null
}

/** Opens the picker anchored to an element. Calls `onSelect(name)` on pick. */
export async function openHeroiconPicker({anchorEl, current = '', onSelect}) {
    buildPopover()
    activeOnSelect = onSelect
    activeAnchor = anchorEl
    currentName = current

    popover.classList.remove('hidden')
    input.value = ''

    try {
        await fetchIcons()
    } catch {
        grid.innerHTML = '<p class="col-span-6 px-1 py-4 text-center text-xs text-muted">Falha ao carregar os ícones.</p>'
        position(anchorEl)
        return
    }

    renderGrid('')
    position(anchorEl)
    input.focus()

    const active = grid.querySelector('[data-icon-name].bg-accent-soft')
    if (active) active.scrollIntoView({block: 'nearest'})
}

// Closes on outside click / Esc. Module-level delegation (see CLAUDE.md).
document.addEventListener('click', (e) => {
    if (!popover || popover.classList.contains('hidden')) return
    if (popover.contains(e.target)) return
    if (activeAnchor && activeAnchor.contains(e.target)) return // the anchor itself reopens/repositions it
    close()
})

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && popover && !popover.classList.contains('hidden')) close()
})

export function init() {} // no-op — API is imperative; keeps the globalModules interface
