// Editor.js "Cards" tool — a regular grid of product cards: a logo, a name, an
// optional line of description and a destination.
//
// Serializes to `{% cards cols="3" %}{% card title="…" image="/files/12"
// fit="cover" link="notebook:…" %}descrição{% endcard %}…{% endcards %}` (see
// docs-markdown.js), which GitbookRenderer paints as the same grid for the
// reader.
//
// Three things about it are deliberate:
//
// - **Every card in a grid is the same size**, so the frame around the logo is
//   fixed and it is the LOGO that adapts: `fit` is per card, because a
//   wordmark, a small square icon and a screenshot each need a different answer
//   to the same box (see cards-format.js).
// - **The card is the link**, so nothing inside it is rich text. A link inside
//   a link is not valid HTML, and the inline toolbar's first offer is "Link" —
//   so title and description are plain text, saved and rendered as such.
// - **The destination is stored as a REFERENCE, not a URL** —
//   `notebook:{slug}` or `page:{slug}` — for the same reason an internal link
//   is (App\Support\Documentation\PageLinks): the same caderno has a different
//   address in the editor, in `/docs` and on a magic link, so an address
//   written into the Markdown would be correct for exactly one audience. A
//   typed `https://…` is stored as itself.
//
// Depends on config injected by docs-editor.js:
//   uploadUrl       — where a logo is POSTed (same endpoint as the Image tool)
//   cardTargetsUrl  — the catalog the destination picker offers

import {fold} from '../fold.js'
import {
    CARD_COLUMNS,
    CARD_FITS,
    CARD_FIT_LABELS,
    DEFAULT_CARD_COLUMNS,
    DEFAULT_CARD_FIT,
} from './cards-format.js'

const ICON = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>'

const IMAGE_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="ak-card__placeholder-icon"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M18 15h.008"/></svg>'

const LINK_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="ak-card__link-icon"><path stroke-linecap="round" d="M9.5 14.5 14.5 9.5M10 6.5h1.8A3.2 3.2 0 0 1 15 9.7v0a3.2 3.2 0 0 1-3.2 3.2H10M14 17.5h-1.8A3.2 3.2 0 0 1 9 14.3v0a3.2 3.2 0 0 1 3.2-3.2H14"/></svg>'

/** The catalog of everything a card may point at — one fetch per page load. */
let targetsPromise = null

/** Lookups a saved card resolves its own label against. */
const notebookBySlug = new Map()
const pageBySlug = new Map()

// An empty catalog and an unreachable one must not read the same, for the
// reason diagram.js states: "não encontrei o catálogo" is a fact about the
// request, while "caderno removido" is a claim about what the author picked.
let targetsFailed = false

function loadTargets(url) {
    if (!url) return Promise.resolve({notebooks: [], pages: []})

    if (!targetsPromise) {
        targetsPromise = fetch(url, {headers: {Accept: 'application/json'}})
            .then((r) => {
                if (!r.ok) throw new Error(`card targets ${r.status}`)

                return r.json()
            })
            .then((d) => {
                const data = {notebooks: d.notebooks || [], pages: d.pages || []}
                data.notebooks.forEach((n) => notebookBySlug.set(n.slug, n))
                data.pages.forEach((p) => pageBySlug.set(p.slug, p))

                return data
            })
            .catch(() => {
                targetsFailed = true
                targetsPromise = null

                return {notebooks: [], pages: []}
            })
    }

    return targetsPromise
}

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
}

/**
 * Uploads a logo through the same endpoint the Image tool uses, and answers
 * with the `/files/{id}` path that goes into the notation — never the absolute
 * url the endpoint returns, which is the AUTHENTICATED route and would break
 * on the magic link (`DocumentationReader::rewriteAssetUrls` rewrites the
 * root-relative form and only that).
 */
