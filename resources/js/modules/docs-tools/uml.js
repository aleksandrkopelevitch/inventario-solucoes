// Editor.js "Diagrama UML" tool — the editing half of `{% uml %}`.
//
// The block holds the diagram's type, its title and its JSON body VERBATIM
// (`source`), and previews it with the reader's own renderer (docs-uml.js's
// mountUml), so what the author sees is what the page will draw, fullscreen
// button included. The JSON is NOT on the block: it opens from the diagram's
// "Código" button in `#main-modal`, and the preview behind it redraws as it
// is typed. A page of diagrams used to read as a page of JSON. (That click
// is a click inside the editor on something that is not text, so it is what
// lets docs-editor.js take the typing in the dialog — outside the editor —
// as an edit; see `userEdited` there.)
//
// The server check also answers each bound solution's logo (`logos`), which
// the preview then draws as a corner badge, the way the reader does.
//
// "Gerar com IA" (`umlDraftUrl`, UmlDraftService) asks the model for the JSON:
// from the page, from what the author typed beside the button, or — when the
// block holds a diagram of its own and something was typed — as a CHANGE to
// it. The answer REPLACES the JSON, so the previous one is kept for
// "Desfazer" (a tool's data has no Editor.js undo). Spec:
// docs-and-specs/uml-ai-generation.md. The JSON is the source of truth on purpose: it is what a
// model writes through the MCP server, and one notation edited by both is the
// only way the two never drift. The SERVER is the judge of a valid diagram
// (App\Support\Documentation\UmlDiagram), and the block ASKS it
// (`umlCheckUrl`, NotebookPageUmlController) a moment after the author stops
// typing: the preview is lenient by design — it draws an element with no
// `at`, an unknown kind, two elements in one cell — and without the question
// the author saved a picture the page then showed as an error box.
//
// Typing in a textarea changes a property, not the DOM tree, so Editor.js
// would never notice it — each field calls `dispatchChange()`. The preview,
// the status line, the "Gerar com IA" row and its note repaint for reasons
// that are not edits (a check answering, "Gerando…"), so each is
// `data-mutation-free` (see .claude/rules/css-and-docs-rendering.md): a
// generation that failed must not leave the page "Não salvo".

import {mountUml} from '../docs-uml'

const ICON = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="6"/><path d="M3 6h7"/><rect x="14" y="15" width="7" height="6"/><path d="M14 18h7"/><path d="M6.5 9v5.5H14"/><path d="m11 12 3 2.5-3 2.5"/></svg>'

// Mirrors App\Support\Documentation\UmlDiagram::TYPES.
const TYPES = {
    sequence: 'Diagrama de sequência',
    class: 'Diagrama de classes',
    component: 'Diagrama de componentes',
    deployment: 'Diagrama de implantação',
    usecase: 'Diagrama de casos de uso',
    activity: 'Diagrama de atividades',
    state: 'Diagrama de estados',
    package: 'Diagrama de pacotes',
}

// A starting body per type, offered while the block is empty.
const SKELETONS = {
    sequence: {
        participants: [
            {id: 'cliente', label: 'Cliente', kind: 'actor'},
            {id: 'api', label: 'API', kind: 'boundary'},
        ],
        messages: [
            {from: 'cliente', to: 'api', label: 'requisição', kind: 'sync'},
            {from: 'api', to: 'cliente', label: 'resposta', kind: 'return'},
        ],
    },
    class: {
        nodes: [
            {id: 'a', kind: 'class', name: 'Pedido', attributes: ['- id: int'], operations: ['+ total(): decimal'], at: [0, 0]},
            {id: 'b', kind: 'class', name: 'Item', at: [1, 0]},
        ],
        edges: [{from: 'a', to: 'b', kind: 'composition', from_label: '1', to_label: '1..*'}],
    },
    activity: {
        nodes: [
            {id: 'inicio', kind: 'initial', at: [0, 0]},
            {id: 'acao', kind: 'action', name: 'Fazer algo', at: [0, 1]},
            {id: 'fim', kind: 'final', at: [0, 2]},
        ],
        edges: [{from: 'inicio', to: 'acao', kind: 'flow'}, {from: 'acao', to: 'fim', kind: 'flow'}],
    },
}

