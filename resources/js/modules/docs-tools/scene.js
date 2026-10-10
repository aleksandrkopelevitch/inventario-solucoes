// Editor.js SCENE tool — an animated figure (docs-scene.js) inside the page.
// One tool, two entries in the "/" menu, one per scene type:
//
// - "Fluxo em etapas" → `{% scene type="steps" %}{% step title="…"
//   highlight="true" %}detalhe{% endstep %}…{% endscene %}`
// - "Antes → depois" → `{% scene type="before-after" from="Hoje" to="…" %}
//   {% change aspect="…" before="…" after="…" %}…{% endscene %}`
//
// (docs-markdown.js writes and reads both; GitbookRenderer emits them as an
// ordered list / a table that the reader animates.)
//
// One tool rather than one per type because everything around the rows is the
// same: "Gerar a partir da página" asks the model (Gemini Flash, via
// `sceneUrl`) for the rows of THIS type — optionally narrowed by what the author
// types in "Sobre o quê?" — and the author then edits, reorders, adds or removes
// rows by hand, or writes them all by hand from the start; the preview is the
// reader's own drawing. What differs per type is only the shape of a row, and
// that is the TYPES table below.
//
// Every field is a native <input>, deliberately, and every change is reported
// by hand through `block.dispatchChange()`: typing in a native input mutates no
// DOM, so Editor.js would otherwise never notice it. The preview and the status
// line are `data-mutation-free` — they repaint for reasons that are not edits
// (a resize, a request in flight), and each repaint would have marked the page
// dirty and handed it to autosave.
//
// Depends on config injected by docs-editor.js:
//   sceneUrl — POST endpoint that proposes the rows (null outside a caderno)

import {mountScene} from '../docs-scene.js'

const STEPS_ICON = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="8" width="5.5" height="8" rx="1.5"/><rect x="16" y="8" width="5.5" height="8" rx="1.5"/><path d="M8 12h7M12.5 9.5 15 12l-2.5 2.5"/></svg>'

const BEFORE_AFTER_ICON = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="5" width="7" height="14" rx="1.5" stroke-dasharray="2.5 2.5"/><rect x="14.5" y="5" width="7" height="14" rx="1.5"/><path d="M10.5 12h3M12.3 10.5 13.8 12l-1.5 1.5"/></svg>'

const SPARKLES = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9.8 15.9 9 18.8l-.8-2.9a4.5 4.5 0 0 0-3.1-3.1L2.2 12l2.9-.8a4.5 4.5 0 0 0 3.1-3.1L9 5.2l.8 2.9a4.5 4.5 0 0 0 3.1 3.1l2.9.8-2.9.8a4.5 4.5 0 0 0-3.1 3.1ZM18.3 8.7 18 9.8l-.3-1.1a3.4 3.4 0 0 0-2.4-2.4L14.2 6l1.1-.3a3.4 3.4 0 0 0 2.4-2.4L18 2.2l.3 1.1a3.4 3.4 0 0 0 2.4 2.4l1.1.3-1.1.3a3.4 3.4 0 0 0-2.4 2.4Z"/></svg>'

const MAX_CAPTION = 120 // StepScene::MAX_CAPTION = BeforeAfterScene::MAX_CAPTION

/**
 * What a row of each scene type is. The limits mirror the PHP IRs
 * (App\Support\Documentation\StepScene / BeforeAfterScene) — the input refuses
 * what the validator would refuse, so a hand-written row never fails a save.
 */
