// docs-editor.js — editor de blocos da documentação (Editor.js), estilo GitBook.
//
// - Monta um Editor.js em cada [data-ak-docs-editor], carregando o Markdown cru
//   do <textarea data-ak-docs-source> (convertido para blocos por docs-markdown).
// - Abas hospedam Editor.js aninhados (docs-tools/tabs.js) com a paleta inteira.
// - Salva serializando blocos -> Markdown+notação GitBook: botão, Ctrl/Cmd+S e
//   autosave (debounce). Avisa ao sair com alterações não salvas.
// - Imagens: upload por botão, colar (paste) e arrastar (drag-drop) — servidas
//   por /files/{id} (coleção `docs` do Spatie).
// - "/" no início de um bloco vazio abre o menu; Markdown ("## ", "- ", "```",
//   "---" …) transforma o bloco na hora, também dentro das abas.
// - Reordenar blocos é só por drag&drop (editorjs-drag-drop, ligado tanto no
//   editor principal quanto nos aninhados das abas) — "Move up"/"Move down"
//   ficam escondidos no menu de block tunes (ver docs-editor.css).

import * as ajaxModule from './ajax'
import {updateSlots} from './ajax-slot'
import {parse, serialize} from './docs-markdown'
import {EDITOR_I18N} from './docs-tools/i18n'

const initialized = new WeakSet()
const editors = new WeakMap()

let dirty = false
let autosaveTimer = null
let DragDrop = null

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
}

// Larguras-preset para imagens (%). A imagem fica sempre centralizada (ver
// docs-editor.css / app.css) — só a largura é configurável.
const IMAGE_WIDTHS = [25, 50, 75, 100]
const IMAGE_WIDTH_TITLES = {25: 'Pequeno · 25%', 50: 'Médio · 50%', 75: 'Grande · 75%', 100: 'Total · 100%'}

// Ícone (retângulo centralizado cuja largura acompanha o preset) pro menu de
// tunes do bloco. `currentColor` herda a cor do item do popover.
function imageWidthIcon(pct) {
    const w = Math.round(5 + (pct / 100) * 11)
    const x = Math.round((20 - w) / 2)
    return `<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="${x}" y="6" width="${w}" height="8" rx="1.5" fill="currentColor"/></svg>`
}

// Estende o @editorjs/image só pra adicionar largura-preset: reaproveita todo o
// upload/colar/arrastar/legenda do tool original. As tunes nativas
// (borda/fundo/esticar) são desligadas via `features` no config (elas nunca
// eram persistidas pelo nosso serializer — só confundiam). A largura vira uma
// classe `ak-img--wNN` no wrapper (preview no editor) e é salva em `data.width`
// (serializada como `<figure data-width>` por docs-markdown).
function makeImageTool(Base) {
    return class DocsImageTool extends Base {
        constructor(opts) {
            super(opts)
            const w = Number(opts?.data?.width)
            this._width = IMAGE_WIDTHS.includes(w) ? w : 100
        }

        render() {
            this._wrapper = super.render()
            this._applyWidth()
            return this._wrapper
        }

        _applyWidth() {
            if (!this._wrapper) return
            IMAGE_WIDTHS.forEach((w) => this._wrapper.classList.toggle(`ak-img--w${w}`, this._width === w))
        }

        renderSettings() {
            return IMAGE_WIDTHS.map((w) => ({
                icon: imageWidthIcon(w),
                label: IMAGE_WIDTH_TITLES[w],
                name: `ak-img-w${w}`,
                toggle: 'ak-img-width',
                isActive: this._width === w,
                onActivate: () => {
                    this._width = w
                    this._applyWidth()
                    markDirty()
                },
            }))
        }

        save() {
            const data = super.save()
            data.width = this._width
            return data
        }
    }
}

