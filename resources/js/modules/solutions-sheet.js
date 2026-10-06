// solutions-sheet.js — the read-only solutions spreadsheet (x-solutions.spreadsheet),
// on the inventory screen and on its magic link alike.
//
// The server embeds the WHOLE dataset as JSON (`[data-ak-sheet-data]`, built by
// SolutionSpreadsheetService) and everything after that happens here: the
// catalog is ~100 rows, so a value filter, a sort or a column toggle is a
// re-render, not a request. The export is the one thing that goes back to the
// server, and it carries the visible columns and the ids on screen, in display
// order — the file is what you were looking at, with no second implementation
// of the filters in PHP to drift from this one.
//
// A cell is a string, an array of strings (several owners, several cadernos),
// a number or null. A column's value filter is the set of values ALLOWED
// (Excel's checkbox list); a row passes when any of its values is in the set.
// Missing values are a value of their own, "(Vazio)", so "who has no owner"
// is a filter like any other.
//
// What is remembered in localStorage — per audience, a per-viewer convenience —
// is the column layout and the sort. Filters and the search are not: a sheet
// that reopens already narrowed reads as missing rows.
import {fold} from './fold'

const EMPTY = '\u0000'
const EMPTY_LABEL = '(Vazio)'
const POPOVER_WIDTH = 288
const collator = new Intl.Collator('pt-BR', {numeric: true, sensitivity: 'base'})

const sheets = new WeakMap()

export function init() {
    document.querySelectorAll('[data-ak-sheet]').forEach((root) => {
        if (sheets.has(root)) return

        const source = root.querySelector('[data-ak-sheet-data]')
        if (!source) return

        const data = JSON.parse(source.textContent)
        const state = {
            root,
            columns: data.columns,
            rows: data.rows,
            hidden: defaultHidden(data.columns),
            filters: new Map(),
            search: '',
            sort: null,
            popoverKey: null,
        }

        restore(state)
        sheets.set(root, state)
        syncColumnCheckboxes(state)
        render(state)
    })
}

/* ------------------------------------------------------------------ state */

function defaultHidden(columns) {
    return new Set(columns.filter((c) => c.hidden).map((c) => c.key))
}

function storageKey(state) {
    return state.root.dataset.akSheetStorage || null
}

function restore(state) {
    const key = storageKey(state)
    if (!key) return

    try {
        const saved = JSON.parse(window.localStorage.getItem(key) || 'null')
        if (!saved) return

        const known = new Set(state.columns.map((c) => c.key))
        if (Array.isArray(saved.hidden)) {
            state.hidden = new Set(saved.hidden.filter((k) => known.has(k) && k !== 'name'))
        }
        if (saved.sort && known.has(saved.sort.key) && ['asc', 'desc'].includes(saved.sort.dir)) {
            state.sort = saved.sort
        }
    } catch {
        // Storage refused or corrupt: the defaults are a perfectly good sheet.
    }
}

function persist(state) {
    const key = storageKey(state)
    if (!key) return

    try {
        window.localStorage.setItem(key, JSON.stringify({hidden: [...state.hidden], sort: state.sort}))
    } catch {
        // Private window, blocked storage — the layout just isn't remembered.
    }
}

function visibleColumns(state) {
    return state.columns.filter((c) => !state.hidden.has(c.key))
}

function column(state, key) {
    return state.columns.find((c) => c.key === key)
}

/* ----------------------------------------------------------------- values */

/** A cell as the list of filterable values it holds. */
function valuesOf(row, col) {
    const value = row.cells[col.key]

    if (Array.isArray(value)) return value.length ? value.map(String) : [EMPTY]
    if (value === null || value === undefined || value === '') return [EMPTY]

    return [String(value)]
}

function label(value, col) {
    if (value === EMPTY) return EMPTY_LABEL
    if (col.kind === 'date') return formatDate(value)

    return value
}

function formatDate(iso) {
    const [y, m, d] = String(iso).split('-')

    return d && m && y ? `${d}/${m}/${y}` : iso
}

function matchesSearch(state, row, cols) {
    if (!state.search) return true

    return cols.some((col) => valuesOf(row, col).some((v) => v !== EMPTY && fold(label(v, col)).includes(state.search)))
}

