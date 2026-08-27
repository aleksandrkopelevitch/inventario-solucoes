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

const ICON = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="5" rx="1"/><rect x="14" y="16" width="7" height="5" rx="1"/><path d="M6.5 8v5a3 3 0 0 0 3 3h8"/></svg>'

/** One fetch per page load, shared by every block on it. */
let catalogPromise = null

function loadCatalog(url) {
    if (!catalogPromise) {
        catalogPromise = fetch(url, {headers: {Accept: 'application/json'}})
            .then((r) => (r.ok ? r.json() : {groups: []}))
            .then((d) => d.groups || [])
            .catch(() => [])
    }

    return catalogPromise
}

export default class DiagramTool {
    static get toolbox() {
        return {title: 'Diagrama', icon: ICON}
    }

    constructor({data, config, api}) {
        this.api = api
        this.catalogUrl = config?.catalogUrl || ''
        this.data = {slug: data?.slug || ''}
        this.wrapper = null
    }

    render() {
        this.wrapper = document.createElement('div')
        this.wrapper.className = 'ak-doc-diagram-block my-3 rounded-card border border-line bg-surface'
        this.draw()

        return this.wrapper
    }

    draw() {
        this.wrapper.replaceChildren()

        const head = document.createElement('div')
        head.className = 'flex items-center gap-2 px-4 py-3'

        const label = document.createElement('span')
        label.className = 'min-w-0 flex-1 truncate text-sm'
        if (this.data.slug) {
            label.innerHTML = ''
            label.append(document.createTextNode(this.name || this.data.slug))
            label.classList.add('font-semibold', 'text-ink')
        } else {
            label.textContent = 'Nenhum diagrama escolhido'
            label.classList.add('italic', 'text-muted')
        }

        const pick = document.createElement('button')
        pick.type = 'button'
        pick.className = 'shrink-0 cursor-pointer rounded-field border border-line bg-surface px-2.5 py-1 text-xs font-medium text-ink transition-colors hover:border-accent-line hover:bg-accent-soft/40'
        pick.textContent = this.data.slug ? 'Trocar' : 'Escolher diagrama'
        pick.addEventListener('click', () => this.togglePicker())

        head.append(label, pick)
        this.wrapper.append(head)

        this.picker = document.createElement('div')
        this.picker.className = 'hidden border-t border-line p-2'
        this.wrapper.append(this.picker)
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
            const term = filter.value.trim().toLowerCase()

            list.querySelectorAll('[data-group]').forEach((node) => {
                const solution = node.dataset.group.toLowerCase()
                let any = false

                node.querySelectorAll('[data-diagram]').forEach((row) => {
                    const hit = !term || solution.includes(term) || row.dataset.diagram.toLowerCase().includes(term)
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
                this.name = diagram.name
                this.draw()
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
