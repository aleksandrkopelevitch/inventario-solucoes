// Editor.js "Tabs" tool (GitBook-style tabs) with genuinely NESTED blocks:
// each tab hosts its own Editor.js with the full palette (headings, lists,
// images, hints, tables, and even other tabs). Serializes to
// {% tabs %}{% tab title="…" %} <blocks> {% endtab %} … {% endtabs %} — the
// blocks ↔ Markdown conversion lives in docs-markdown.js.
//
// Depends on config injected by docs-editor.js:
//   EditorJS   — constructor (avoids re-importing the core in this chunk)
//   getTools() — tools map for the nested editor (includes this tool itself)
//   wire(ed, holder) — wires shortcuts ("/" and Markdown) on the nested editor
//   onChange() — marks the doc as dirty (triggers autosave)
//   i18n       — Editor.js PT-BR dictionary (docs-tools/i18n.js)

const ICON = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="4" x2="9" y2="9"/></svg>'

export default class TabsTool {
    static get toolbox() {
        return {title: 'Tabs', icon: ICON}
    }

    constructor({data, config}) {
        this.config = config || {}
        const items = Array.isArray(data.items) && data.items.length ? data.items : [{title: 'Aba 1', blocks: []}]
        this.seed = items.map((t) => ({
            title: t.title || 'Aba',
            blocks: Array.isArray(t.blocks) ? t.blocks : [],
        }))
        this.tabs = [] // {tabEl, label, panel, holder, editor, blocks}
        this.active = 0
        this.wrapper = null
    }

    render() {
        this.wrapper = document.createElement('div')
        this.wrapper.className = 'ak-tabs'

        this.bar = document.createElement('div')
        this.bar.className = 'ak-tabs__bar'
        this.bar.setAttribute('role', 'tablist')

        this.addBtn = document.createElement('button')
        this.addBtn.type = 'button'
        this.addBtn.className = 'ak-tabs__add'
        this.addBtn.title = 'Adicionar aba'
        this.addBtn.textContent = '+'
        this.addBtn.addEventListener('click', () => {
            const index = this.buildTab({title: `Aba ${this.tabs.length + 1}`, blocks: []})
            this.initEditor(index)
            this.select(index)
            this.tabs[index].label.focus()
            this.config.onChange?.()
        })
        this.bar.appendChild(this.addBtn)

        this.panels = document.createElement('div')
        this.panels.className = 'ak-tabs__panels'

        this.wrapper.append(this.bar, this.panels)
        this.seed.forEach((item) => this.buildTab(item))
        return this.wrapper
    }

    // Called by Editor.js after the block enters the DOM — only then can the
    // nested editors be mounted with correctly measured layout.
    rendered() {
        this.tabs.forEach((_, i) => this.initEditor(i))
        this.select(0)
    }

    buildTab(item) {
        const tab = {blocks: item.blocks || [], editor: null}

        const tabEl = document.createElement('div')
        tabEl.className = 'ak-tabs__tab'
        tabEl.setAttribute('role', 'tab')

        // Drag grip (reorders the tabs).
        const grip = document.createElement('span')
        grip.className = 'ak-tabs__grip'
        grip.draggable = true
        grip.title = 'Arrastar para reordenar'
        grip.textContent = '⠿'
        grip.addEventListener('dragstart', (e) => {
            this.dragFrom = this.tabs.indexOf(tab)
            e.dataTransfer.effectAllowed = 'move'
            e.dataTransfer.setData('text/plain', 'tab')
        })
        tabEl.addEventListener('dragover', (e) => {
            if (this.dragFrom == null) return
            e.preventDefault()
            e.dataTransfer.dropEffect = 'move'
        })
        tabEl.addEventListener('drop', (e) => {
            if (this.dragFrom == null) return
            e.preventDefault()
            this.reorder(this.dragFrom, this.tabs.indexOf(tab))
            this.dragFrom = null
        })

        const label = document.createElement('div')
        label.className = 'ak-tabs__label'
        label.contentEditable = 'true'
        label.spellcheck = false
        label.textContent = item.title || 'Aba'
        label.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); label.blur() }
        })
        label.addEventListener('input', () => this.config.onChange?.())
        label.addEventListener('focus', () => this.select(this.tabs.indexOf(tab)))

        const remove = document.createElement('button')
        remove.type = 'button'
        remove.className = 'ak-tabs__remove'
        remove.title = 'Remover aba'
        remove.innerHTML = '&times;'
        remove.addEventListener('click', (e) => {
            e.stopPropagation()
            this.removeTab(this.tabs.indexOf(tab))
        })

        tabEl.append(grip, label, remove)
        tabEl.addEventListener('mousedown', (e) => {
            if (e.target !== label && e.target !== remove) {
                this.select(this.tabs.indexOf(tab))
            }
        })

        const panel = document.createElement('div')
        panel.className = 'ak-tabs__panel'
        const holder = document.createElement('div')
        holder.className = 'ak-tabs__holder'
        panel.appendChild(holder)

        tab.tabEl = tabEl
        tab.label = label
        tab.panel = panel
        tab.holder = holder

        this.bar.insertBefore(tabEl, this.addBtn)
        this.panels.appendChild(panel)
        this.tabs.push(tab)
        return this.tabs.length - 1
    }

    initEditor(index) {
        const tab = this.tabs[index]
        if (!tab || tab.editor || !this.config.EditorJS) return

        tab.editor = new this.config.EditorJS({
            holder: tab.holder,
            minHeight: 40,
            placeholder: 'Conteúdo da aba…',
            i18n: this.config.i18n,
            tools: this.config.getTools ? this.config.getTools() : {},
            data: {blocks: tab.blocks || []},
            onChange: () => this.config.onChange?.(),
        })

        tab.editor.isReady
            .then(() => this.config.wire?.(tab.editor, tab.holder))
            .catch(() => {})
    }

    select(index) {
        if (index < 0 || index >= this.tabs.length) return
        this.active = index
        this.tabs.forEach((tab, i) => {
            tab.tabEl.classList.toggle('is-active', i === index)
            tab.tabEl.setAttribute('aria-selected', i === index ? 'true' : 'false')
            tab.panel.classList.toggle('hidden', i !== index)
        })
    }

    reorder(from, to) {
        if (from == null || to == null || from === to || from < 0 || to < 0) return
        const [moved] = this.tabs.splice(from, 1)
        this.tabs.splice(to, 0, moved)
        // Re-flows the DOM into the new order (insertBefore/appendChild move the nodes).
        this.tabs.forEach((t) => this.bar.insertBefore(t.tabEl, this.addBtn))
        this.tabs.forEach((t) => this.panels.appendChild(t.panel))
        this.select(to)
        this.config.onChange?.()
    }

    removeTab(index) {
        if (this.tabs.length <= 1) return
        const [tab] = this.tabs.splice(index, 1)
        tab.editor?.destroy?.()
        tab.tabEl.remove()
        tab.panel.remove()
        this.select(Math.max(0, index - 1))
        this.config.onChange?.()
    }

    async save() {
        const items = []
        for (const tab of this.tabs) {
            let blocks = tab.blocks || []
            if (tab.editor) {
                try {
                    blocks = (await tab.editor.save()).blocks
                } catch (_) {
                    // keep the original blocks if the nested save fails
                }
            }
            items.push({title: tab.label.textContent.trim() || 'Aba', blocks})
        }
        return {items}
    }

    // Prevents Editor.js from "flattening" the tool when the tabs are empty.
    static get contentless() {
        return false
    }
}
