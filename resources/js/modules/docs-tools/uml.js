// Editor.js "Diagrama UML" tool — the editing half of `{% uml %}`.
//
// The block holds the diagram's type, its title and its JSON body VERBATIM
// (`source`), and previews it with the reader's own renderer (docs-uml.js's
// mountUml), so what the author sees is what the page will draw, fullscreen
// button included. The JSON is the source of truth on purpose: it is what a
// model writes through the MCP server, and one notation edited by both is the
// only way the two never drift. The SERVER is the judge of a valid diagram
// (App\Support\Documentation\UmlDiagram), and the block ASKS it
// (`umlCheckUrl`, NotebookPageUmlController) a moment after the author stops
// typing: the preview is lenient by design — it draws an element with no
// `at`, an unknown kind, two elements in one cell — and without the question
// the author saved a picture the page then showed as an error box.
//
// Typing in a textarea changes a property, not the DOM tree, so Editor.js
// would never notice it — each field calls `dispatchChange()`. The preview
// repaints for reasons that are not edits, so it sits in a
// `data-mutation-free` box (see .claude/rules/css-and-docs-rendering.md).

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
        this.checkTimer = null
        this.ticket = 0
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

        const source = document.createElement('textarea')
        source.className = 'ak-uml-tool__source'
        source.spellcheck = false
        source.rows = 10
        source.placeholder = 'JSON do diagrama'
        source.value = this.data.source || skeleton(this.data.type)
        if (!this.data.source) this.data.source = source.value

        const status = document.createElement('p')
        status.className = 'ak-uml-tool__status'
        status.setAttribute('role', 'status')

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
            source.readOnly = true
        }

        const changed = () => {
            this.block?.dispatchChange()
            clearTimeout(this.timer)
            this.timer = setTimeout(() => this.paint(stage, status), 250)
        }

        type.addEventListener('change', () => {
            // An untouched skeleton follows the type; anything typed stays.
            if (source.value.trim() === skeleton(this.data.type).trim()) source.value = skeleton(type.value)
            this.data.type = type.value
            this.data.source = source.value
            changed()
        })
        title.addEventListener('input', () => {
            this.data.title = title.value
            changed()
        })
        source.addEventListener('input', () => {
            this.data.source = source.value
            changed()
        })

        head.append(type, title)
        wrap.append(head, source, status, preview)
        // After the block is in the document: the renderer measures text with
        // the font the preview is set in.
        requestAnimationFrame(() => this.paint(stage, status))

        return wrap
    }

    paint(stage, status) {
        // Whatever an older server question answers no longer describes this text.
        this.ticket++
        clearTimeout(this.checkTimer)
        let body
        try {
            body = JSON.parse(this.data.source || '{}')
        } catch (error) {
            status.textContent = `JSON inválido: ${error.message}`
            stage.replaceChildren()

            return
        }

        try {
            const {warnings} = mountUml(stage, {...body, type: this.data.type, title: this.data.title})
            status.textContent = warnings.length ? `Aviso: ${warnings.join(' ')} Mova um dos elementos para outra célula.` : ''
            this.askServer(status)
        } catch (error) {
            status.textContent = `${error?.message || 'Não foi possível desenhar este diagrama.'} Confira os ids, os "kind" e as posições "at"; ao salvar, a página lista cada problema.`
            stage.replaceChildren()
        }
    }

    /**
     * Asks the page's validator about the diagram as it stands, and says so
     * when the page would refuse it. Debounced, and only the LAST question's
     * answer is shown — an answer to an older text is dropped. A failed
     * request says nothing: the preview is still right about what it draws.
     */
    askServer(status) {
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
                const {problems = []} = await response.json()
                if (ticket !== this.ticket || !problems.length) return
                status.textContent = `A página vai mostrar este diagrama como erro: ${problems.join(' · ')}`
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