function skeleton(type) {
    const body = SKELETONS[type] || {
        nodes: [
            {id: 'a', kind: type === 'usecase' ? 'actor' : 'component', name: 'A', at: [0, 0]},
            {id: 'b', kind: type === 'usecase' ? 'usecase' : type === 'state' ? 'state' : 'component', name: 'B', at: [1, 0]},
        ],
        edges: [{from: 'a', to: 'b', kind: type === 'state' ? 'flow' : 'dependency'}],
    }

    return JSON.stringify(body, null, 2)
}

export default class UmlTool {
    static get toolbox() {
        return {title: 'Diagrama UML', icon: ICON}
    }

    // Enter inside the JSON is a line break, not a new block.
    static get enableLineBreaks() {
        return true
    }

    static get isReadOnlySupported() {
        return true
    }

    constructor({data, block, readOnly, config}) {
        this.data = {
            type: TYPES[data?.type] ? data.type : 'sequence',
            title: data?.title || '',
            source: data?.source || '',
        }
        this.block = block
        this.readOnly = readOnly
        this.timer = null
        this.checkUrl = config?.umlCheckUrl || ''
        this.draftUrl = config?.umlDraftUrl || ''
        this.busy = false
        // The JSON a generation replaced, for "Desfazer".
        this.previous = null
        this.checkTimer = null
        this.ticket = 0
        // slug → logo URL, as the server check last answered it.
        this.logos = {}
        // The block's status line, and its copy in the open code dialog.
        this.statuses = new Set()
    }

    render() {
        const wrap = document.createElement('div')
        wrap.className = 'ak-uml-tool'

        const head = document.createElement('div')
        head.className = 'ak-uml-tool__head'

        const type = document.createElement('select')
        type.className = 'ak-uml-tool__type'
        type.setAttribute('aria-label', 'Tipo de diagrama')
        Object.entries(TYPES).forEach(([value, label]) => {
            const option = document.createElement('option')
            option.value = value
            option.textContent = label
            option.selected = value === this.data.type
            type.appendChild(option)
        })

        const title = document.createElement('input')
        title.type = 'text'
        title.className = 'ak-uml-tool__title'
        title.placeholder = 'Título (opcional)'
        title.value = this.data.title

        if (!this.data.source) this.data.source = skeleton(this.data.type)

        const status = document.createElement('p')
        status.className = 'ak-uml-tool__status'
        status.setAttribute('role', 'status')
        status.dataset.mutationFree = 'true'
        this.statuses.add(status)

        // The preview box is the positioned frame the fullscreen button sits on.
        const preview = document.createElement('div')
        preview.className = 'ak-uml-tool__preview'
        preview.dataset.mutationFree = 'true'
        const stage = document.createElement('div')
        stage.className = 'ak-uml__stage'
        preview.appendChild(stage)

        if (this.readOnly) {
            type.disabled = true
            title.readOnly = true
        }

        this.stage = stage
        this.changed = () => {
            this.block?.dispatchChange()
            clearTimeout(this.timer)
            this.timer = setTimeout(() => this.paint(), 250)
        }

        type.addEventListener('change', () => {
            // An untouched skeleton follows the type; anything typed stays.
            if (this.data.source.trim() === skeleton(this.data.type).trim()) {
                this.data.source = skeleton(type.value)
                if (this.source) this.source.value = this.data.source
            }
            this.data.type = type.value
            this.changed()
        })
        title.addEventListener('input', () => {
            this.data.title = title.value
            this.changed()
        })

        head.append(type, title)
        wrap.append(head)
        if (this.draftUrl && !this.readOnly) {
            // What a generation brought back — muted, apart from the status
            // line, which is the validator's and reads as a warning.
            this.noteEl = document.createElement('p')
            this.noteEl.className = 'ak-uml-tool__note'
            this.noteEl.setAttribute('role', 'status')
            this.noteEl.dataset.mutationFree = 'true'
            wrap.append(this.renderDraftRow(), this.noteEl)
        }
        wrap.append(status, preview)
        // After the block is in the document: the renderer measures text with
        // the font the preview is set in.
        requestAnimationFrame(() => this.paint())

        return wrap
    }

