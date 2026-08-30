// Editor.js "Code" tool — @editorjs/code plus the one thing a Markdown fence
// has and it doesn't: a LANGUAGE.
//
// Upstream's data is `{code}` and nothing else, so a page that came in as
// ```xml went back out as ``` on the first save — see the note in
// docs-markdown.js. Highlighting the read-only view (docs-highlight.js) turned
// that from invisible to the difference between a colored block and a grey
// one, so the token has to survive the round trip, and someone writing a new
// block has to be able to set it.
//
// Subclassed rather than reimplemented: Tab-to-indent, the paste handling that
// turns a copied <code> element into a block, and the toolbox entry are all
// upstream's, and none of them care about the language.

import CodeTool from '@editorjs/code'

import {normalizeLanguage} from '../docs-markdown'

const DATALIST_ID = 'ak-code-languages'

// The fence tokens worth offering. Not a whitelist — the field takes anything
// `normalizeLanguage()` accepts, since a corpus can always grow a language the
// list never heard of; these are just the ones already in this one plus the
// grammars docs-highlight.js ships.
const SUGGESTIONS = [
    'bash', 'csharp', 'css', 'diff', 'dockerfile', 'html', 'http', 'ini',
    'java', 'javascript', 'json', 'markdown', 'php', 'python', 'sql',
    'typescript', 'xml', 'yaml',
]

/** One shared <datalist> for every code block on the page. */
function ensureDatalist() {
    if (document.getElementById(DATALIST_ID)) return

    const list = document.createElement('datalist')
    list.id = DATALIST_ID
    SUGGESTIONS.forEach((name) => {
        const option = document.createElement('option')
        option.value = name
        list.appendChild(option)
    })
    document.body.appendChild(list)
}

export default class DocsCodeTool extends CodeTool {
    constructor(options) {
        super(options)

        this.language = normalizeLanguage(options.data?.language)
        // Editor.js only notices a block changed when its DOM mutates, and
        // typing in an <input> mutates a property, not the tree — without this
        // handle the language would be set and then never autosaved.
        this.blockApi = options.block
        this.docsReadOnly = options.readOnly
    }

    render() {
        ensureDatalist()

        const holder = document.createElement('div')
        holder.className = 'ak-code-block'
        holder.appendChild(this.renderLanguageBar())
        holder.appendChild(super.render())

        return holder
    }

    renderLanguageBar() {
        const bar = document.createElement('div')
        bar.className = 'ak-code-block__bar'

        const input = document.createElement('input')
        input.type = 'text'
        input.className = 'ak-code-block__lang'
        input.value = this.language
        input.placeholder = 'linguagem'
        input.setAttribute('list', DATALIST_ID)
        input.setAttribute('aria-label', 'Linguagem do bloco de código')
        input.spellcheck = false
        input.autocomplete = 'off'
        if (this.docsReadOnly) input.readOnly = true

        input.addEventListener('input', () => {
            this.language = normalizeLanguage(input.value)
            this.blockApi?.dispatchChange()
        })
        // Normalising on the way out rather than on every keystroke: rewriting
        // the field under the caret while someone types "JSON" is how you end
        // up unable to type a capital letter at all.
        input.addEventListener('blur', () => {
            input.value = this.language
        })

        bar.appendChild(input)

        return bar
    }

    // `wrapper` is the element render() returned, one level above upstream's.
    // Its own save() only ever does `querySelector('textarea')`, so it would
    // work through the extra div too — spelled out here because the pair
    // (code + language) is the whole point of this subclass.
    save(wrapper) {
        return {
            code: wrapper.querySelector('textarea')?.value ?? '',
            language: this.language,
        }
    }
}
