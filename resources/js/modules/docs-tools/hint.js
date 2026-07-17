// Editor.js "Hint" tool (GitBook-style callout). Serializes to
// {% hint style="info|warning|danger|success" icon="…" %} … {% endhint %} —
// see docs-markdown.js. The style only defines the color; the icon is a
// freely-chosen outline heroicon (defaulted per style), picked in the
// picker (heroicon-picker.js). The text is rich (contenteditable, accepts
// the inline toolbar).

import {getHeroiconSvg, openHeroiconPicker} from '../heroicon-picker'
import {DEFAULT_HINT_ICON} from './hint-icons'

const STYLES = ['info', 'warning', 'danger', 'success']

const LABELS = {
    info: 'Informação',
    warning: 'Atenção',
    danger: 'Cuidado',
    success: 'Dica',
}

const ICON = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'

export default class HintTool {
    static get toolbox() {
        return {title: 'Hint', icon: ICON}
    }

    static get enableLineBreaks() {
        return true
    }

    constructor({data, api}) {
        this.api = api
        this.data = {
            style: STYLES.includes(data.style) ? data.style : 'info',
            icon: data.icon || '',
            text: data.text || '',
        }
        this.wrapper = null
        this.editable = null
        this.iconBtn = null
    }

    // Effective icon shown: the author's choice, otherwise the style's default.
    resolvedIcon() {
        return this.data.icon || DEFAULT_HINT_ICON[this.data.style] || DEFAULT_HINT_ICON.info
    }

    async renderIcon() {
        const svg = await getHeroiconSvg(this.resolvedIcon())
        if (this.iconBtn) this.iconBtn.innerHTML = svg || ''
    }

    setIcon(name) {
        // Stores empty when it's the style's default — keeps the notation clean.
        this.data.icon = name === DEFAULT_HINT_ICON[this.data.style] ? '' : name
        this.renderIcon()
    }

    render() {
        this.wrapper = document.createElement('div')
        this.wrapper.classList.add('ak-hint')
        this.wrapper.dataset.hintStyle = this.data.style

        // Icon badge — also the trigger for the heroicon picker.
        this.iconBtn = document.createElement('button')
        this.iconBtn.type = 'button'
        this.iconBtn.className = 'ak-hint__icon'
        this.iconBtn.title = 'Escolher ícone'
        this.iconBtn.setAttribute('contenteditable', 'false')
        this.iconBtn.addEventListener('click', (e) => {
            e.preventDefault()
            openHeroiconPicker({
                anchorEl: this.iconBtn,
                current: this.resolvedIcon(),
                onSelect: (name) => this.setIcon(name),
            })
        })
        this.renderIcon()

        this.editable = document.createElement('div')
        this.editable.contentEditable = 'true'
        this.editable.classList.add('ak-hint__text')
        this.editable.innerHTML = this.data.text
        this.editable.dataset.placeholder = 'Texto do aviso…'

        // Enter inserts a <br> break instead of letting the browser create a
        // <div> per line — a <div> doesn't disappear on backspace (the empty
        // line keeps taking up vertical space) and doesn't serialize either
        // (inlineToMd only understands <br>).
        this.editable.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.isComposing) {
                e.preventDefault()
                document.execCommand('insertLineBreak')
            }
        })

        // On clearing everything, collapse residue (empty <br>/<div>) back to
        // the :empty state — so the placeholder reappears and the height
        // returns to normal.
        this.editable.addEventListener('input', () => {
            if (!this.editable.textContent.trim() && !this.editable.querySelector('img')) {
                this.editable.innerHTML = ''
            }
        })

        this.wrapper.appendChild(this.iconBtn)
        this.wrapper.appendChild(this.editable)
        return this.wrapper
    }

    renderSettings() {
        return STYLES.map((style) => ({
            icon: ICON,
            title: LABELS[style],
            isActive: this.data.style === style,
            closeOnActivate: true,
            onActivate: () => {
                this.data.style = style
                this.wrapper.dataset.hintStyle = style
                // If the icon was on the default, it follows the style's new default.
                this.renderIcon()
            },
        }))
    }

    save() {
        return {
            style: this.data.style,
            icon: this.data.icon,
            text: this.editable.innerHTML.trim(),
        }
    }

    static get sanitize() {
        return {
            style: false,
            icon: false,
            text: {br: true, b: {}, i: {}, a: {href: true}, code: {}, mark: {}, u: {}},
        }
    }
}