    setStatus(text) {
        this.statuses.forEach((el) => { el.textContent = text })
    }

    get status() {
        return [...this.statuses][0]?.textContent || ''
    }

    setNote(text) {
        if (this.noteEl) this.noteEl.textContent = text
    }

    /* -------------------------------------------------------------- */
    /*  "Gerar com IA"                                                 */
    /* -------------------------------------------------------------- */

    renderDraftRow() {
        const row = document.createElement('div')
        row.className = 'ak-uml-tool__ai'
        row.dataset.mutationFree = 'true'

        this.instruction = document.createElement('input')
        this.instruction.type = 'text'
        this.instruction.className = 'ak-uml-tool__instruction'
        this.instruction.maxLength = 600
        this.instruction.placeholder = 'Descreva o diagrama ou a mudança (opcional) — ex.: o pedido do VTEX ao SAP, via Digibee'
        this.instruction.setAttribute('aria-label', 'O que o diagrama deve mostrar')
        this.instruction.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return
            e.preventDefault()
            this.generate()
        })

        this.generateBtn = document.createElement('button')
        this.generateBtn.type = 'button'
        this.generateBtn.className = 'ak-uml-tool__generate'
        this.generateBtn.textContent = 'Gerar com IA'
        this.generateBtn.addEventListener('click', () => this.generate())

        this.undoBtn = document.createElement('button')
        this.undoBtn.type = 'button'
        this.undoBtn.className = 'ak-uml-tool__undo'
        this.undoBtn.textContent = 'Desfazer'
        this.undoBtn.title = 'Voltar ao diagrama de antes da geração'
        this.undoBtn.hidden = true
        this.undoBtn.addEventListener('click', () => this.undo())

        row.append(this.instruction, this.generateBtn, this.undoBtn)

        return row
    }

    isSkeleton() {
        return this.data.source.trim() === skeleton(this.data.type).trim()
    }

    async generate() {
        if (!this.draftUrl || this.busy) return

        const instruction = this.instruction.value.trim()
        // A diagram of the author's own and nothing asked: this starts over.
        if (!this.isSkeleton() && !instruction
            && !window.confirm('Gerar um diagrama novo a partir da página? O diagrama atual será substituído (dá para desfazer).')) return

        this.setBusy(true)
        try {
            // The page as it is NOW in the editor, as the scene block sends it:
            // the paragraph just written is usually the one the diagram is for.
            const content = typeof window.__akDocsGetMarkdown === 'function' ? await window.__akDocsGetMarkdown() : null
            const response = await fetch(this.draftUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                body: JSON.stringify({
                    type: this.data.type,
                    title: this.data.title || null,
                    instruction: instruction || null,
                    content,
                    // With something asked, the block's own diagram is what changes.
                    source: !this.isSkeleton() && instruction ? this.data.source : null,
                }),
            })
            const data = await response.json().catch(() => null)
            if (!response.ok || typeof data?.source !== 'string') {
                throw new Error(data?.message || 'Não foi possível gerar agora. Tente de novo.')
            }

            this.previous = this.data.source
            this.data.source = data.source
            this.logos = data.logos || {}
            if (this.source) this.source.value = data.source
            this.undoBtn.hidden = false
            const left = (data.problems?.length || 0) + (data.warnings?.length || 0)
            this.setNote(left
                ? 'Diagrama gerado, com pontos a corrigir no código (listados abaixo). Revise antes de publicar.'
                : 'Diagrama gerado. Revise antes de publicar.')
            this.changed()
        } catch (error) {
            window.Toast?.open({title: 'Diagrama UML', content: error.message, type: 'warning'})
            this.setNote('')
        } finally {
            this.setBusy(false)
        }
    }

    undo() {
        if (this.previous === null) return
        this.data.source = this.previous
        if (this.source) this.source.value = this.previous
        this.forgetGeneration()
        this.changed()
    }

    /** After an undo, or once the author edits the JSON by hand. */
    forgetGeneration() {
        this.previous = null
        this.setNote('')
        if (this.undoBtn) this.undoBtn.hidden = true
    }

    setBusy(busy) {
        this.busy = busy
        this.generateBtn.disabled = busy
        this.instruction.disabled = busy
        this.generateBtn.textContent = busy ? 'Gerando…' : 'Gerar com IA'
        if (busy) this.setNote('Lendo a página e montando o diagrama — leva alguns segundos.')
    }

    /**
     * The JSON, in the shared `#main-modal`. Typing there edits the block like
     * the field it replaced did: the data, the change, the preview behind the
     * dialog, and the status — mirrored under the field, since the block's own
     * is out of sight while the dialog is open.
     */
    openCode() {
        const modal = document.getElementById('main-modal')
        if (!modal || !window.Modal) {
            window.Toast?.show('Não consegui abrir o código do diagrama.', 'warning')

            return
        }

        const shell = document.createElement('div')
        shell.className = 'flex max-h-[85vh] flex-col'

        const header = document.createElement('div')
        header.className = 'border-b border-line px-6 py-4'
        const heading = document.createElement('h2')
        heading.className = 'text-base font-bold text-ink'
        heading.textContent = this.data.title ? `Código — ${this.data.title}` : 'Código do diagrama'
        const hint = document.createElement('p')
        hint.className = 'mt-0.5 text-xs text-muted'
        hint.textContent = this.readOnly
            ? `${TYPES[this.data.type]}, em JSON.`
            : `${TYPES[this.data.type]}, em JSON. O diagrama atrás desta janela se redesenha enquanto você digita.`
        header.append(heading, hint)

        const source = document.createElement('textarea')
        source.className = 'ak-uml-tool__source ak-uml-tool__source--dialog'
        source.spellcheck = false
        source.placeholder = 'JSON do diagrama'
        source.setAttribute('aria-label', 'JSON do diagrama')
        source.value = this.data.source
        source.readOnly = this.readOnly
        source.addEventListener('input', () => {
            this.data.source = source.value
            this.forgetGeneration()
            this.changed()
        })
        // Tab indents instead of leaving the field: this is code.
        source.addEventListener('keydown', (e) => {
            if (e.key !== 'Tab' || e.shiftKey || this.readOnly) return
            e.preventDefault()
            document.execCommand('insertText', false, '  ')
        })

        const status = document.createElement('p')
        status.className = 'ak-uml-tool__status'
        status.setAttribute('role', 'status')
        status.textContent = this.status

        const footer = document.createElement('div')
        footer.className = 'flex justify-end px-6 py-4'
        const close = document.createElement('button')
        close.type = 'button'
        close.dataset.close = ''
        close.className = 'rounded-field border border-line-2 bg-surface px-3 py-1.5 text-sm font-medium text-ink hover:bg-canvas'
        close.textContent = 'Fechar'
        footer.append(close)

        const field = document.createElement('div')
        field.className = 'flex min-h-0 flex-1 flex-col px-6 pt-4'
        field.append(source, status)

        shell.append(header, field, footer)
        modal.querySelector('[data-content]').replaceChildren(shell)
        modal.querySelector('[data-loading]')?.classList.add('hidden')
        window.Modal.open('main-modal', true)

        this.source = source
        this.statuses.add(status)
        modal.addEventListener('close', () => {
            this.statuses.delete(status)
            if (this.source === source) this.source = null
        }, {once: true})
        // At the top: focus alone puts the caret, and the scroll, at the end.
        source.focus({preventScroll: true})
        source.setSelectionRange(0, 0)
        source.scrollTop = 0
    }

    paint() {
        // Whatever an older server question answers no longer describes this text.
        this.ticket++
        clearTimeout(this.checkTimer)
        let body
        try {
            body = JSON.parse(this.data.source || '{}')
        } catch (error) {
            this.setStatus(`JSON inválido: ${error.message}`)
            this.mount(null)

            return
        }

        try {
            const {warnings} = this.mount({...body, type: this.data.type, title: this.data.title, logos: this.logos})
            this.setStatus(warnings.length ? `Aviso: ${warnings.join(' ')} Mova um dos elementos para outra célula.` : '')
            this.askServer(body)
        } catch (error) {
            this.setStatus(`${error?.message || 'Não foi possível desenhar este diagrama.'} Confira os ids, os "kind" e as posições "at"; ao salvar, a página lista cada problema.`)
            this.mount(null)
        }
    }

    /**
     * Draws into the preview, or — for a diagram that cannot be drawn — leaves
     * only the "Código" button there: the JSON is the way to fix it, and it
     * has no other door.
     */
    mount(diagram) {
        const stage = this.stage
        if (diagram) return mountUml(stage, diagram, {onCode: () => this.openCode()})

        stage.replaceChildren()
        if (!this.fallbackCode) {
            this.fallbackCode = document.createElement('button')
            this.fallbackCode.type = 'button'
            this.fallbackCode.className = 'ak-uml-tool__code'
            this.fallbackCode.textContent = 'Abrir o código do diagrama'
            this.fallbackCode.addEventListener('click', (e) => {
                e.preventDefault()
                e.stopPropagation()
                this.openCode()
            })
        }
        stage.append(this.fallbackCode)

        return {warnings: []}
    }

    /**
     * Asks the page's validator about the diagram as it stands, and says so
     * when the page would refuse it. Debounced, and only the LAST question's
     * answer is shown — an answer to an older text is dropped. A failed
     * request says nothing: the preview is still right about what it draws.
     */
    askServer(body) {
        if (!this.checkUrl || this.readOnly) return
        const ticket = ++this.ticket
        clearTimeout(this.checkTimer)
        this.checkTimer = setTimeout(async () => {
            try {
                const response = await fetch(this.checkUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    },
                    body: JSON.stringify({type: this.data.type, title: this.data.title, source: this.data.source}),
                })
                if (!response.ok || ticket !== this.ticket) return
                const {problems = [], bindings = [], hints = [], logos = {}} = await response.json()
                if (ticket !== this.ticket) return
                if (problems.length) {
                    this.setStatus(`A página vai mostrar este diagrama como erro: ${problems.join(' · ')}`)

                    return
                }
                // New logos redraw the preview with their badges — without
                // asking again: nothing about the text changed.
                if (JSON.stringify(logos) !== JSON.stringify(this.logos)) {
                    this.logos = logos
                    try {
                        this.mount({...body, type: this.data.type, title: this.data.title, logos})
                    } catch {
                        // It drew a moment ago; the badges are decoration.
                    }
                }
                // The diagram draws; these only concern the catalog
                // relationships it states. A hint is a suggestion, never a
                // refusal: a name is not a binding.
                const notes = [
                    ...(bindings.length ? [`Vínculo sem efeito: ${bindings.join(' · ')}`] : []),
                    ...(hints.length ? [`Dica: ${hints.join(' · ')}`] : []),
                ]
                if (notes.length) this.setStatus([this.status, ...notes].filter(Boolean).join(' '))
            } catch {
                // Unreachable server: the preview stays the only word.
            }
        }, 700)
    }

    save() {
        return {...this.data, source: this.data.source.trim()}
    }

    validate(data) {
        return data.source !== ''
    }
}
