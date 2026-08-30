// Editor.js "Diagrama" tool — a CITATION of a drawing from the catalog.
// Serializes to `{% diagram slug="…" %}` (see docs-markdown.js), which
// GitbookRenderer turns into the drawing's current picture plus a link that
// opens the canvas in a new tab.
//
// The block stores a SLUG and nothing else. It is what the author picked and
// what the diagram's URL shows, it survives a database reload between
// environments, and a citation whose diagram was deleted still says something
// legible. Everything else — the name, the picture — is resolved at render
// time, so a renamed or redrawn diagram updates every page citing it without
// anybody editing prose.
//
// The block PREVIEWS that render rather than describing it: the same picture,
// the same name, the same three states (picture / no picture yet / removed) the
// reader will get. It used to show the raw slug and a "Trocar" button, so an
// author citing a drawing had no way to tell from the editor whether they had
// picked the right one — and a page reopened later read as
// `zfl-bloq-desbloq-cliente`, since the name was only ever known in the session
// that picked it.

import {fold} from '../fold.js'

const ICON = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="5" rx="1"/><rect x="14" y="16" width="7" height="5" rx="1"/><path d="M6.5 8v5a3 3 0 0 0 3 3h8"/></svg>'

const EXTERNAL_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="size-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>'

const IMAGE_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="size-4 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M18 15h.008"/></svg>'

/** One fetch per page load, shared by every block on it. */
let catalogPromise = null

/** slug → the catalog's own row for it, so a saved block can resolve itself. */
const bySlug = new Map()

// An empty catalog and an unreachable one look identical from `bySlug`, and
// they must not read the same: "não encontrei o catálogo" is a fact about the
// request, while "Diagrama removido" is a claim about the author's citation.
// Getting that wrong tells someone their drawing was deleted because a fetch
// failed.
let catalogFailed = false

function loadCatalog(url) {
    if (!catalogPromise) {
        catalogPromise = fetch(url, {headers: {Accept: 'application/json'}})
            .then((r) => {
                if (!r.ok) throw new Error(`catalog ${r.status}`)

                return r.json()
            })
            .then((d) => d.groups || [])
            .then((groups) => {
                // A diagram appears once per participating solution, so this
                // deliberately overwrites: every copy is the same row.
                groups.forEach((g) => g.diagrams.forEach((d) => bySlug.set(d.slug, d)))

                return groups
            })
            .catch(() => {
                catalogFailed = true

                return []
            })
    }

    return catalogPromise
}

export default class DiagramTool {
    static get toolbox() {
        return {title: 'Diagrama', icon: ICON}
    }

    constructor({data, config, api, block}) {
        this.api = api
        // Editor.js notices a block changed by watching its DOM, and this one
        // rewrites its own the moment the catalog answers — an async paint that
        // nobody asked for would mark the page dirty and hand it to autosave.
        // The wrapper is `data-mutation-free`, so the only change this tool
        // reports is the one it dispatches itself: picking a diagram.
        this.block = block
        this.catalogUrl = config?.catalogUrl || ''
        this.data = {slug: data?.slug || ''}
        this.entry = null
        this.resolved = false
        this.wrapper = null
    }

    render() {
        this.wrapper = document.createElement('div')
        this.wrapper.className = 'ak-doc-diagram-block my-3 overflow-hidden rounded-card border border-line bg-surface'
        this.wrapper.dataset.mutationFree = 'true'
        this.draw()
        this.resolve()

        return this.wrapper
    }

    /** Fills in name + picture for a slug this session didn't pick itself. */
    async resolve() {
        if (!this.data.slug || this.resolved) return

        await loadCatalog(this.catalogUrl)
        this.entry = bySlug.get(this.data.slug) ?? null
        this.resolved = true
        this.draw()
    }

    draw() {
        this.wrapper.replaceChildren()

        if (this.data.slug) this.wrapper.append(this.renderPreview())
        this.wrapper.append(this.renderBar())

        this.picker = document.createElement('div')
        this.picker.className = 'hidden border-t border-line p-2'
        this.wrapper.append(this.picker)
    }

    /** The three states GitbookRenderer draws, drawn the same way. */
    renderPreview() {
        if (!this.resolved) {
            return this.strip(IMAGE_ICON, 'Carregando pré-visualização…')
        }

        if (!this.entry) {
            return catalogFailed
                ? this.strip(IMAGE_ICON, 'Não consegui carregar o catálogo — recarregue a página para ver a prévia.', true)
                : this.strip(IMAGE_ICON, `Diagrama removido (${this.data.slug}).`, true)
        }

        if (!this.entry.pictureUrl) {
            return this.strip(IMAGE_ICON, 'Sem imagem ainda — abra o diagrama e salve o layout para gerar uma.', true)
        }

        const img = document.createElement('img')
        img.src = this.entry.pictureUrl
        img.alt = this.entry.name
        img.loading = 'lazy'
        // Capped, unlike the reader's copy: a tall flow drawn end to end would
        // push "Trocar" and the picker under the fold of the block being
        // edited. `object-contain` keeps it honest at that height.
        img.className = 'block max-h-[26rem] w-full border-b border-line bg-white object-contain'

        return img
    }

    strip(icon, text, dashed = false) {
        const el = document.createElement('div')
        el.className = `flex items-center gap-2 border-b px-4 py-6 text-xs text-muted ${dashed ? 'border-dashed border-line' : 'border-line'}`
        el.innerHTML = icon
        el.append(document.createTextNode(text))

        return el
    }