async function uploadLogo(url, file) {
    const body = new FormData()
    body.append('file', file)

    const response = await fetch(url, {
        method: 'POST',
        headers: {'X-CSRF-TOKEN': csrf(), Accept: 'application/json'},
        body,
    })

    const data = await response.json().catch(() => null)

    if (!response.ok || !data?.success || !data?.file?.mediaId) {
        throw new Error('upload failed')
    }

    return `/files/${data.file.mediaId}`
}

/** Icon for the block tunes: N bars, so the option shows the layout it picks. */
function columnsIcon(count) {
    const gap = 2
    const width = (20 - gap * (count - 1)) / count
    const bars = Array.from(
        {length: count},
        (_, i) => `<rect x="${(2 + i * (width + gap)).toFixed(1)}" y="5" width="${width.toFixed(1)}" height="10" rx="1.2" fill="currentColor"/>`,
    ).join('')

    return `<svg width="20" height="20" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">${bars}</svg>`
}

export default class CardsTool {
    static get toolbox() {
        return {title: 'Cards', icon: ICON}
    }

    /** Enter inside a card's text must never split the block into two. */
    static get enableLineBreaks() {
        return true
    }

    /** An empty grid is still a grid the author is filling in. */
    static get contentless() {
        return false
    }

    constructor({data, config, block}) {
        this.config = config || {}
        this.block = block

        const cols = Number(data?.cols)
        this.cols = CARD_COLUMNS.includes(cols) ? cols : DEFAULT_CARD_COLUMNS

        const items = Array.isArray(data?.items) ? data.items : []
        this.seed = (items.length ? items : [{}]).map((item) => ({
            title: String(item.title || ''),
            description: String(item.description || ''),
            image: String(item.image || ''),
            fit: CARD_FITS.includes(item.fit) ? item.fit : DEFAULT_CARD_FIT,
            link: String(item.link || ''),
        }))

        this.cards = []
        this.picking = null
        this.dragFrom = null
    }

    render() {
        this.wrapper = document.createElement('div')
        this.wrapper.className = 'ak-cards'
        this.wrapper.dataset.cols = String(this.cols)

        this.grid = document.createElement('div')
        this.grid.className = 'ak-cards__grid'

        this.addBtn = document.createElement('button')
        this.addBtn.type = 'button'
        this.addBtn.className = 'ak-cards__add'
        this.addBtn.innerHTML = '<span aria-hidden="true">+</span>'
        this.addBtn.append(document.createTextNode(' Adicionar card'))
        this.addBtn.addEventListener('click', () => {
            const card = this.buildCard({fit: DEFAULT_CARD_FIT})
            card.title.focus()
            this.changed()
        })

        this.picker = document.createElement('div')
        this.picker.className = 'ak-cards__picker hidden'
        // Rewritten from a fetch nobody asked for (the catalog answering), and
        // opened/closed by clicks that are not content: every change to it is
        // either irrelevant to the document or already reported by hand.
        this.picker.dataset.mutationFree = 'true'

        // The ghost card goes in FIRST: every card is inserted before it
        // (`buildCard`), so it has to already be in the grid.
        this.grid.append(this.addBtn)
        this.wrapper.append(this.grid, this.picker)
        this.seed.forEach((item) => this.buildCard(item))

        // Fills in the labels of destinations this session did not pick itself.
        this.resolveLabels()

        return this.wrapper
    }

    renderSettings() {
        return CARD_COLUMNS.map((count) => ({
            icon: columnsIcon(count),
            label: `${count} por linha`,
            name: `ak-cards-cols-${count}`,
            toggle: 'ak-cards-cols',
            isActive: this.cols === count,
            onActivate: () => {
                this.cols = count
                this.wrapper.dataset.cols = String(count)
                this.changed()
            },
        }))
    }

    /* ---------------------------------------------------------------- */
    /*  One card                                                        */
    /* ---------------------------------------------------------------- */

