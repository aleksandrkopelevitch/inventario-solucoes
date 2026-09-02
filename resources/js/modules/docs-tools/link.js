// Editor.js "Link" inline tool — REPLACES the built-in one (docs-editor.js
// registers it under the `link` name, which is how Editor.js lets an internal
// tool be overridden).
//
// It exists because a link in this app has two kinds and the built-in tool only
// knows one:
//
//   - an ABSOLUTE url somebody types or pastes (https://…, mailto:…), exactly
//     as before — same input, same Enter, same CMD+K;
//   - a reference INSIDE the caderno: another page, or one of its H1–H3
//     headings, or a heading of the page being written.
//
// The second is why the URL cannot be typed. The same page is reachable at two
// unrelated addresses — `notebooks/{caderno}/{página}` for someone signed in and
// `public-docs/{token}/page/{página}` for a visitor holding the magic link — so
// an address written into the Markdown is correct for exactly one audience. The
// tool writes `[texto](page:{slug}#anchor)` instead and lets the renderer
// resolve it per reader (App\Support\Documentation\PageLinks). A heading in the
// CURRENT page is written as a plain `#anchor`, which needs no resolving at all
// and is what makes it work identically in the editor's own reader and on a
// shared link.
//
// The anchors are NOT derived here. They are read out of the caderno's rendered
// HTML server-side (`notebooks.link-targets`, which is a reading of the search
// index) — re-implementing commonmark's slug normalizer in the browser drifts
// the moment two headings on a page collide and it starts suffixing `-1`, and a
// drifted anchor fails silently: the right page at the wrong place.
//
// Two mechanics worth knowing before touching this:
//
//   - **The selection is captured as a cloned Range and the link is inserted
//     with DOM calls, not `execCommand`.** The picker is a modal, so by the
//     time somebody chooses a heading the editor has lost focus and the inline
//     toolbar has closed — and `document.execCommand('createLink')` acts on the
//     live selection, which no longer exists. A cloned Range survives all of
//     that because nothing in between touches the block's DOM.
//   - **There is no "fake background".** The built-in tool highlights the
//     selection with `execCommand('hiliteColor')` while its input is open, and
//     removing that highlight again rewrites the very text nodes the Range
//     points at. It also needs two saved selections to undo itself. Skipping it
//     costs a visual cue and buys a Range that cannot be invalidated behind our
//     back.

import {fold} from '../fold.js'

const LINK_ICON = '<svg width="14" height="10" viewBox="0 0 14 10" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M5.5 5h3M4.5 1.5H3.2A2.2 2.2 0 0 0 1 3.7v2.6a2.2 2.2 0 0 0 2.2 2.2h1.3M9.5 1.5h1.3A2.2 2.2 0 0 1 13 3.7v2.6a2.2 2.2 0 0 1-2.2 2.2H9.5"/></svg>'

const CHEVRON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-3 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>'

/**
 * The caderno's link targets, refetched when the cached copy is older than
 * this.
 *
 * It is a short TTL rather than a one-shot fetch (the diagram catalog's shape)
 * because the thing being listed is the page somebody is WRITING: autosave
 * commits a new heading about a second after it is typed, and a picker that
 * cached the catalog on first open would then refuse to offer it for the rest
 * of the session. Cheap to refresh — the endpoint answers from a content-hashed
 * cache.
 */
const TARGETS_TTL = 15000

let targetsPromise = null
let targetsFetchedAt = 0
let targetsFailed = false

function loadTargets(url) {
    if (!url) return Promise.resolve([])

    if (!targetsPromise || Date.now() - targetsFetchedAt > TARGETS_TTL) {
        targetsFetchedAt = Date.now()
        targetsFailed = false
        targetsPromise = fetch(url, {headers: {Accept: 'application/json'}})
            .then((r) => {
                if (!r.ok) throw new Error(`link targets ${r.status}`)

                return r.json()
            })
            .then((d) => d.pages || [])
            .catch(() => {
                targetsFailed = true
                // Not cached: a failed fetch must not become the answer for the
                // next fifteen seconds.
                targetsPromise = null
                targetsFetchedAt = 0

                return []
            })
    }

    return targetsPromise
}

/**
 * What goes in the `href`.
 *
 * Mirrors the built-in tool's `addProtocol()` — anything already carrying a
 * scheme (`https:`, `mailto:`, and `page:` with it) or starting with `#`, `/`
 * or `//` is left exactly as typed; a bare `example.com` gets a protocol. The
 * one difference is which protocol: `https`, not the built-in's `http`.
 */
