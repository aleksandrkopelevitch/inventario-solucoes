// Editor.js INLINE tool "Valor protegido" — the only inline tool this app
// writes, and the only construct of the documentation dialect that is inline
// (see App\Support\Documentation\SecretText).
//
// It wraps the selection in `<span class="ak-secret-mark">`, which
// docs-markdown.js serializes as {% secret %}…{% endsecret %}. A protected
// value is a token inside a sentence, a table cell or a line of a code sample,
// so a BLOCK tool (the shape hint/tabs/diagram use) could not express it.
//
// What the author sees while editing depends on who they are, and that is
// decided on the server, not here: an admin's editor holds the real values, and
// everyone else's holds `[[SECRET-n]]` markers, restored on save by
// EditsDocumentation::persistDocumentation(). This tool marks text either way —
// an editor who cannot READ a value can still move it, and can still protect a
// new one they just typed.

import {SECRET_CLASS} from './secret-class'

const ICON = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>'

export default class SecretInlineTool {
    static get isInline() {
        return true
    }

    /** Shown in the inline toolbar; also the i18n key under `toolNames`. */
    static get title() {
        return 'Valor protegido'
    }

    /**
     * Without this, Editor.js's sanitizer strips the span on save and the
     * protection is silently gone — the value stays on the page as ordinary
     * text. Same declaration @editorjs/marker makes for its own `<mark>`.
     */
    static get sanitize() {
        return {
            span: {
                class: SECRET_CLASS,
            },
        }
    }

    constructor({api}) {
        this.api = api
        this.tag = 'SPAN'
        this.class = SECRET_CLASS
        this.button = null
    }

    render() {
        this.button = document.createElement('button')
        this.button.type = 'button'
        this.button.classList.add(this.api.styles.inlineToolButton)
        this.button.innerHTML = ICON

        return this.button
    }

    surround(range) {
        if (!range) return

        const marked = this.api.selection.findParentTag(this.tag, this.class)

        if (marked) {
            this.unwrap(marked)

            return
        }

        this.wrap(range)
    }

    wrap(range) {
        const span = document.createElement(this.tag)
        span.classList.add(this.class)
        span.appendChild(range.extractContents())
        range.insertNode(span)

        this.api.selection.expandToTag(span)
    }

    unwrap(marked) {
        this.api.selection.expandToTag(marked)

        const selection = window.getSelection()
        const range = selection.getRangeAt(0)
        const contents = range.extractContents()

        marked.remove()
        range.insertNode(contents)

        selection.removeAllRanges()
        selection.addRange(range)
    }

    checkState() {
        const marked = this.api.selection.findParentTag(this.tag, this.class)

        this.button?.classList.toggle(this.api.styles.inlineToolButtonActive, Boolean(marked))

        return Boolean(marked)
    }
}