    buildCard(item) {
        const card = {
            fitValue: CARD_FITS.includes(item.fit) ? item.fit : DEFAULT_CARD_FIT,
            image: item.image || '',
            link: item.link || '',
        }

        const el = document.createElement('div')
        el.className = 'ak-card'
        card.el = el

        el.append(this.buildMedia(card), this.buildBody(card, item), this.buildFoot(card))

        this.grid.insertBefore(el, this.addBtn)
        this.cards.push(card)

        return card
    }

    buildMedia(card) {
        const media = document.createElement('div')
        media.className = 'ak-card__media'
        media.dataset.fit = card.fitValue
        media.title = 'Clique para enviar o logo (ou arraste um arquivo aqui)'
        // Editor.js decides a block changed by watching its DOM, and this frame
        // repaints for reasons that are not edits: an upload's "Enviando…", the
        // outline while a file is dragged over it. Both would mark the page
        // dirty and hand it to autosave — dragging a file ACROSS the editor on
        // the way somewhere else would be enough. The real changes (a logo
        // arrived, a logo was removed, the fit changed) are dispatched by hand
        // below. It can carry this and the card cannot, because the frame holds
        // no contenteditable: typing reaches Editor.js only through the
        // MutationObserver this attribute filters.
        media.dataset.mutationFree = 'true'
        card.media = media

        const file = document.createElement('input')
        file.type = 'file'
        file.accept = 'image/*'
        file.className = 'hidden'
        file.addEventListener('change', () => {
            if (file.files?.[0]) this.setImage(card, file.files[0])
            file.value = ''
        })

        media.addEventListener('click', (e) => {
            if (e.target.closest('[data-card-image-remove]')) return
            file.click()
        })

        // Dropping straight onto the frame, which is where somebody aims.
        media.addEventListener('dragover', (e) => {
            if (!e.dataTransfer?.types?.includes('Files')) return
            e.preventDefault()
            media.classList.add('is-dropping')
        })
        media.addEventListener('dragleave', () => media.classList.remove('is-dropping'))
        media.addEventListener('drop', (e) => {
            if (!e.dataTransfer?.files?.length) return
            e.preventDefault()
            e.stopPropagation()
            media.classList.remove('is-dropping')
            this.setImage(card, e.dataTransfer.files[0])
        })

        media.append(file)
        this.drawMedia(card)

        return media
    }

    /** The frame's three states: a logo, an upload in flight, or an invitation. */
    drawMedia(card) {
        card.media.querySelectorAll('[data-card-media-content]').forEach((node) => node.remove())
        card.media.dataset.fit = card.fitValue

        const content = document.createElement('div')
        content.dataset.cardMediaContent = '1'
        content.className = 'ak-card__media-content'

        if (card.uploading) {
            content.classList.add('ak-card__placeholder')
            content.textContent = 'Enviando…'
        } else if (card.image) {
            const img = document.createElement('img')
            img.src = card.image
            img.alt = ''
            content.append(img)

            const remove = document.createElement('button')
            remove.type = 'button'
            remove.dataset.cardImageRemove = '1'
            remove.className = 'ak-card__media-remove'
            remove.title = 'Remover logo'
            remove.innerHTML = '&times;'
            remove.addEventListener('click', (e) => {
                e.stopPropagation()
                card.image = ''
                this.drawMedia(card)
                this.changed()
            })
            content.append(remove)
        } else {
            content.classList.add('ak-card__placeholder')
            content.innerHTML = IMAGE_ICON
            content.append(document.createTextNode('Enviar logo'))
        }

        card.media.append(content)
    }

    async setImage(card, file) {
        if (!file || !this.config.uploadUrl) return

        card.uploading = true
        this.drawMedia(card)

        try {
            card.image = await uploadLogo(this.config.uploadUrl, file)
        } catch (_) {
            Toast.open({
                title: 'Atenção',
                content: 'Não foi possível enviar a imagem. Use JPG, PNG, GIF, WEBP ou SVG de até 20 MB.',
                type: 'warning',
            })
        } finally {
            card.uploading = false
            this.drawMedia(card)
        }

        this.changed()
    }

