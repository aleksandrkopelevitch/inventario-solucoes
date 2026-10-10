// Editor.js "Fluxo em etapas" tool — the first SCENE type: an ordered run of
// steps drawn as an animated figure (docs-scene.js) inside the page.
//
// Serializes to `{% scene type="steps" caption="…" %}{% step title="…"
// highlight="true" %}detalhe{% endstep %}…{% endscene %}` (docs-markdown.js),
// which GitbookRenderer emits as an ordered list that the reader animates.
//
// Two ways to fill it, and the block treats them as one: "Gerar a partir da
// página" asks the model (Gemini Flash, via `sceneUrl`) for the steps of the
// process the page describes — optionally narrowed by what the author types in
// "Sobre o quê?" — and the author can then edit, reorder, add or remove steps
// by hand, or write them all by hand from the start. The model only ever
// chooses WORDS; the picture is drawn by docs-scene.js, so the preview here is
// exactly what the reader will see.
//
// Every field is a native <input>, deliberately, and every change is reported
// by hand through `block.dispatchChange()`: typing in a native input mutates no
// DOM, so Editor.js would otherwise never notice it. The preview and the status
// line are `data-mutation-free` — they repaint for reasons that are not edits
// (a resize, a request in flight), and each repaint would have marked the page
// dirty and handed it to autosave.
//
// Depends on config injected by docs-editor.js:
//   sceneUrl — POST endpoint that proposes the steps (null outside a caderno)

import {mountScene} from '../docs-scene.js'

const ICON = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="8" width="5.5" height="8" rx="1.5"/><rect x="16" y="8" width="5.5" height="8" rx="1.5"/><path d="M8 12h7M12.5 9.5 15 12l-2.5 2.5"/></svg>'

const SPARKLES = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9.8 15.9 9 18.8l-.8-2.9a4.5 4.5 0 0 0-3.1-3.1L2.2 12l2.9-.8a4.5 4.5 0 0 0 3.1-3.1L9 5.2l.8 2.9a4.5 4.5 0 0 0 3.1 3.1l2.9.8-2.9.8a4.5 4.5 0 0 0-3.1 3.1ZM18.3 8.7 18 9.8l-.3-1.1a3.4 3.4 0 0 0-2.4-2.4L14.2 6l1.1-.3a3.4 3.4 0 0 0 2.4-2.4L18 2.2l.3 1.1a3.4 3.4 0 0 0 2.4 2.4l1.1.3-1.1.3a3.4 3.4 0 0 0-2.4 2.4Z"/></svg>'