const TYPES = {
    steps: {
        name: 'Fluxo em etapas',
        icon: STEPS_ICON,
        list: 'steps',
        max: 8,
        add: 'Adicionar etapa',
        focus: 'Sobre o quê? (opcional) — ex.: devolução de mercadoria',
        caption: 'Legenda (opcional) — ex.: da solicitação ao crédito no SAP',
        highlight: ['Marcar como ponto-chave', 'Desmarcar ponto-chave'],
        replace: 'Substituir as etapas atuais pelo fluxo gerado a partir da página?',
        done: 'Fluxo gerado. Confira as etapas — dá para editar, reordenar e marcar o ponto-chave.',
        fields: [
            {key: 'title', placeholder: 'O que acontece nesta etapa', max: 40, cls: 'ak-scene-tool__title'},
            {key: 'detail', placeholder: 'Detalhe (opcional) — quem faz, onde, como', max: 140, cls: 'ak-scene-tool__detail'},
        ],
        labels: false,
        filled: (row) => row.title.trim() || row.detail.trim(),
    },
    'before-after': {
        name: 'Antes → depois',
        icon: BEFORE_AFTER_ICON,
        list: 'changes',
        max: 6,
        add: 'Adicionar mudança',
        focus: 'Sobre o quê? (opcional) — ex.: o pedido com a integração ao Leo360',
        caption: 'Legenda (opcional) — ex.: o que muda no pedido do Leomob',
        highlight: ['Marcar como principal mudança', 'Desmarcar principal mudança'],
        replace: 'Substituir as mudanças atuais pela comparação gerada a partir da página?',
        done: 'Comparação gerada. Confira as mudanças — dá para editar, reordenar e marcar a principal.',
        fields: [
            {key: 'aspect', placeholder: 'O que muda — ex.: Pagamento', max: 32, cls: 'ak-scene-tool__title'},
            // `pair` puts the two sides on one line with an arrow between them.
            {key: 'before', placeholder: 'Como era (vazio = é novo)', max: 120, cls: 'ak-scene-tool__detail', pair: true},
            {key: 'after', placeholder: 'Como fica (vazio = deixa de existir)', max: 120, cls: 'ak-scene-tool__detail', pair: true},
        ],
        labels: true,
        filled: (row) => row.before.trim() || row.after.trim(),
    },
}

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
}

function el(tag, className, attrs = {}) {
    const node = document.createElement(tag)
    if (className) node.className = className
    Object.entries(attrs).forEach(([key, value]) => {
        if (key === 'text') node.textContent = value
        else node.setAttribute(key, value)
    })

    return node
}

function input(className, {placeholder, value = '', max}) {
    const field = el('input', className, {type: 'text', placeholder, maxlength: String(max), autocomplete: 'off'})
    field.value = value
    // Editor.js reads Enter, Backspace and Tab inside a block as block
    // gestures (split, merge, indent). Inside these fields they are text.
    field.addEventListener('keydown', (e) => e.stopPropagation())

    return field
}

export default class SceneTool {
    static get toolbox() {
        return Object.entries(TYPES).map(([type, spec]) => ({title: spec.name, icon: spec.icon, data: {type}}))
    }

    static get enableLineBreaks() {
        return true
    }

    static get contentless() {
        return false
    }

    constructor({data, config, block}) {
        this.config = config || {}
        this.block = block
        this.type = TYPES[data?.type] ? data.type : 'steps'
        this.spec = TYPES[this.type]
        this.caption = String(data?.caption || '')
        this.from = String(data?.from || '')
        this.to = String(data?.to || '')
        this.rows = this.normalizeRows(data?.[this.spec.list])
        this.previewTimer = null
    }

    normalizeRows(rows) {
        return (Array.isArray(rows) ? rows : []).map((row) => {
            const clean = {highlight: row.highlight === true}
            this.spec.fields.forEach(({key}) => { clean[key] = String(row[key] || '') })

            return clean
        })
    }

    blankRow() {
        return this.normalizeRows([{}])[0]
    }

    render() {
        this.wrapper = el('div', 'ak-scene-tool no-preview')
        this.wrapper.dataset.type = this.type

        const head = el('div', 'ak-scene-tool__head')
        const badge = el('span', 'ak-scene-tool__icon')
        badge.innerHTML = this.spec.icon
        head.append(badge, el('span', 'ak-scene-tool__name', {text: this.spec.name}))

        this.wrapper.append(head, this.buildAsk())

        this.stage = el('div', 'ak-scene-tool__stage')
        this.stage.dataset.mutationFree = 'true'
        this.wrapper.append(this.stage)

        this.captionField = input('ak-scene-tool__caption', {placeholder: this.spec.caption, value: this.caption, max: MAX_CAPTION})
        this.captionField.addEventListener('input', () => {
            this.caption = this.captionField.value
            this.changed()
        })
        this.wrapper.append(this.captionField)

        if (this.spec.labels) this.wrapper.append(this.buildLabels())

        this.list = el('ol', 'ak-scene-tool__steps')
        this.addBtn = el('button', 'ak-scene-tool__add', {type: 'button'})
        this.addBtn.innerHTML = '<span aria-hidden="true">+</span> '
        this.addBtn.append(document.createTextNode(this.spec.add))
        this.addBtn.addEventListener('click', () => {
            if (this.rows.length >= this.spec.max) return
            this.rows.push(this.blankRow())
            this.drawRows()
            this.list.lastElementChild?.querySelector('input')?.focus()
            this.changed()
        })

        this.wrapper.append(this.list, this.addBtn)
        this.drawRows()
        // After the block is in the DOM, so the stage has a width to lay out in.
        requestAnimationFrame(() => this.preview())

        return this.wrapper
    }