// Editor.js e seus plugins são pesados (~400 kB) e só usados nesta página, então
// são carregados sob demanda (chunk separado) apenas quando há um editor na tela.
async function loadTools(uploadUrl) {
    const [
        {default: EditorJS},
        {default: Header},
        {default: List},
        {default: Quote},
        {default: CodeTool},
        {default: Delimiter},
        {default: Table},
        {default: ImageTool},
        {default: AttachesTool},
        {default: InlineCode},
        {default: Marker},
        {default: Embed},
        {default: HintTool},
        {default: TabsTool},
        {default: DragDropTune},
    ] = await Promise.all([
        import('@editorjs/editorjs'),
        import('@editorjs/header'),
        import('@editorjs/list'),
        import('@editorjs/quote'),
        import('@editorjs/code'),
        import('@editorjs/delimiter'),
        import('@editorjs/table'),
        import('@editorjs/image'),
        import('@editorjs/attaches'),
        import('@editorjs/inline-code'),
        import('@editorjs/marker'),
        import('@editorjs/embed'),
        import('./docs-tools/hint'),
        import('./docs-tools/tabs'),
        import('editorjs-drag-drop'),
    ])

    DragDrop = DragDropTune

    const DocsImageTool = makeImageTool(ImageTool)
    const uploadHeaders = {'X-CSRF-TOKEN': csrf()}
    const wire = (editor, holder) => wireShortcuts(holder, editor)

    // Fábrica reutilizada pelos editores aninhados das abas (fresh a cada aba).
    const buildTools = () => ({
        header: {class: Header, inlineToolbar: true, config: {levels: [1, 2, 3], defaultLevel: 2}},
        list: {class: List, inlineToolbar: true, config: {defaultStyle: 'unordered'}},
        quote: {class: Quote, inlineToolbar: true},
        code: {class: CodeTool},
        delimiter: {class: Delimiter},
        table: {class: Table, inlineToolbar: true},
        image: {
            class: DocsImageTool,
            config: {
                field: 'file',
                types: 'image/*',
                // byFile: upload de arquivo colado/escolhido. byUrl: ao colar uma
                // imagem de site externo (o clipboard traz só um <img src="http…">,
                // sem blob), o plugin manda a URL pra cá e o servidor baixa e
                // rehospeda na coleção `docs` — nada de hotlink pra domínio externo.
                endpoints: {byFile: uploadUrl, byUrl: uploadUrl},
                additionalRequestHeaders: uploadHeaders,
                captionPlaceholder: 'Legenda (opcional)',
                // Tunes nativas desligadas — a única configuração é a largura
                // (renderSettings do DocsImageTool). Ver makeImageTool acima.
                features: {border: false, background: false, stretch: false},
            },
        },
        attaches: {
            class: AttachesTool,
            config: {
                field: 'file',
                endpoint: uploadUrl,
                additionalRequestHeaders: uploadHeaders,
            },
        },
        embed: {
            class: Embed,
            config: {
                services: {
                    youtube: true,
                    vimeo: true,
                    figma: {
                        regex: /(https?:\/\/(?:www\.)?figma\.com\/(?:file|proto|design|board)\/[^\s]+)/,
                        embedUrl: 'https://www.figma.com/embed?embed_host=share&url=<%= remote_id %>',
                        html: '<iframe style="width:100%;height:450px;" allowfullscreen frameborder="0"></iframe>',
                        height: 450,
                        width: 600,
                        id: (groups) => encodeURIComponent(groups[0]),
                    },
                },
            },
        },
        hint: {class: HintTool},
        tabs: {class: TabsTool, config: {EditorJS, getTools: buildTools, wire, onChange: markDirty, uploadUrl, i18n: EDITOR_I18N}},
        inlineCode: {class: InlineCode},
        marker: {class: Marker},
    })

    return {EditorJS, tools: buildTools()}
}

export function init() {
    document.querySelectorAll('[data-ak-docs-editor]').forEach((holder) => {
        if (initialized.has(holder)) return
        initialized.add(holder)
        mount(holder)
    })
}

async function mount(holder) {
    let config = {}
    try {
        config = JSON.parse(holder.dataset.config || '{}')
    } catch (_) {
        config = {}
    }

    const source = document.querySelector('[data-ak-docs-source]')
    const blocks = parse(source ? source.value : '')

    const {EditorJS, tools} = await loadTools(config.uploadUrl || '')

    const editor = new EditorJS({
        holder,
        autofocus: false,
        placeholder: 'Escreva a documentação ou digite / para inserir um bloco…',
        i18n: EDITOR_I18N,
        tools,
        data: {blocks},
        onChange: markDirty,
    })

    editors.set(holder, editor)
    editor.isReady.then(() => wireShortcuts(holder, editor)).catch(() => {})

    // Fonte do "Copiar Markdown" (docs-copy.js) na tela de edição: serializa o
    // estado ATUAL do editor, não o textarea de origem (que pode estar defasado).
    window.__akDocsGetMarkdown = async () => serialize((await editor.save()).blocks)

    // Substitui todo o conteúdo do editor pelo Markdown gerado pelo "Assiste IA"
    // (docs-ai.js) — carrega como blocos para revisão, marcando como não salvo.
    window.__akDocsSetMarkdown = async (md) => {
        await editor.blocks.render({blocks: parse(md)})
        markDirty()
    }

    installGlobalHandlers()
}

