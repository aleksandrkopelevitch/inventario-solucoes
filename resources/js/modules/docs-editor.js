// docs-editor.js — GitBook-style block editor for documentation (Editor.js).
//
// - Mounts an Editor.js on each [data-ak-docs-editor], loading the raw
//   Markdown from <textarea data-ak-docs-source> (converted to blocks by docs-markdown).
// - Tabs host nested Editor.js instances (docs-tools/tabs.js) with the whole palette.
// - Saves by serializing blocks -> Markdown+GitBook notation: button, Ctrl/Cmd+S
//   and autosave (debounced). Warns on leaving with unsaved changes.
// - Images: upload via button, paste and drag-drop — served via
//   /files/{id} (Spatie's `docs` collection).
// - "/" at the start of an empty block opens the menu; Markdown ("## ", "- ",
//   "```", "---" …) transforms the block on the spot, also inside tabs.
// - Reordering blocks is drag&drop only (editorjs-drag-drop, wired on both
//   the main editor and the nested tab editors) — "Move up"/"Move down"
//   stay hidden in the block tunes menu (see docs-editor.css).

import * as ajaxModule from './ajax'
import {updateSlots} from './ajax-slot'
import {setButtonLoading} from './button-loading'
import {parse, serialize} from './docs-markdown'
import {EDITOR_I18N} from './docs-tools/i18n'

const initialized = new WeakSet()
const editors = new WeakMap()

let dirty = false
let autosaveTimer = null
let DragDrop = null
// Editing lock, owned here and raised by docs-chat.js while the Documentation
// Assistant is generating a reply: nothing may be saved (button, Ctrl+S or
// autosave) while the user can't see or edit the content. It lives at module
// level, NOT inside mount(), so the lock can go up before the Editor.js chunk
// finishes loading — docs-chat.js locks on page load when it resumes a
// still-generating turn.
let locked = false

/**
 * Blocks/unblocks every save path. Exported (instead of another
 * `window.__akDocs*` global) because it only touches this module's state and
 * the save button — no editor instance needed, so no async mount to wait for.
 */
export function setEditorLocked(on) {
    locked = !!on
    if (locked) clearTimeout(autosaveTimer)

    const btn = document.querySelector('[data-ak-docs-save]')
    if (!btn) return
    if (locked) btn.setAttribute('disabled', 'disabled')
    else btn.removeAttribute('disabled')
}

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
}

// Preset widths for images (%). The image is always centered (see
// docs-editor.css / app.css) — only the width is configurable.
const IMAGE_WIDTHS = [25, 50, 75, 100]
const IMAGE_WIDTH_TITLES = {25: 'Pequeno · 25%', 50: 'Médio · 50%', 75: 'Grande · 75%', 100: 'Total · 100%'}

// Icon (centered rectangle whose width follows the preset) for the block
// tunes menu. `currentColor` inherits the popover item's color.
function imageWidthIcon(pct) {
    const w = Math.round(5 + (pct / 100) * 11)
    const x = Math.round((20 - w) / 2)
    return `<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="${x}" y="6" width="${w}" height="8" rx="1.5" fill="currentColor"/></svg>`
}

// Extends @editorjs/image just to add preset widths: reuses all the original
// tool's upload/paste/drag/caption behavior. The native tunes
// (border/background/stretch) are turned off via `features` in the config
// (they were never persisted by our serializer — they only caused confusion).
// The width becomes an `ak-img--wNN` class on the wrapper (preview in the
// editor) and is saved in `data.width` (serialized as `<figure data-width>`
// by docs-markdown).
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