    /** The two column names of an "antes → depois" ("Hoje" → "Com a integração"). */
    buildLabels() {
        const row = el('div', 'ak-scene-tool__labels')
        const from = input('ak-scene-tool__label', {placeholder: 'Coluna da esquerda — Antes', value: this.from, max: 24})
        const to = input('ak-scene-tool__label', {placeholder: 'Coluna da direita — Depois', value: this.to, max: 24})
        from.addEventListener('input', () => {
            this.from = from.value
            this.changed()
        })
        to.addEventListener('input', () => {
            this.to = to.value
            this.changed()
        })
        this.fromField = from
        this.toField = to
        row.append(from, el('span', 'ak-scene-tool__arrow', {text: '→', 'aria-hidden': 'true'}), to)

        return row
    }

    /* ---------------------------------------------------------------- */
    /*  "Gerar a partir da página"                                      */
    /* ---------------------------------------------------------------- */

    buildAsk() {
        const row = el('div', 'ak-scene-tool__ask')
        // Its own repaints (busy, an error line) are not edits.
        row.dataset.mutationFree = 'true'

        this.focusField = input('ak-scene-tool__focus', {placeholder: this.spec.focus, max: 300})
        this.focusField.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault()
                this.generate()
            }
        })

        this.generateBtn = el('button', 'ak-scene-tool__generate', {type: 'button'})
        this.generateBtn.innerHTML = SPARKLES
        this.generateLabel = el('span', '', {text: 'Gerar a partir da página'})
        this.generateBtn.append(this.generateLabel)
        this.generateBtn.addEventListener('click', () => this.generate())

        this.status = el('p', 'ak-scene-tool__status')

        if (!this.config.sceneUrl) {
            this.generateBtn.disabled = true
            this.generateBtn.title = 'Disponível só dentro de um caderno'
        }

        row.append(this.focusField, this.generateBtn, this.status)

        return row
    }

    async generate() {
        if (!this.config.sceneUrl || this.busy) return

        if (this.rows.some(this.spec.filled) && !window.confirm(this.spec.replace)) return

        this.setBusy(true)

        try {
            // The page as it is NOW in the editor — the paragraph just written
            // is usually the one the figure is for, and autosave may not have
            // reached it yet.
            const content = typeof window.__akDocsGetMarkdown === 'function' ? await window.__akDocsGetMarkdown() : null

            const response = await fetch(this.config.sceneUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf()},
                body: JSON.stringify({type: this.type, focus: this.focusField.value.trim() || null, content}),
            })
            const data = await response.json().catch(() => null)

            if (!response.ok || !data?.scene) {
                throw new Error(data?.message || 'Não foi possível gerar agora. Tente de novo.')
            }

            this.rows = this.normalizeRows(data.scene[this.spec.list])
            this.caption = data.scene.caption || ''
            this.captionField.value = this.caption
            if (this.spec.labels) {
                this.from = data.scene.from || ''
                this.to = data.scene.to || ''
                this.fromField.value = this.from
                this.toField.value = this.to
            }
            this.drawRows()
            this.changed()
            this.status.textContent = this.spec.done
        } catch (error) {
            this.status.textContent = ''
            Toast.open({title: this.spec.name, content: error.message, type: 'warning'})
        } finally {
            this.setBusy(false)
        }
    }

    setBusy(busy) {
        this.busy = busy
        this.generateBtn.disabled = busy
        this.wrapper.classList.toggle('is-busy', busy)
        this.generateLabel.textContent = busy ? 'Gerando…' : 'Gerar a partir da página'
        if (busy) this.status.textContent = 'Lendo a página e montando a figura — leva alguns segundos.'
    }

    /* ---------------------------------------------------------------- */
    /*  The rows                                                        */
    /* ---------------------------------------------------------------- */

    drawRows() {
        this.list.replaceChildren(...this.rows.map((row, i) => this.buildRow(row, i)))
        this.addBtn.hidden = this.rows.length >= this.spec.max
        this.wrapper.classList.toggle('is-empty', this.rows.length === 0)
    }

    buildRow(row, i) {
        const item = el('li', 'ak-scene-tool__step' + (row.highlight ? ' is-highlight' : ''))
        const number = el('span', 'ak-scene-tool__num', {text: String(i + 1), 'aria-hidden': 'true'})

        const fields = el('div', 'ak-scene-tool__fields')
        const pair = el('div', 'ak-scene-tool__pair')
        const inputs = this.spec.fields.map((spec) => {
            const field = input(spec.cls, {placeholder: spec.placeholder, value: row[spec.key], max: spec.max})
            field.addEventListener('input', () => {
                row[spec.key] = field.value
                this.changed()
            })
            if (spec.pair) {
                if (pair.childElementCount) pair.append(el('span', 'ak-scene-tool__arrow', {text: '→', 'aria-hidden': 'true'}))
                pair.append(field)
            } else {
                fields.append(field)
            }

            return field
        })
        if (pair.childElementCount) fields.append(pair)

        // Enter walks forward through the row, then to the next row's first field.
        inputs.forEach((field, k) => field.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return
            e.preventDefault()
            const next = inputs[k + 1] ?? item.nextElementSibling?.querySelector('input')
            if (next) next.focus()
            else this.addBtn.click()
        }))

        const [on, off] = this.spec.highlight
        const actions = el('div', 'ak-scene-tool__actions')
        actions.append(
            this.action('★', row.highlight ? off : on, () => {
                const value = !row.highlight
                // At most one: the figure singles out ONE row or none.
                this.rows.forEach((other) => { other.highlight = false })
                row.highlight = value
            }, row.highlight ? 'is-on' : ''),
            this.action('↑', 'Subir', () => this.move(i, -1), '', i === 0),
            this.action('↓', 'Descer', () => this.move(i, 1), '', i === this.rows.length - 1),
            this.action('×', 'Remover', () => this.rows.splice(i, 1), 'is-danger'),
        )

        item.append(number, fields, actions)

        return item
    }

    action(glyph, label, run, extra = '', disabled = false) {
        const button = el('button', `ak-scene-tool__action ${extra}`.trim(), {type: 'button', title: label, 'aria-label': label, text: glyph})
        button.disabled = disabled
        button.addEventListener('click', () => {
            run()
            this.drawRows()
            this.changed()
        })

        return button
    }

    move(i, delta) {
        const j = i + delta
        if (j < 0 || j >= this.rows.length) return
        ;[this.rows[i], this.rows[j]] = [this.rows[j], this.rows[i]]
    }

    /* ---------------------------------------------------------------- */
    /*  Preview + save                                                  */
    /* ---------------------------------------------------------------- */

    changed() {
        this.block?.dispatchChange()
        clearTimeout(this.previewTimer)
        this.previewTimer = setTimeout(() => this.preview(), 250)
    }

    scene(trim = false) {
        const text = (value) => (trim ? value.trim() : value)
        const scene = {type: this.type, caption: text(this.caption)}
        if (this.spec.labels) {
            scene.from = text(this.from)
            scene.to = text(this.to)
        }
        scene[this.spec.list] = this.rows.map((row) => {
            const copy = {highlight: row.highlight}
            this.spec.fields.forEach(({key}) => { copy[key] = text(row[key]) })

            return copy
        })

        return scene
    }

    preview() {
        if (!this.stage) return
        // Shown before drawing, so the stage has a width to lay the figure out in.
        this.wrapper.classList.toggle('no-preview', !this.rows.some(this.spec.filled))
        mountScene(this.stage, this.scene())
    }

    save() {
        return this.scene(true)
    }

    validate(data) {
        return (data[this.spec.list] || []).some(this.spec.filled)
    }
}