    renderBar() {
        const bar = document.createElement('div')
        bar.className = 'flex items-center gap-2 px-4 py-3'

        const label = document.createElement('span')
        label.className = 'min-w-0 flex-1 truncate text-sm'
        if (this.data.slug) {
            label.append(document.createTextNode(this.entry?.name || this.data.slug))
            label.classList.add('font-semibold', 'text-ink')
        } else {
            label.textContent = 'Nenhum diagrama escolhido'
            label.classList.add('italic', 'text-muted')
        }
        bar.append(label)

        // Same escape hatch the reader's card offers, and for the same reason:
        // the canvas is a full-screen editor, so it opens in a new tab rather
        // than costing the author the page they are writing.
        if (this.entry?.url) {
            const open = document.createElement('a')
            open.href = this.entry.url
            open.target = '_blank'
            open.rel = 'noopener'
            open.title = 'Abrir diagrama em uma nova aba'
            open.className = 'shrink-0 rounded-field border border-line px-2 py-1 text-xs text-muted no-underline transition-colors hover:border-accent-line hover:bg-accent-soft/40 hover:text-ink'
            open.innerHTML = EXTERNAL_ICON
            bar.append(open)
        }

        const pick = document.createElement('button')
        pick.type = 'button'
        pick.className = 'shrink-0 cursor-pointer rounded-field border border-line bg-surface px-2.5 py-1 text-xs font-medium text-ink transition-colors hover:border-accent-line hover:bg-accent-soft/40'
        pick.textContent = this.data.slug ? 'Trocar' : 'Escolher diagrama'
        pick.addEventListener('click', () => this.togglePicker())
        bar.append(pick)

        return bar
    }

    async togglePicker() {
        if (!this.picker.classList.contains('hidden')) {
            this.picker.classList.add('hidden')

            return
        }

        this.picker.classList.remove('hidden')

        if (this.picker.dataset.built) return
        this.picker.dataset.built = '1'
        this.picker.textContent = 'Carregando…'

        const groups = await loadCatalog(this.catalogUrl)
        this.picker.replaceChildren()

        if (!groups.length) {
            this.picker.textContent = 'Nenhum diagrama no catálogo.'

            return
        }

        const filter = document.createElement('input')
        filter.type = 'search'
        filter.placeholder = 'Filtrar por diagrama ou solução…'
        filter.className = 'mb-2 w-full rounded-field border border-line-2 px-2.5 py-1.5 text-xs'
        this.picker.append(filter)

        const list = document.createElement('div')
        list.className = 'max-h-64 overflow-y-auto'
        this.picker.append(list)

        groups.forEach((group) => list.append(this.renderGroup(group)))

        // Filtering in the browser, not over the wire: the catalog is tens of
        // rows, and a group whose name matches keeps all of its diagrams.
        filter.addEventListener('input', () => {
            const term = fold(filter.value.trim())

            list.querySelectorAll('[data-group]').forEach((node) => {
                const solution = fold(node.dataset.group)
                let any = false

                node.querySelectorAll('[data-diagram]').forEach((row) => {
                    const hit = !term || solution.includes(term) || fold(row.dataset.diagram).includes(term)
                    row.classList.toggle('hidden', !hit)
                    any = any || hit
                })

                node.classList.toggle('hidden', !any)
                // A matching filter opens what it matched — a hit hidden inside
                // a collapsed group reads as no hit at all.
                if (term && any) node.querySelector('[data-children]')?.classList.remove('hidden')
            })
        })
    }

    renderGroup(group) {
        const wrap = document.createElement('div')
        wrap.dataset.group = group.solution
        wrap.className = 'mb-1'

        const children = document.createElement('div')
        children.dataset.children = '1'
        children.className = 'hidden pl-4'

        const toggle = document.createElement('button')
        toggle.type = 'button'
        toggle.className = 'flex w-full cursor-pointer items-center gap-1.5 rounded px-2 py-1.5 text-left text-xs font-semibold text-ink hover:bg-raised'
        toggle.innerHTML = '<span class="chev inline-block transition-transform">&rsaquo;</span>'
        toggle.append(document.createTextNode(` ${group.solution} (${group.diagrams.length})`))
        toggle.addEventListener('click', () => {
            const open = children.classList.toggle('hidden')
            toggle.querySelector('.chev').style.rotate = open ? '0deg' : '90deg'
        })

        group.diagrams.forEach((diagram) => {
            const row = document.createElement('button')
            row.type = 'button'
            row.dataset.diagram = diagram.name
            row.className = 'block w-full cursor-pointer truncate rounded px-2 py-1.5 text-left text-xs text-body hover:bg-raised hover:text-ink'
            row.textContent = diagram.name
            row.addEventListener('click', () => {
                this.data.slug = diagram.slug
                this.entry = diagram
                this.resolved = true
                this.draw()
                // The one real change this block makes. Everything else it does
                // to its own DOM is filtered out by `data-mutation-free`, so
                // without this the new citation would sit there unsaved.
                this.block?.dispatchChange()
            })
            children.append(row)
        })

        wrap.append(toggle, children)

        return wrap
    }

    save() {
        return {slug: this.data.slug}
    }

    static get sanitize() {
        return {slug: false}
    }

    /** A citation with no diagram chosen would serialize to an empty block. */
    validate(data) {
        return Boolean(data.slug)
    }
}