function normalizeUrl(raw) {
    const value = String(raw ?? '').trim()

    if (!value) return ''
    if (/^[a-z][a-z\d+.-]*:/i.test(value)) return value
    if (value.startsWith('#')) return value
    if (/^\/\/?[^/\s]/.test(value)) return value

    return `https://${value}`
}

export default class DocsLinkTool {
    static get isInline() {
        return true
    }

    static get title() {
        return 'Link'
    }

    /**
     * `href` only. The built-in tool also whitelists `target`/`rel`, which it
     * never writes — and an attribute the serializer does not carry
     * (docs-markdown.js writes `[texto](href)`) would be lost on the next save
     * anyway, so promising it here would be a promise the round trip breaks.
     */
    static get sanitize() {
        return {a: {href: true}}
    }

    constructor({api, config}) {
        this.api = api
        this.targetsUrl = config?.linkTargetsUrl || ''
        this.pageSlug = config?.pageSlug || ''
        this.nodes = {button: null, wrapper: null, input: null, remove: null}
        // The selection the link will wrap, held as a clone — see the note at
        // the top of this file.
        this.range = null
        this.anchor = null
        this.opened = false
    }

    get shortcut() {
        return 'CMD+K'
    }

    render() {
        this.nodes.button = document.createElement('button')
        this.nodes.button.type = 'button'
        this.nodes.button.classList.add('ce-inline-tool', 'ce-inline-tool--link')
        this.nodes.button.innerHTML = LINK_ICON

        return this.nodes.button
    }