/** Rows passing the search and every value filter — except `exceptKey`'s, for that column's own popover. */
function filteredRows(state, exceptKey = null) {
    const cols = visibleColumns(state)

    return state.rows.filter((row) => {
        if (!matchesSearch(state, row, cols)) return false

        for (const [key, allowed] of state.filters) {
            if (key === exceptKey) continue
            const col = column(state, key)
            if (!valuesOf(row, col).some((v) => allowed.has(v))) return false
        }

        return true
    })
}

function sortValue(row, col) {
    const value = row.cells[col.key]

    if (Array.isArray(value)) return value.length ? value.join(', ') : null
    if (value === '' || value === undefined) return null

    return value
}

function compareValues(a, b, col) {
    if (col.kind === 'number') return Number(a) - Number(b)

    return collator.compare(String(a), String(b))
}

function sortedRows(state, rows) {
    if (!state.sort) return rows

    const col = column(state, state.sort.key)
    const dir = state.sort.dir === 'desc' ? -1 : 1

    return [...rows].sort((ra, rb) => {
        const a = sortValue(ra, col)
        const b = sortValue(rb, col)

        // Blanks sink to the bottom in either direction — a descending sort
        // that opens on twenty empty cells hides the answer.
        if (a === null && b === null) return 0
        if (a === null) return 1
        if (b === null) return -1

        return dir * compareValues(a, b, col)
    })
}

function displayedRows(state) {
    return sortedRows(state, filteredRows(state))
}

/* ----------------------------------------------------------------- render */

function render(state) {
    const grid = state.root.querySelector('[data-ak-sheet-grid]')
    const cols = visibleColumns(state)
    const rows = displayedRows(state)

    const table = document.createElement('table')
    table.className = 'w-max min-w-full border-separate border-spacing-0 text-[13px]'
    table.append(renderHead(state, cols), renderBody(state, cols, rows))
    grid.replaceChildren(table)

    if (!rows.length) {
        const empty = document.createElement('p')
        empty.className = 'sticky left-0 px-6 py-10 text-sm text-muted'
        empty.textContent = 'Nenhuma solução corresponde à busca e aos filtros.'
        grid.append(empty)
    }

    const total = state.rows.length
    const narrowed = state.filters.size > 0 || state.search !== ''
    state.root.querySelector('[data-ak-sheet-count]').textContent = narrowed
        ? `${rows.length} de ${total}`
        : `${total} soluções`
    state.root.querySelector('[data-ak-sheet-clear]')?.classList.toggle('hidden', !narrowed)
}

function icon(state, name) {
    const template = state.root.querySelector(`[data-ak-sheet-icon="${name}"]`)

    return template ? template.content.firstElementChild.cloneNode(true) : document.createTextNode('')
}

function headerControls(state, col) {
    const wrap = document.createElement('div')
    wrap.className = 'flex items-center gap-1'

    const sort = document.createElement('button')
    sort.type = 'button'
    sort.dataset.akSheetSortToggle = col.key
    sort.className = 'flex min-w-0 flex-1 cursor-pointer items-center gap-1 text-left font-semibold text-ink hover:text-accent'
    sort.title = 'Ordenar'
    const text = document.createElement('span')
    text.textContent = col.label
    sort.append(text)
    if (state.sort?.key === col.key) {
        const arrow = document.createElement('span')
        arrow.className = 'text-accent'
        arrow.append(icon(state, state.sort.dir))
        sort.append(arrow)
    }

    const active = state.filters.has(col.key)
    const filter = document.createElement('button')
    filter.type = 'button'
    filter.dataset.akSheetFilter = col.key
    filter.setAttribute('aria-label', `Filtrar ${col.label}`)
    filter.title = active ? 'Filtro ativo' : 'Filtrar'
    filter.className = active
        ? 'shrink-0 cursor-pointer rounded bg-accent-soft p-1 text-accent'
        : 'shrink-0 cursor-pointer rounded p-1 text-faint hover:bg-raised hover:text-ink'
    filter.append(icon(state, 'filter'))

    wrap.append(sort, filter)

    return wrap
}