/* ------------------------------------------------------------------ */
/*  Salvar (botão, Ctrl/Cmd+S, autosave)                              */
/* ------------------------------------------------------------------ */

let globalHandlersInstalled = false

function installGlobalHandlers() {
    if (globalHandlersInstalled) return
    globalHandlersInstalled = true

    // Botão Salvar — captura ANTES do ajax-post (precisamos serializar async).
    document.addEventListener(
        'click',
        (e) => {
            const btn = e.target.closest('[data-ak-docs-save]')
            if (!btn) return
            e.preventDefault()
            e.stopPropagation()
            save(btn)
        },
        true
    )

    // Ctrl/Cmd+S salva.
    document.addEventListener('keydown', (e) => {
        if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 's') {
            const btn = document.querySelector('[data-ak-docs-save]')
            if (!btn) return
            e.preventDefault()
            save(btn)
        }
    })

    // Avisa ao sair com alterações não salvas.
    window.addEventListener('beforeunload', (e) => {
        if (!dirty) return
        e.preventDefault()
        e.returnValue = ''
    })
}

function markDirty() {
    dirty = true
    setStatus('Não salvo')
    clearTimeout(autosaveTimer)
    autosaveTimer = setTimeout(() => {
        const btn = document.querySelector('[data-ak-docs-save]')
        if (btn && dirty) save(btn, {silent: true})
    }, 2500)
}

async function save(btn, {silent = false} = {}) {
    const holder = document.querySelector('[data-ak-docs-editor]')
    const editor = holder ? editors.get(holder) : null
    if (!editor) return

    clearTimeout(autosaveTimer)
    setButtonLoading(btn, true)
    setStatus('Salvando…')
    try {
        const output = await editor.save()
        const markdown = serialize(output.blocks)

        const formData = new FormData()
        formData.append('_method', 'PATCH')
        formData.append('documentation', markdown)

        const response = await ajaxModule.init('POST', btn.dataset.action, formData)
        const data = await response.json()

        dirty = false
        updateSlots(data)
        setStatus('Salvo ' + timeNow())
        if (!silent) {
            Toast.open({content: data.message, title: data.title || 'Alerta', type: data.type || 'success'})
        }
    } catch (error) {
        let message = 'Erro ao salvar a documentação.'
        if (error.response) {
            try {
                message = (await error.response.json()).message ?? message
            } catch (_) {
                // mantém a mensagem padrão
            }
        }
        setStatus('Não salvo')
        Toast.open({content: message, title: 'Atenção', type: 'warning'})
    } finally {
        setButtonLoading(btn, false)
    }
}

function timeNow() {
    const d = new Date()
    return d.toLocaleTimeString('pt-BR', {hour: '2-digit', minute: '2-digit'})
}

function setStatus(text) {
    const el = document.querySelector('[data-ak-docs-status]')
    if (el) el.textContent = text
}

function setButtonLoading(button, loading) {
    const spinner = button.querySelector('[data-spinner]')
    const label = button.querySelector('[data-label]')
    if (loading) {
        spinner?.classList.remove('opacity-0')
        spinner?.classList.add('absolute')
        label?.classList.add('opacity-0')
        button.setAttribute('disabled', 'disabled')
        button.classList.add('cursor-progress')
    } else {
        spinner?.classList.add('opacity-0', 'absolute')
        label?.classList.remove('opacity-0')
        button.removeAttribute('disabled')
        button.classList.remove('cursor-progress')
    }
}

/* ------------------------------------------------------------------ */
/*  Atalhos: "/" abre o menu, Markdown transforma o bloco             */
/*  Escopados por holder — não vazam entre o editor externo e os      */
/*  editores aninhados das abas.                                      */
/* ------------------------------------------------------------------ */

function wireShortcuts(holder, editor) {
    holder.setAttribute('data-ak-docs-holder', '')

    if (DragDrop) new DragDrop(editor)

    holder.addEventListener('keydown', (e) => {
        // Só o holder mais próximo do alvo trata o evento (evita o editor
        // externo agir sobre digitação dentro de uma aba aninhada).
        if (e.target.closest('[data-ak-docs-holder]') !== holder) return

        if (e.key === '/') {
            handleSlash(holder, e)
        } else if (e.key === ' ') {
            handleMarkdownSpace(editor, e)
        } else if (e.key === 'Enter') {
            handleMarkdownEnter(editor, e)
        }
    })

    // Colar um bloco de Markdown/notação vira blocos (capture: antes do Editor.js).
    holder.addEventListener('paste', (e) => handlePasteMarkdown(holder, editor, e), true)
}

