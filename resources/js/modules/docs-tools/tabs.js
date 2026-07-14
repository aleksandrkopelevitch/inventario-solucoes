// Ferramenta Editor.js "Tabs" (abas estilo GitBook) com blocos ANINHADOS de
// verdade: cada aba hospeda o seu próprio Editor.js com a paleta completa
// (títulos, listas, imagens, hints, tabelas, e até outras abas). Serializa para
// {% tabs %}{% tab title="…" %} <blocos> {% endtab %} … {% endtabs %} — a
// conversão dos blocos ↔ Markdown fica em docs-markdown.js.
//
// Depende de config injetada por docs-editor.js:
//   EditorJS   — construtor (evita reimportar o core neste chunk)
//   getTools() — mapa de tools p/ o editor aninhado (inclui esta própria tool)
//   wire(ed, holder) — liga atalhos ("/" e Markdown) no editor aninhado
//   onChange() — marca a doc como suja (dispara autosave)
//   i18n       — dicionário PT-BR do Editor.js (docs-tools/i18n.js)

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

    // Chamado pelo Editor.js após o bloco entrar no DOM — só então dá pra
    // montar os editores aninhados com layout medido corretamente.
    rendered() {
        this.tabs.forEach((_, i) => this.initEditor(i))
        this.select(0)
    }

    buildTab(item) {
        const tab = {blocks: item.blocks || [], editor: null}

        const tabEl = document.createElement('div')
        tabEl.className = 'ak-tabs__tab'
        tabEl.setAttribute('role', 'tab')

        // Grip de arraste (reordena as abas).
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
        // Reflui o DOM na nova ordem (insertBefore/appendChild movem os nós).
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
                    // mantém os blocos originais se o save aninhado falhar
                }
            }
            items.push({title: tab.label.textContent.trim() || 'Aba', blocks})
        }
        return {items}
    }

    // Impede o Editor.js de "achatar" a tool quando as abas estão vazias.
    static get contentless() {
        return false
    }
}