// Editor.js and its plugins are heavy (~400 kB) and only used on this page, so
// they're loaded on demand (separate chunk) only when an editor is on screen.
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

    // Factory reused by the tabs' nested editors (fresh for each tab).
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
                // byFile: upload of a pasted/chosen file. byUrl: when pasting an
                // image from an external site (the clipboard only carries an
                // <img src="http…">, no blob), the plugin sends the URL here and
                // the server downloads and re-hosts it in the `docs` collection —
                // no hotlinking to an external domain.
                endpoints: {byFile: uploadUrl, byUrl: uploadUrl},
                additionalRequestHeaders: uploadHeaders,
                captionPlaceholder: 'Legenda (opcional)',
                // Native tunes turned off — the only configuration is the width
                // (DocsImageTool's renderSettings). See makeImageTool above.
                features: {border: false, background: false, stretch: false},
            },
        },
        attaches: {
            class: AttachesTool,
            config: {
                field: 'file',
                endpoint: uploadUrl,
                additionalRequestHeaders: uploadHeaders,
                // Este plugin NÃO passa a falha de upload pelo i18n (só o
                // "File title" dele vai) — a mensagem sai de
                // `config.errorMessage`, então a tradução mora aqui e não no
                // EDITOR_I18N, ao contrário da equivalente do Image. Mesma
                // razão pra traduzir em vez de dar um `Toast` nosso:
                // `uploadingFailed()` no plugin é quem remove o loader do
                // bloco. Limites de `UploadDocumentationMediaRequest`.
                errorMessage: 'Não foi possível enviar o arquivo. Use PDF, Office, CSV, TXT, ZIP, JSON, YAML ou MD de até 20 MB.',
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

    // Source for "Copiar Markdown" (docs-copy.js) on the editing screen: serializes
    // the editor's CURRENT state, not the source textarea (which may be stale).
    window.__akDocsGetMarkdown = async () => serialize((await editor.save()).blocks)

    // Replaces the whole editor content with a draft applied from the
    // Documentation Assistant (docs-chat.js) — loads it as blocks, marking it
    // as unsaved (the user still has to Salvar).
    window.__akDocsSetMarkdown = async (md) => {
        await editor.blocks.render({blocks: parse(md)})
        markDirty()
    }

    installGlobalHandlers()
}

/* ------------------------------------------------------------------ */
/*  Save (button, Ctrl/Cmd+S, autosave)                               */
/* ------------------------------------------------------------------ */

let globalHandlersInstalled = false

function installGlobalHandlers() {
    if (globalHandlersInstalled) return
    globalHandlersInstalled = true

    // Save button — captures BEFORE ajax-post (we need to serialize async).
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

    // Ctrl/Cmd+S saves.
    document.addEventListener('keydown', (e) => {
        if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 's') {
            const btn = document.querySelector('[data-ak-docs-save]')
            if (!btn) return
            e.preventDefault()
            save(btn)
        }
    })

    // Leaving with unsaved changes doesn't ASK any more — it saves what it can.
    //
    // This used to be a `beforeunload` returning a value, i.e. the browser's own
    // "Deseja sair desta página?" dialog. It fired on any navigation made inside
    // the autosave window and, because Editor.js normalises content on mount,
    // sometimes with no edit at all: a modal that appears when nothing is at
    // stake only teaches people to dismiss modals. What replaces it is a shorter
    // autosave window (below) plus a save the moment the tab stops being
    // visible, which is when a person switches away or closes it.
    //
    // Deliberately NOT a `sendBeacon` of a pre-serialized snapshot: serializing
    // on every keystroke runs `editor.save()` concurrently with the real save,
    // and a snapshot that loses that race gets sent LATER — overwriting newer
    // content with older. Measured, not theorised: it silently reverted a manual
    // save during a click-through. An awaited save on `visibilitychange` cannot
    // reorder like that; the price is that a hard navigation within the autosave
    // window can still lose the last keystrokes, which is the same exposure as
    // clicking "Leave" on the old dialog.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState !== 'hidden' || !dirty) return

        const btn = document.querySelector('[data-ak-docs-save]')
        if (btn) save(btn, {silent: true})
    })
}

function markDirty() {
    dirty = true
    setStatus('Não salvo')
    clearTimeout(autosaveTimer)
    if (locked) return // no autosave while "Assiste IA" holds the editor
    // 1.2s, down from 2.5s: with the exit dialog gone this window IS the
    // exposure to losing a keystroke, and with the save Toast gone there is no
    // longer a cost to saving more often.
    autosaveTimer = setTimeout(() => {
        const btn = document.querySelector('[data-ak-docs-save]')
        if (btn && dirty) save(btn, {silent: true})
    }, 1200)
}