function renderHead(state, cols) {
    const thead = document.createElement('thead')
    thead.className = 'sticky top-0 z-20'

    const groupRow = document.createElement('tr')
    const labelRow = document.createElement('tr')

    const [first, ...rest] = cols
    const corner = document.createElement('th')
    corner.rowSpan = 2
    corner.className = 'sticky left-0 z-30 min-w-[9rem] sm:min-w-[13rem] border-b border-r border-line-2 bg-canvas px-3 py-2 text-left align-bottom whitespace-nowrap'
    corner.append(headerControls(state, first))
    groupRow.append(corner)

    // Consecutive columns of one group share a spanning cell, so the group
    // label survives any combination of hidden columns.
    let previous = null
    rest.forEach((col) => {
        if (previous && previous.dataset.group === col.group) {
            previous.colSpan += 1
        } else {
            previous = document.createElement('th')
            previous.dataset.group = col.group
            previous.colSpan = 1
            previous.className = 'border-b border-r border-line bg-canvas px-3 pb-1 pt-2 text-left text-[10px] font-bold uppercase tracking-[0.12em] text-faint whitespace-nowrap'
            previous.textContent = col.group
            groupRow.append(previous)
        }

        const th = document.createElement('th')
        th.className = 'border-b border-r border-line-2 bg-canvas px-3 py-2 text-left align-bottom whitespace-nowrap'
        th.append(headerControls(state, col))
        labelRow.append(th)
    })

    thead.append(groupRow, labelRow)

    return thead
}

function renderBody(state, cols, rows) {
    const tbody = document.createElement('tbody')

    rows.forEach((row) => {
        const tr = document.createElement('tr')
        tr.className = 'group'

        cols.forEach((col, index) => {
            const td = document.createElement('td')
            td.className = index === 0
                // Narrower and wrapping on a phone, where a frozen column of
                // full-length names would leave no room for anything else.
                ? 'sticky left-0 z-10 max-w-[9rem] border-b border-r border-line-2 bg-white px-3 py-2 align-top font-semibold text-ink group-hover:bg-canvas sm:max-w-none'
                : 'border-b border-r border-line bg-white px-3 py-2 align-top text-body group-hover:bg-canvas'
            td.append(cellContent(row, col, index === 0))
            tr.append(td)
        })

        tbody.append(tr)
    })

    return tbody
}

function cellContent(row, col, isName) {
    const value = row.cells[col.key]

    if (isName && row.url) {
        const a = document.createElement('a')
        a.href = row.url
        a.className = 'text-ink no-underline hover:text-accent hover:underline sm:whitespace-nowrap'
        a.textContent = value

        return a
    }

    if (isName) {
        const name = document.createElement('div')
        name.className = 'sm:whitespace-nowrap'
        name.textContent = value

        return name
    }

    const blank = value === null || value === undefined || value === '' || (Array.isArray(value) && !value.length)
    if (blank) {
        const dash = document.createElement('span')
        dash.className = 'text-faint'
        dash.textContent = '—'

        return dash
    }

    if (Array.isArray(value)) {
        const list = document.createElement('div')
        list.className = col.kind === 'long' ? 'min-w-[16rem] max-w-[26rem]' : 'whitespace-nowrap'
        value.forEach((item) => {
            const line = document.createElement('div')
            line.textContent = item
            list.append(line)
        })

        return list
    }

    const el = document.createElement('div')

    if (col.kind === 'long') {
        el.className = 'line-clamp-3 min-w-[16rem] max-w-[26rem] whitespace-normal'
        el.title = value
        el.textContent = value
    } else if (col.kind === 'number') {
        el.className = 'text-right tabular-nums'
        el.textContent = value
    } else {
        el.className = 'whitespace-nowrap'
        el.textContent = label(String(value), col)
    }

    return el
}

/* ---------------------------------------------------------------- popover */

function popover(state) {
    return state.root.querySelector('[data-ak-sheet-popover]')
}

function openPopover(state, key, anchor) {
    const pop = popover(state)
    const col = column(state, key)
    state.popoverKey = key

    pop.querySelector('[data-ak-sheet-popover-title]').textContent = col.label
    pop.querySelector('[data-ak-sheet-popover-search]').value = ''
    pop.querySelectorAll('[data-ak-sheet-sort]').forEach((btn) => {
        const on = state.sort?.key === key && state.sort.dir === btn.dataset.akSheetSort
        btn.classList.toggle('!bg-accent-soft', on)
        btn.classList.toggle('!text-accent', on)
    })
    fillPopoverList(state)

    const rect = anchor.getBoundingClientRect()
    pop.style.left = `${Math.max(8, Math.min(rect.left - POPOVER_WIDTH + rect.width, window.innerWidth - POPOVER_WIDTH - 8))}px`
    pop.style.top = `${Math.min(rect.bottom + 4, window.innerHeight - 120)}px`
    pop.classList.remove('hidden')
    pop.querySelector('[data-ak-sheet-popover-search]').focus()
}