    renderActions() {
        // Editor.js rebuilds the inline toolbar's popover on every open, so this
        // runs again each time and the panel is born closed — which is why
        // `opened` is reset HERE rather than in `clear()`: the flag has to
        // describe the wrapper that exists now, not the one from last time.
        this.opened = false

        const wrapper = document.createElement('div')
        wrapper.className = 'ak-link-actions hidden w-[19rem] max-w-[78vw] pt-1'

        const input = document.createElement('input')
        input.type = 'text'
        input.placeholder = 'https://… ou escolha no caderno'
        input.className =
            'w-full rounded-field border border-line-2 bg-surface px-2.5 py-1.5 text-sm text-ink outline-none focus:border-accent'
        input.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return
            e.preventDefault()
            e.stopPropagation()
            const value = input.value.trim()
            if (value) this.apply(value)
            else this.unlink()
        })
        // The inline toolbar closes on Escape and on selection changes; a
        // click inside its own actions must not be read as either.
        input.addEventListener('mousedown', (e) => e.stopPropagation())

        const row = document.createElement('div')
        row.className = 'mt-1.5 flex items-center gap-1.5'

        const pick = document.createElement('button')
        pick.type = 'button'
        pick.className =
            'flex-1 cursor-pointer rounded-field border border-line bg-surface px-2.5 py-1 text-xs font-medium text-ink transition-colors hover:border-accent-line hover:bg-accent-soft/40'
        pick.textContent = 'Escolher página ou seção…'
        pick.addEventListener('click', (e) => {
            e.preventDefault()
            this.openPicker()
        })
        row.append(pick)

        const remove = document.createElement('button')
        remove.type = 'button'
        remove.className =
            'hidden shrink-0 cursor-pointer rounded-field border border-line px-2.5 py-1 text-xs font-medium text-muted transition-colors hover:border-crit hover:bg-crit-soft hover:text-crit'
        remove.textContent = 'Remover'
        remove.addEventListener('click', (e) => {
            e.preventDefault()
            this.unlink()
        })
        row.append(remove)

        wrapper.append(input, row)

        this.nodes.wrapper = wrapper
        this.nodes.input = input
        this.nodes.remove = remove

        return wrapper
    }

    /** Clicking the toolbar button (or CMD+K). */
    surround(range) {
        if (range) this.range = range.cloneRange()

        this.anchor = this.api.selection.findParentTag('A')

        // Deliberately NOT the built-in behaviour, which unlinks on click. The
        // href is the thing you most often want to SEE, and an accidental click
        // that silently drops a link is worse than one that opens a panel with
        // "Remover" in it.
        if (this.anchor) {
            this.openActions(true)

            return
        }

        if (this.opened) this.closeActions()
        else this.openActions(true)
    }

    /** Editor.js asks on every selection change while the toolbar is open. */
    checkState() {
        // The most reliable moment to capture the selection: this runs while
        // the toolbar is being built, i.e. while the user's own selection is
        // still the document's.
        const selection = window.getSelection()
        if (selection && selection.rangeCount > 0 && !this.opened) {
            this.range = selection.getRangeAt(0).cloneRange()
        }

        this.anchor = this.api.selection.findParentTag('A')

        if (this.anchor) {
            this.nodes.button?.classList.add('ce-inline-tool--active')
            // Only while the panel is closed — filling it on every selection
            // change would overwrite whatever is being typed into it.
            if (!this.opened && this.nodes.input) {
                this.nodes.input.value = this.anchor.getAttribute('href') || ''
            }
            this.nodes.remove?.classList.remove('hidden')
            // Returning true is what makes Editor.js open the nested popover
            // holding these actions, so the panel has to be shown with it or
            // that popover opens EMPTY — which is what happened until this line
            // existed. Not focused: the caret belongs to the text, and the
            // person may only be checking where the link goes.
            this.openActions()

            return true
        }

        this.nodes.button?.classList.remove('ce-inline-tool--active')
        this.nodes.remove?.classList.add('hidden')

        return false
    }

    /** Called by Editor.js when the inline toolbar closes. */
    clear() {
        this.closeActions()
    }

    openActions(focus = false) {
        this.nodes.wrapper?.classList.remove('hidden')
        this.opened = true
        if (focus) this.nodes.input?.focus()
    }

    closeActions() {
        this.nodes.wrapper?.classList.add('hidden')
        this.opened = false
        // The Range is deliberately kept: the picker is a modal, so it is still
        // needed after the toolbar has closed (see the note at the top).
    }

    /* -------------------------------------------------------------- */
    /*  Writing the link                                               */
    /* -------------------------------------------------------------- */

    /**
     * @param {string} url   the destination, before normalizing
     * @param {string} label text to insert when nothing is selected
     */
    apply(url, label = '') {
        const href = normalizeUrl(url)
        if (!href) return

        if (this.anchor) {
            this.anchor.setAttribute('href', href)
            this.finish()

            return
        }

        const range = this.range
        if (!range) {
            window.Toast?.show('Selecione o texto que deve virar link.', 'warning')

            return
        }

        const anchor = document.createElement('a')
        anchor.setAttribute('href', href)

        try {
            if (range.collapsed) {
                // Nothing selected: the link brings its own text. The built-in
                // tool does nothing at all here, which reads as a broken button
                // — and when the destination came from the picker there IS an
                // obvious label to use, the page or heading that was chosen.
                anchor.textContent = label || href
                range.insertNode(anchor)
            } else {
                try {
                    range.surroundContents(anchor)
                } catch (_) {
                    // A selection that only partially contains an element can't
                    // be surrounded — extract and re-wrap instead.
                    anchor.appendChild(range.extractContents())
                    range.insertNode(anchor)
                }
            }
        } catch (_) {
            window.Toast?.open({content: 'Não consegui inserir o link — selecione o texto novamente.', title: 'Atenção', type: 'warning'})

            return
        }

        this.caretAfter(anchor)
        this.finish()
    }

    unlink() {
        const anchor = this.anchor
        if (anchor?.parentNode) {
            const parent = anchor.parentNode
            while (anchor.firstChild) parent.insertBefore(anchor.firstChild, anchor)
            parent.removeChild(anchor)
            parent.normalize()
        }

        this.anchor = null
        this.finish()
    }

    caretAfter(node) {
        const selection = window.getSelection()
        if (!selection) return

        const range = document.createRange()
        range.setStartAfter(node)
        range.collapse(true)
        selection.removeAllRanges()
        selection.addRange(range)
    }

    finish() {
        this.range = null
        this.closeActions()
        this.api.inlineToolbar?.close()
    }

    /* -------------------------------------------------------------- */
    /*  The picker                                                     */
    /* -------------------------------------------------------------- */

    openPicker() {
        const modal = document.getElementById('main-modal')
        if (!modal || !window.Modal) {
            window.Toast?.show('Não consegui abrir o seletor de páginas.', 'warning')

            return
        }

        const shell = document.createElement('div')
        shell.className = 'flex max-h-[82vh] flex-col'

        const header = document.createElement('div')
        header.className = 'border-b border-line px-6 py-4'
        header.innerHTML =
            '<h2 class="text-base font-bold text-ink">Link para uma página ou seção</h2>' +
            '<p class="mt-0.5 text-xs text-muted">Do caderno atual. O endereço é resolvido na hora de ler, ' +
            'então o mesmo link funciona no app e no link público.</p>'

        const search = document.createElement('input')
        search.type = 'search'
        search.placeholder = 'Filtrar por página ou título de seção…'
        search.className =
            'mx-6 mt-4 rounded-field border border-line-2 bg-surface px-3 py-2 text-sm text-ink outline-none focus:border-accent'

        const list = document.createElement('div')
        list.className = 'mt-3 min-h-0 flex-1 overflow-y-auto px-4 pb-4'
        list.textContent = 'Carregando…'

        shell.append(header, search, list)
        modal.querySelector('[data-content]').replaceChildren(shell)
        modal.querySelector('[data-loading]')?.classList.add('hidden')
        window.Modal.open('main-modal', true)

        loadTargets(this.targetsUrl).then((pages) => {
            list.replaceChildren()

            if (!pages.length) {
                list.className = 'px-6 pb-6 pt-2 text-sm text-muted'
                // An unreachable catalog and an empty caderno must not read the
                // same — the first is a fact about the request, the second a
                // fact about the documentation.
                list.textContent = targetsFailed
                    ? 'Não consegui carregar as páginas do caderno. Tente de novo em instantes.'
                    : 'Este caderno ainda não tem outra página com conteúdo.'

                return
            }

            pages.forEach((page) => list.append(this.renderPageGroup(page)))
            search.focus()
        })

        search.addEventListener('input', () => {
            const term = fold(search.value.trim())

            list.querySelectorAll('[data-ak-link-page]').forEach((group) => {
                const pageHit = !term || fold(group.dataset.akLinkPage).includes(term)
                let any = pageHit

                group.querySelectorAll('[data-ak-link-heading]').forEach((row) => {
                    const hit = pageHit || fold(row.dataset.akLinkHeading).includes(term)
                    row.classList.toggle('hidden', !hit)
                    any = any || hit
                })

                // The page's own row stays visible whenever anything under it
                // matched: it is what says which page that heading belongs to.
                group.classList.toggle('hidden', !any)
                group.querySelector('[data-ak-link-page-row]')?.classList.toggle('hidden', !pageHit && !any)
            })
        })
    }

    /** One page: a row for its top, then a row per H1–H3 it contains. */
    renderPageGroup(page) {
        const isCurrent = page.slug === this.pageSlug

        const group = document.createElement('div')
        group.dataset.akLinkPage = page.title
        group.className = 'mb-2'

        const title = document.createElement('div')
        title.className = 'flex items-baseline gap-2 px-2 pb-1 pt-2'
        title.innerHTML =
            '<span class="truncate text-xs font-bold uppercase tracking-wide text-muted">' +
            (isCurrent ? 'Nesta página' : escapeText(page.title)) +
            '</span>'
        if (isCurrent) {
            const name = document.createElement('span')
            name.className = 'truncate text-xs text-faint'
            name.textContent = page.title
            title.append(name)
        } else if (page.trail?.length) {
            const trail = document.createElement('span')
            trail.className = 'truncate text-xs text-faint'
            trail.textContent = page.trail.join(' › ')
            title.append(trail)
        }
        group.append(title)

        // The page's own top. Not offered for the page being written — a link
        // from a page to itself is a no-op, and offering it invites one.
        if (!isCurrent) {
            const row = this.renderRow(`Abrir “${page.title}”`, `page:${page.slug}`, page.title, 0)
            row.dataset.akLinkPageRow = '1'
            group.append(row)
        }

        ;(page.headings || []).forEach((heading) => {
            const destination = isCurrent ? `#${heading.anchor}` : `page:${page.slug}#${heading.anchor}`
            const row = this.renderRow(heading.text, destination, heading.text, heading.level)
            row.dataset.akLinkHeading = heading.text
            group.append(row)
        })

        return group
    }

    /** `level` 0 = the page itself, 1..3 = an H1–H3 inside it. */
    renderRow(text, destination, label, level) {
        const indent = ['pl-2', 'pl-2', 'pl-6', 'pl-10'][level] ?? 'pl-10'

        const row = document.createElement('button')
        row.type = 'button'
        row.className =
            `flex w-full cursor-pointer items-center gap-1.5 rounded-field ${indent} pr-2 py-1.5 text-left text-sm text-body transition-colors hover:bg-raised hover:text-ink`
        row.innerHTML = `<span class="text-faint">${CHEVRON}</span>`

        const span = document.createElement('span')
        span.className = 'min-w-0 flex-1 truncate'
        span.textContent = text
        row.append(span)

        if (level > 0) {
            const badge = document.createElement('span')
            badge.className = 'shrink-0 rounded bg-raised px-1.5 py-0.5 font-mono text-[10px] text-muted'
            badge.textContent = `H${level}`
            row.append(badge)
        }

        row.addEventListener('click', () => {
            window.Modal?.close('main-modal')
            this.apply(destination, label)
        })

        return row
    }
}

function escapeText(value) {
    const el = document.createElement('span')
    el.textContent = String(value ?? '')

    return el.innerHTML
}