async function save(btn, {silent = false} = {}) {
    // The button is `disabled` while locked, but Ctrl+S reaches here directly.
    if (locked) return

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
        // No Toast on success, manual save included: the status text beside the
        // button is the confirmation, and it blinks so the change registers
        // without a card sliding over the page every 2.5s of typing.
        setStatus('Salvo ' + timeNow(), {blink: true})
    } catch (error) {
        let message = 'Erro ao salvar a documentação.'
        if (error.response) {
            try {
                message = (await error.response.json()).message ?? message
            } catch (_) {
                // keep the default message
            }
        }
        setStatus('Não salvo')
        Toast.open({content: message, title: 'Atenção', type: 'warning'})
    } finally {
        setButtonLoading(btn, false)
        // A lock may have gone up while this save was in flight (an autosave
        // armed just before "Assiste IA" started): setButtonLoading() re-enables
        // the button unconditionally, which would silently release the lock.
        if (locked) btn.setAttribute('disabled', 'disabled')
    }
}

function timeNow() {
    const d = new Date()
    return d.toLocaleTimeString('pt-BR', {hour: '2-digit', minute: '2-digit'})
}

function setStatus(text, {blink = false} = {}) {
    const el = document.querySelector('[data-ak-docs-status]')
    if (!el) return

    el.textContent = text
    if (!blink) return

    // Restart the animation even when the text is identical (two saves in the
    // same minute read the same): removing the class and reading `offsetWidth`
    // forces the reflow that lets it play again.
    el.classList.remove('is-saved-blink')
    void el.offsetWidth
    el.classList.add('is-saved-blink')
}

/* ------------------------------------------------------------------ */
/*  Shortcuts: "/" opens the menu, Markdown transforms the block      */
/*  Scoped per holder — don't leak between the outer editor and the   */
/*  tabs' nested editors.                                             */
/* ------------------------------------------------------------------ */

function wireShortcuts(holder, editor) {
    holder.setAttribute('data-ak-docs-holder', '')

    if (DragDrop) new DragDrop(editor)

    holder.addEventListener('keydown', (e) => {
        // Only the holder closest to the target handles the event (prevents
        // the outer editor from acting on typing inside a nested tab).
        if (e.target.closest('[data-ak-docs-holder]') !== holder) return

        if (e.key === '/') {
            handleSlash(holder, e)
        } else if (e.key === ' ') {
            handleMarkdownSpace(editor, e)
        } else if (e.key === 'Enter') {
            handleMarkdownEnter(editor, e)
        }
    })

    // Pasting a Markdown/notation block turns it into blocks (capture: before Editor.js).
    holder.addEventListener('paste', (e) => handlePasteMarkdown(holder, editor, e), true)
}

// Detects "block-level" Markdown (multi-line with markers) — single-line
// pastes follow the normal flow (keeps inline formatting).
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
    if (e.target.closest('.ak-tabs__bar')) return // not on the tab title
    if (e.clipboardData?.files?.length) return // leaves image/file to the tool

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
    if (current && current.name === 'code') return // pastes raw inside code

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
        // Removes the empty paragraph where the cursor was (pushed down).
        if (atEmpty) editor.blocks.delete(at + blocks.length)
        markDirty()
    } catch (_) {
        // Divergent API: better not to break; nothing is inserted.
    }
}

// "/" in an empty block opens the insertion toolbox (by clicking THIS editor's "+").
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

// Typing "## " (etc.) at the start of an empty paragraph transforms the block.
function handleMarkdownSpace(editor, e) {
    const editable = e.target.closest('[contenteditable]')
    if (!editable) return

    const shortcut = MD_SPACE[editable.textContent]
    if (!shortcut) return

    convertCurrent(editor, editable, shortcut[0], shortcut[1], e)
}

// "```" (code) and "---"/"***"/"___" (divider) on pressing Enter.
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
        // Divergent API: ignore, the key acts normally.
    }
}