function closePopover(state) {
    state.popoverKey = null
    popover(state)?.classList.add('hidden')
}

/** Every value the column holds, counted against the rows the OTHER filters leave. */
function fillPopoverList(state) {
    const col = column(state, state.popoverKey)
    const list = popover(state).querySelector('[data-ak-sheet-popover-list]')

    const counts = new Map()
    filteredRows(state, col.key).forEach((row) => {
        new Set(valuesOf(row, col)).forEach((v) => counts.set(v, (counts.get(v) ?? 0) + 1))
    })

    const all = new Set()
    state.rows.forEach((row) => valuesOf(row, col).forEach((v) => all.add(v)))

    const values = [...all].sort((a, b) => {
        if (a === EMPTY) return 1
        if (b === EMPTY) return -1

        return compareValues(a, b, col)
    })

    const allowed = state.filters.get(col.key)

    list.replaceChildren(...values.map((value) => {
        const row = document.createElement('label')
        row.dataset.akSheetPopoverItem = fold(label(value, col))
        row.className = 'flex cursor-pointer items-center gap-2 rounded px-1 py-1 text-[13px] text-body hover:bg-raised'

        const box = document.createElement('input')
        box.type = 'checkbox'
        box.value = value
        box.checked = !allowed || allowed.has(value)
        box.className = 'size-3.5 shrink-0 cursor-pointer accent-[var(--color-accent)]'

        const text = document.createElement('span')
        text.className = value === EMPTY ? 'min-w-0 flex-1 truncate italic text-muted' : 'min-w-0 flex-1 truncate'
        text.textContent = label(value, col)
        text.title = label(value, col)

        const count = document.createElement('span')
        const n = counts.get(value) ?? 0
        count.className = n ? 'shrink-0 font-mono text-[11px] text-muted' : 'shrink-0 font-mono text-[11px] text-line-2'
        count.textContent = n

        row.append(box, text, count)

        return row
    }))
}

function applyPopover(state) {
    const boxes = [...popover(state).querySelectorAll('[data-ak-sheet-popover-list] input[type="checkbox"]')]
    const checked = boxes.filter((b) => b.checked).map((b) => b.value)

    if (checked.length === boxes.length) {
        state.filters.delete(state.popoverKey)
    } else {
        state.filters.set(state.popoverKey, new Set(checked))
    }

    render(state)
}

function setVisibleBoxes(state, checked) {
    popover(state).querySelectorAll('[data-ak-sheet-popover-item]').forEach((item) => {
        if (item.classList.contains('hidden')) return
        item.querySelector('input').checked = checked
    })
    applyPopover(state)
}

/* ---------------------------------------------------------------- columns */

function syncColumnCheckboxes(state) {
    state.root.querySelectorAll('[data-ak-sheet-column]').forEach((box) => {
        box.checked = !state.hidden.has(box.dataset.akSheetColumn)
    })
}

function setHidden(state, hidden) {
    hidden.delete('name')
    state.hidden = hidden
    // A filter on a column nobody can see would remove rows for no visible
    // reason — hiding a column lets go of its filter.
    hidden.forEach((key) => state.filters.delete(key))
    if (state.sort && hidden.has(state.sort.key)) state.sort = null

    syncColumnCheckboxes(state)
    persist(state)
    render(state)
}

/* ----------------------------------------------------------------- export */

function exportSheet(state, link) {
    const rows = displayedRows(state)

    if (!rows.length) {
        Toast.show('Nenhuma linha para exportar com os filtros atuais.', 'warning')
        return
    }

    const params = new URLSearchParams({format: link.dataset.akSheetExport})
    visibleColumns(state).forEach((c) => params.append('columns[]', c.key))
    rows.forEach((r) => params.append('ids[]', r.id))

    link.closest('[id]')?.classList.add('hidden')
    window.location.assign(`${state.root.dataset.akSheetExportUrl}?${params}`)
}

/* ----------------------------------------------------------------- events */

function stateFor(target) {
    const root = target.closest('[data-ak-sheet]')

    return root ? sheets.get(root) : null
}