const MAX_STEPS = 8 // App\Support\Documentation\StepScene::MAX_STEPS
const MAX_TITLE = 40 // StepScene::MAX_TITLE
const MAX_DETAIL = 140 // StepScene::MAX_DETAIL
const MAX_CAPTION = 120 // StepScene::MAX_CAPTION

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
        return {title: 'Fluxo em etapas', icon: ICON}
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
        this.caption = String(data?.caption || '')
        this.steps = (Array.isArray(data?.steps) ? data.steps : []).map((step) => ({
            title: String(step.title || ''),
            detail: String(step.detail || ''),
            highlight: step.highlight === true,
        }))
        this.previewTimer = null
    }

    render() {
        this.wrapper = el('div', 'ak-scene-tool no-preview')

        const head = el('div', 'ak-scene-tool__head')
        const badge = el('span', 'ak-scene-tool__icon')
        badge.innerHTML = ICON
        head.append(badge, el('span', 'ak-scene-tool__name', {text: 'Fluxo em etapas'}))

        this.wrapper.append(head, this.buildAsk())

        this.stage = el('div', 'ak-scene-tool__stage')
        this.stage.dataset.mutationFree = 'true'
        this.wrapper.append(this.stage)

        this.captionField = input('ak-scene-tool__caption', {
            placeholder: 'Legenda (opcional) — ex.: da solicitação ao crédito no SAP',
            value: this.caption,
            max: MAX_CAPTION,
        })
        this.captionField.addEventListener('input', () => {
            this.caption = this.captionField.value
            this.changed()
        })

        this.list = el('ol', 'ak-scene-tool__steps')
        this.addBtn = el('button', 'ak-scene-tool__add', {type: 'button'})
        this.addBtn.innerHTML = '<span aria-hidden="true">+</span> Adicionar etapa'
        this.addBtn.addEventListener('click', () => {
            if (this.steps.length >= MAX_STEPS) return
            this.steps.push({title: '', detail: '', highlight: false})
            this.drawSteps()
            this.list.lastElementChild?.querySelector('input')?.focus()
            this.changed()
        })

        this.wrapper.append(this.captionField, this.list, this.addBtn)
        this.drawSteps()
        // After the block is in the DOM, so the stage has a width to lay out in.
        requestAnimationFrame(() => this.preview())

        return this.wrapper
    }

    /* ---------------------------------------------------------------- */
    /*  "Gerar a partir da página"                                      */
    /* ---------------------------------------------------------------- */

    buildAsk() {
        const row = el('div', 'ak-scene-tool__ask')
        // Its own repaints (busy, an error line) are not edits.
        row.dataset.mutationFree = 'true'

        this.focusField = input('ak-scene-tool__focus', {
            placeholder: 'Sobre o quê? (opcional) — ex.: devolução de mercadoria',
            max: 300,
        })
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

        const hasSteps = this.steps.some((step) => step.title.trim() || step.detail.trim())
        if (hasSteps && !window.confirm('Substituir as etapas atuais pelo fluxo gerado a partir da página?')) return

        this.setBusy(true)

        try {
            // The page as it is NOW in the editor — the paragraph just written
            // is usually the one the figure is for, and autosave may not have
            // reached it yet.
            const content = typeof window.__akDocsGetMarkdown === 'function' ? await window.__akDocsGetMarkdown() : null

            const response = await fetch(this.config.sceneUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf()},
                body: JSON.stringify({type: 'steps', focus: this.focusField.value.trim() || null, content}),
            })
            const data = await response.json().catch(() => null)

            if (!response.ok || !data?.scene) {
                throw new Error(data?.message || 'Não foi possível gerar o fluxo agora. Tente de novo.')
            }

            this.steps = data.scene.steps.map((step) => ({
                title: step.title || '',
                detail: step.detail || '',
                highlight: step.highlight === true,
            }))
            this.caption = data.scene.caption || ''
            this.captionField.value = this.caption
            this.drawSteps()
            this.changed()
            this.status.textContent = 'Fluxo gerado. Confira as etapas — dá para editar, reordenar e marcar o ponto-chave.'
        } catch (error) {
            this.status.textContent = ''
            Toast.open({title: 'Fluxo em etapas', content: error.message, type: 'warning'})
        } finally {
            this.setBusy(false)
        }
    }

    setBusy(busy) {
        this.busy = busy
        this.generateBtn.disabled = busy
        this.wrapper.classList.toggle('is-busy', busy)
        this.generateLabel.textContent = busy ? 'Gerando…' : 'Gerar a partir da página'
        if (busy) this.status.textContent = 'Lendo a página e montando as etapas — leva alguns segundos.'
    }

    /* ---------------------------------------------------------------- */
    /*  The steps                                                       */
    /* ---------------------------------------------------------------- */

    drawSteps() {
        this.list.replaceChildren(...this.steps.map((step, i) => this.buildStep(step, i)))
        this.addBtn.hidden = this.steps.length >= MAX_STEPS
        this.wrapper.classList.toggle('is-empty', this.steps.length === 0)
    }

    buildStep(step, i) {
        const row = el('li', 'ak-scene-tool__step' + (step.highlight ? ' is-highlight' : ''))

        const number = el('span', 'ak-scene-tool__num', {text: String(i + 1), 'aria-hidden': 'true'})

        const fields = el('div', 'ak-scene-tool__fields')
        const title = input('ak-scene-tool__title', {placeholder: 'O que acontece nesta etapa', value: step.title, max: MAX_TITLE})
        const detail = input('ak-scene-tool__detail', {placeholder: 'Detalhe (opcional) — quem faz, onde, como', value: step.detail, max: MAX_DETAIL})
        title.addEventListener('input', () => {
            step.title = title.value
            this.changed()
        })
        detail.addEventListener('input', () => {
            step.detail = detail.value
            this.changed()
        })
        // Enter walks forward: title → detail → next step's title.
        title.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault()
                detail.focus()
            }
        })
        detail.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault()
                const next = row.nextElementSibling?.querySelector('input')
                if (next) next.focus()
                else this.addBtn.click()
            }
        })
        fields.append(title, detail)

        const actions = el('div', 'ak-scene-tool__actions')
        actions.append(
            this.action('★', step.highlight ? 'Desmarcar ponto-chave' : 'Marcar como ponto-chave', () => {
                const on = !step.highlight
                // At most one: the figure singles out ONE step or none.
                this.steps.forEach((other) => { other.highlight = false })
                step.highlight = on
            }, step.highlight ? 'is-on' : ''),
            this.action('↑', 'Subir', () => this.move(i, -1), '', i === 0),
            this.action('↓', 'Descer', () => this.move(i, 1), '', i === this.steps.length - 1),
            this.action('×', 'Remover etapa', () => this.steps.splice(i, 1), 'is-danger'),
        )

        row.append(number, fields, actions)

        return row
    }

    action(glyph, label, run, extra = '', disabled = false) {
        const button = el('button', `ak-scene-tool__action ${extra}`.trim(), {type: 'button', title: label, 'aria-label': label, text: glyph})
        button.disabled = disabled
        button.addEventListener('click', () => {
            run()
            this.drawSteps()
            this.changed()
        })

        return button
    }

    move(i, delta) {
        const j = i + delta
        if (j < 0 || j >= this.steps.length) return
        ;[this.steps[i], this.steps[j]] = [this.steps[j], this.steps[i]]
    }

    /* ---------------------------------------------------------------- */
    /*  Preview + save                                                  */
    /* ---------------------------------------------------------------- */

    changed() {
        this.block?.dispatchChange()
        clearTimeout(this.previewTimer)
        this.previewTimer = setTimeout(() => this.preview(), 250)
    }

    preview() {
        if (!this.stage) return
        // Shown before drawing, so the stage has a width to lay the figure out in.
        this.wrapper.classList.toggle('no-preview', !this.steps.some((step) => step.title.trim() || step.detail.trim()))
        mountScene(this.stage, {type: 'steps', caption: this.caption, steps: this.steps.map((step) => ({...step}))})
    }

    save() {
        return {
            type: 'steps',
            caption: this.caption.trim(),
            steps: this.steps.map((step) => ({
                title: step.title.trim(),
                detail: step.detail.trim(),
                highlight: step.highlight,
            })),
        }
    }

    validate(data) {
        return (data.steps || []).some((step) => step.title || step.detail)
    }
}