    buildBody(card, item) {
        const body = document.createElement('div')
        body.className = 'ak-card__body'

        card.title = this.editable('ak-card__title', 'Nome do produto', item.title || '')
        card.description = this.editable('ak-card__desc', 'Descrição (opcional)', item.description || '')

        body.append(card.title, card.description)

        return body
    }

    /**
     * Plain text, never rich: the whole card is one link, and a link inside a
     * link is not valid HTML — see the note at the top of this file.
     */
    editable(className, placeholder, value) {
        const el = document.createElement('div')
        el.className = className
        el.contentEditable = 'true'
        el.dataset.placeholder = placeholder
        el.textContent = value

        el.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.isComposing) {
                e.preventDefault()
                el.blur()
            }
        })

        // Pasting rich content into a plain field would store markup the
        // serializer then writes into an attribute.
        el.addEventListener('paste', (e) => {
            e.preventDefault()
            const text = (e.clipboardData?.getData('text/plain') || '').replace(/\s+/g, ' ').trim()
            document.execCommand('insertText', false, text)
        })

        return el
    }

    buildFoot(card) {
        const foot = document.createElement('div')
        foot.className = 'ak-card__foot'

        const grip = document.createElement('span')
        grip.className = 'ak-card__grip'
        grip.draggable = true
        grip.title = 'Arrastar para reordenar'
        grip.textContent = '⠿'
        grip.addEventListener('dragstart', (e) => {
            this.dragFrom = this.cards.indexOf(card)
            e.dataTransfer.effectAllowed = 'move'
            e.dataTransfer.setData('text/plain', 'card')
        })
        card.el.addEventListener('dragover', (e) => {
            if (this.dragFrom === null) return
            e.preventDefault()
            e.dataTransfer.dropEffect = 'move'
        })
        card.el.addEventListener('drop', (e) => {
            if (this.dragFrom === null) return
            e.preventDefault()
            this.reorder(this.dragFrom, this.cards.indexOf(card))
            this.dragFrom = null
        })

        const fit = document.createElement('select')
        fit.className = 'ak-card__fit'
        fit.title = 'Como o logo se encaixa no quadro'
        CARD_FITS.forEach((value) => {
            const option = document.createElement('option')
            option.value = value
            option.textContent = CARD_FIT_LABELS[value]
            option.selected = value === card.fitValue
            fit.append(option)
        })
        fit.addEventListener('change', () => {
            card.fitValue = CARD_FITS.includes(fit.value) ? fit.value : DEFAULT_CARD_FIT
            card.media.dataset.fit = card.fitValue
            this.changed()
        })

        const link = document.createElement('button')
        link.type = 'button'
        link.className = 'ak-card__link'
        link.addEventListener('click', () => this.openPicker(card))
        card.linkBtn = link
        this.drawLink(card)

        const remove = document.createElement('button')
        remove.type = 'button'
        remove.className = 'ak-card__remove'
        remove.title = 'Remover card'
        remove.innerHTML = '&times;'
        remove.addEventListener('click', () => this.removeCard(this.cards.indexOf(card)))

        foot.append(grip, fit, link, remove)

        return foot
    }

    removeCard(index) {
        if (index < 0) return

        const [card] = this.cards.splice(index, 1)
        card.el.remove()
        if (this.picking === card) this.closePicker()
        this.changed()
    }

    reorder(from, to) {
        if (from === to || from < 0 || to < 0) return

        const [moved] = this.cards.splice(from, 1)
        this.cards.splice(to, 0, moved)
        this.cards.forEach((card) => this.grid.insertBefore(card.el, this.addBtn))
        this.changed()
    }

    /* ---------------------------------------------------------------- */
    /*  Destination                                                     */
    /* ---------------------------------------------------------------- */

    /**
     * What the button says. A reference whose target this catalog does not have
     * is shown as the raw slug and SAID to be missing — the card still renders
     * for the reader (without a link), so an author has to be able to see the
     * difference from here.
     */
    drawLink(card) {
        const btn = card.linkBtn
        btn.dataset.mutationFree = 'true'
        btn.classList.toggle('is-empty', !card.link)
        btn.replaceChildren()
        btn.insertAdjacentHTML('afterbegin', LINK_ICON)

        const label = document.createElement('span')
        label.className = 'ak-card__link-label'
        label.textContent = this.linkLabel(card.link)
        btn.append(label)
        btn.title = card.link ? `Destino: ${card.link}` : 'Escolher destino'
    }

    linkLabel(link) {
        if (!link) return 'Escolher destino'

        if (link.startsWith('notebook:')) {
            const slug = link.slice('notebook:'.length)
            const notebook = notebookBySlug.get(slug)

            if (notebook) return notebook.name

            return targetsFailed ? slug : `${slug} (caderno não encontrado)`
        }

        if (link.startsWith('page:')) {
            const slug = link.slice('page:'.length)
            const page = pageBySlug.get(slug)

            if (page) return page.title

            return targetsFailed ? slug : `${slug} (página não encontrada)`
        }

        return link
    }

    async resolveLabels() {
        if (!this.cards.some((card) => card.link)) return

        await loadTargets(this.config.cardTargetsUrl)
        this.cards.forEach((card) => this.drawLink(card))
    }

    openPicker(card) {
        if (this.picking === card && !this.picker.classList.contains('hidden')) {
            this.closePicker()

            return
        }

        this.picking = card
        this.picker.classList.remove('hidden')
        // On the BUTTON, never on the card: the card holds the two
        // contenteditables, so it cannot be `data-mutation-free`, and a class
        // toggled on it reads as an edit — opening the picker alone was enough
        // to flip the page to "Não salvo".
        this.cards.forEach((c) => c.linkBtn.classList.toggle('is-open', c === card))
        this.buildPicker()
    }

    closePicker() {
        this.picking = null
        this.picker.classList.add('hidden')
        this.cards.forEach((c) => c.linkBtn.classList.remove('is-open'))
    }

    choose(link) {
        if (!this.picking) return

        this.picking.link = link
        this.drawLink(this.picking)
        this.closePicker()
        // The one change to this block Editor.js cannot see: the button it
        // happens in is `data-mutation-free`.
        this.block?.dispatchChange()
    }

    async buildPicker() {
        this.picker.replaceChildren()

        const head = document.createElement('div')
        head.className = 'ak-cards__picker-head'
        const title = document.createElement('span')
        const named = this.picking?.title?.textContent.trim()
        title.textContent = named ? `Para onde "${named}" leva` : 'Para onde este card leva'
        const close = document.createElement('button')
        close.type = 'button'
        close.className = 'ak-cards__picker-close'
        close.innerHTML = '&times;'
        close.title = 'Fechar'
        close.addEventListener('click', () => this.closePicker())
        head.append(title, close)
        this.picker.append(head)

        const typed = document.createElement('div')
        typed.className = 'ak-cards__picker-typed'

        const url = document.createElement('input')
        url.type = 'url'
        url.placeholder = 'https://… (endereço externo)'
        url.className = 'ak-cards__picker-url'
        url.value = this.picking?.link && !/^(notebook|page):/.test(this.picking.link) ? this.picking.link : ''

        const use = () => {
            const value = url.value.trim()
            if (value) this.choose(value)
        }

        url.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return
            e.preventDefault()
            use()
        })

        // A button as well as Enter, deliberately: `change` on blur would look
        // like the same convenience and is a trap — clicking a caderno in the
        // list below blurs the field FIRST, so a half-typed address would win
        // the race against the row that was actually clicked.
        const useBtn = document.createElement('button')
        useBtn.type = 'button'
        useBtn.className = 'ak-cards__picker-use'
        useBtn.textContent = 'Usar'
        useBtn.addEventListener('click', use)

        typed.append(url, useBtn)
        this.picker.append(typed)

        if (this.picking?.link) {
            const clear = document.createElement('button')
            clear.type = 'button'
            clear.className = 'ak-cards__picker-clear'
            clear.textContent = 'Remover destino (card sem link)'
            clear.addEventListener('click', () => this.choose(''))
            this.picker.append(clear)
        }

        const filter = document.createElement('input')
        filter.type = 'search'
        filter.placeholder = 'Filtrar cadernos e páginas…'
        filter.className = 'ak-cards__picker-filter'
        this.picker.append(filter)

        const list = document.createElement('div')
        list.className = 'ak-cards__picker-list'
        list.textContent = 'Carregando…'
        this.picker.append(list)

        const {notebooks, pages} = await loadTargets(this.config.cardTargetsUrl)
        list.replaceChildren()

        if (!notebooks.length && !pages.length) {
            list.textContent = targetsFailed
                ? 'Não consegui carregar o catálogo — recarregue a página ou informe um endereço acima.'
                : 'Nenhum caderno ou página para escolher.'

            return
        }

        list.append(
            this.pickerGroup('Cadernos', notebooks.map((notebook) => ({
                key: `notebook:${notebook.slug}`,
                label: notebook.name,
                // Said out loud rather than hidden: an unpublished caderno is a
                // perfectly good destination inside the app and simply has no
                // address in `/docs`, so the card there renders without a link.
                hint: notebook.published ? '' : 'não publicado na base de conhecimento',
            }))),
            this.pickerGroup('Páginas deste caderno', pages.map((page) => ({
                key: `page:${page.slug}`,
                label: page.title,
                hint: (page.trail || []).join(' › '),
            }))),
        )

        filter.addEventListener('input', () => {
            const term = fold(filter.value.trim())

            list.querySelectorAll('[data-group]').forEach((group) => {
                let any = false

                group.querySelectorAll('[data-row]').forEach((row) => {
                    const hit = !term || fold(row.dataset.row).includes(term)
                    row.classList.toggle('hidden', !hit)
                    any = any || hit
                })

                group.classList.toggle('hidden', !any)
            })
        })
    }

    pickerGroup(title, rows) {
        const group = document.createElement('div')
        group.dataset.group = title
        group.className = 'ak-cards__picker-group'

        if (!rows.length) {
            group.classList.add('hidden')

            return group
        }

        const heading = document.createElement('div')
        heading.className = 'ak-cards__picker-heading'
        heading.textContent = title
        group.append(heading)

        rows.forEach((row) => {
            const button = document.createElement('button')
            button.type = 'button'
            button.dataset.row = `${row.label} ${row.hint}`
            button.className = 'ak-cards__picker-row'
            button.addEventListener('click', () => this.choose(row.key))

            const label = document.createElement('span')
            label.className = 'ak-cards__picker-label'
            label.textContent = row.label
            button.append(label)

            if (row.hint) {
                const hint = document.createElement('span')
                hint.className = 'ak-cards__picker-hint'
                hint.textContent = row.hint
                button.append(hint)
            }

            group.append(button)
        })

        return group
    }

    /* ---------------------------------------------------------------- */
    /*  Editor.js contract                                              */
    /* ---------------------------------------------------------------- */

    changed() {
        this.block?.dispatchChange()
    }

    save() {
        return {
            cols: this.cols,
            items: this.cards.map((card) => ({
                title: card.title.textContent.trim(),
                description: card.description.textContent.trim(),
                image: card.image,
                fit: card.fitValue,
                link: card.link,
            })),
        }
    }

    static get sanitize() {
        return {
            cols: false,
            items: {title: false, description: false, image: false, fit: false, link: false},
        }
    }

    /**
     * A card with nothing in it at all serializes to an empty citation the
     * renderer would have to apologise for — but a card that has ANY of the
     * four is worth keeping, including a logo with no name yet.
     */
    validate(data) {
        return (data.items || []).some(
            (card) => card.title || card.description || card.image || card.link,
        )
    }
}