document.addEventListener('click', (e) => {
    if (!(e.target instanceof Element)) return

    // Outside clicks close the value popover, wherever they land.
    document.querySelectorAll('[data-ak-sheet]').forEach((root) => {
        const state = sheets.get(root)
        if (!state?.popoverKey) return
        if (e.target.closest('[data-ak-sheet-popover]') || e.target.closest('[data-ak-sheet-filter]')) return
        closePopover(state)
    })

    const state = stateFor(e.target)
    if (!state) return

    const filterBtn = e.target.closest('[data-ak-sheet-filter]')
    if (filterBtn) {
        const key = filterBtn.dataset.akSheetFilter
        state.popoverKey === key ? closePopover(state) : openPopover(state, key, filterBtn)
        return
    }

    const sortToggle = e.target.closest('[data-ak-sheet-sort-toggle]')
    if (sortToggle) {
        const key = sortToggle.dataset.akSheetSortToggle
        // asc → desc → unsorted, the usual three-state header.
        state.sort = state.sort?.key !== key
            ? {key, dir: 'asc'}
            : (state.sort.dir === 'asc' ? {key, dir: 'desc'} : null)
        persist(state)
        render(state)
        return
    }

    const sortBtn = e.target.closest('[data-ak-sheet-sort]')
    if (sortBtn && state.popoverKey) {
        state.sort = {key: state.popoverKey, dir: sortBtn.dataset.akSheetSort}
        persist(state)
        closePopover(state)
        render(state)
        return
    }

    if (e.target.closest('[data-ak-sheet-popover-all]')) return setVisibleBoxes(state, true)
    if (e.target.closest('[data-ak-sheet-popover-none]')) return setVisibleBoxes(state, false)

    if (e.target.closest('[data-ak-sheet-popover-reset]')) {
        state.filters.delete(state.popoverKey)
        fillPopoverList(state)
        render(state)
        return
    }

    const columnsBtn = e.target.closest('[data-ak-sheet-columns]')
    if (columnsBtn) {
        setHidden(state, columnsBtn.dataset.akSheetColumns === 'all' ? new Set() : defaultHidden(state.columns))
        return
    }

    if (e.target.closest('[data-ak-sheet-clear]')) {
        state.filters.clear()
        state.search = ''
        const input = state.root.querySelector('[data-ak-sheet-search]')
        if (input) input.value = ''
        render(state)
        return
    }

    const exportLink = e.target.closest('[data-ak-sheet-export]')
    if (exportLink) {
        e.preventDefault()
        exportSheet(state, exportLink)
    }
})

document.addEventListener('change', (e) => {
    if (!(e.target instanceof Element)) return
    const state = stateFor(e.target)
    if (!state) return

    if (e.target.matches('[data-ak-sheet-column]')) {
        const hidden = new Set(state.hidden)
        e.target.checked ? hidden.delete(e.target.dataset.akSheetColumn) : hidden.add(e.target.dataset.akSheetColumn)
        setHidden(state, hidden)
        return
    }

    if (e.target.closest('[data-ak-sheet-popover-list]')) applyPopover(state)
})

let searchTimer = null

document.addEventListener('input', (e) => {
    if (!(e.target instanceof Element)) return
    const state = stateFor(e.target)
    if (!state) return

    if (e.target.matches('[data-ak-sheet-search]')) {
        clearTimeout(searchTimer)
        searchTimer = setTimeout(() => {
            state.search = fold(e.target.value.trim())
            render(state)
        }, 150)
        return
    }

    if (e.target.matches('[data-ak-sheet-popover-search]')) {
        const q = fold(e.target.value.trim())
        popover(state).querySelectorAll('[data-ak-sheet-popover-item]').forEach((item) => {
            item.classList.toggle('hidden', q !== '' && !item.dataset.akSheetPopoverItem.includes(q))
        })
    }
})

document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return

    document.querySelectorAll('[data-ak-sheet]').forEach((root) => {
        const state = sheets.get(root)
        if (state?.popoverKey) closePopover(state)
    })
})

// The popover is `fixed` under the header that opened it; scrolling the grid
// would leave it pointing at the wrong column.
document.addEventListener('scroll', (e) => {
    if (!(e.target instanceof Element) || !e.target.matches('[data-ak-sheet-grid]')) return
    const state = stateFor(e.target)
    if (state?.popoverKey) closePopover(state)
}, true)