// Detecta Markdown "de bloco" (multi-linha com marcadores) — pastes de linha
// única seguem o fluxo normal (mantém formatação inline).
function looksLikeMarkdown(t) {
    if (!t || !/\n/.test(t.trim())) return false
    return (
        /(^|\n)\s*(#{1,6}\s|[-*+]\s|\d+\.\s|>\s|```|~~~|\||\{%\s)/.test(t) ||
        /\[[^\]]+\]\([^)]+\)/.test(t) ||
        /<figure|<img\s/i.test(t)
    )
}

function handlePasteMarkdown(holder, editor, e) {
    if (e.target.closest('[data-ak-docs-holder]') !== holder) return
    if (e.target.closest('.ak-tabs__bar')) return // não no título da aba
    if (e.clipboardData?.files?.length) return // deixa imagem/arquivo pro tool

    const text = e.clipboardData?.getData('text/plain') || ''
    if (!looksLikeMarkdown(text)) return

    let index
    let current
    try {
        index = editor.blocks.getCurrentBlockIndex()
        current = editor.blocks.getBlockByIndex(index)
    } catch (_) {
        return
    }
    if (current && current.name === 'code') return // cola cru dentro de code

    const blocks = parse(text)
    if (!blocks.length) return

    e.preventDefault()
    e.stopPropagation()
    insertBlocks(editor, blocks, index, current)
}

function insertBlocks(editor, blocks, index, current) {
    const atEmpty =
        current && current.name === 'paragraph' && (current.holder?.textContent || '').trim() === ''
    const at = typeof index === 'number' ? index : editor.blocks.getBlocksCount()

    try {
        if (typeof editor.blocks.insertMany === 'function') {
            editor.blocks.insertMany(blocks, at)
        } else {
            blocks.forEach((b, i) => editor.blocks.insert(b.type, b.data, {}, at + i, false))
        }
        // Remove o parágrafo vazio onde o cursor estava (empurrado para baixo).
        if (atEmpty) editor.blocks.delete(at + blocks.length)
        markDirty()
    } catch (_) {
        // API divergente: melhor não quebrar; nada é inserido.
    }
}

// "/" num bloco vazio abre a toolbox de inserção (clicando no "+" DESTE editor).
function handleSlash(holder, e) {
    const block = e.target.closest('[contenteditable]')
    if (!block || block.textContent.trim() !== '') return

    const plus = Array.from(holder.querySelectorAll('.ce-toolbar__plus')).find(
        (p) => p.closest('[data-ak-docs-holder]') === holder
    )
    if (!plus) return

    e.preventDefault()
    setTimeout(() => plus.click(), 0)
}

const MD_SPACE = {
    '#': ['header', {level: 1}],
    '##': ['header', {level: 2}],
    '###': ['header', {level: 3}],
    '-': ['list', {style: 'unordered'}],
    '*': ['list', {style: 'unordered'}],
    '1.': ['list', {style: 'ordered'}],
    '[]': ['list', {style: 'checklist'}],
    '[ ]': ['list', {style: 'checklist'}],
    '>': ['quote', {}],
}

// Digitar "## " (etc.) no começo de um parágrafo vazio transforma o bloco.
function handleMarkdownSpace(editor, e) {
    const editable = e.target.closest('[contenteditable]')
    if (!editable) return

    const shortcut = MD_SPACE[editable.textContent]
    if (!shortcut) return

    convertCurrent(editor, editable, shortcut[0], shortcut[1], e)
}

// "```" (code) e "---"/"***"/"___" (divisor) ao apertar Enter.
function handleMarkdownEnter(editor, e) {
    const editable = e.target.closest('[contenteditable]')
    if (!editable) return

    const token = editable.textContent.trim()
    if (token === '```') {
        convertCurrent(editor, editable, 'code', {}, e)
    } else if (/^(---+|\*\*\*+|___+)$/.test(token)) {
        convertCurrent(editor, editable, 'delimiter', {}, e)
    }
}

function convertCurrent(editor, editable, tool, data, e) {
    try {
        const index = editor.blocks.getCurrentBlockIndex()
        const block = editor.blocks.getBlockByIndex(index)
        if (!block || block.name !== 'paragraph') return

        e.preventDefault()
        editable.textContent = ''
        editor.blocks.convert(block.id, tool, data)
        setTimeout(() => editor.caret.setToBlock(index, 'start'), 0)
    } catch (_) {
        // API divergente: ignora, a tecla age normalmente.
    }
}
