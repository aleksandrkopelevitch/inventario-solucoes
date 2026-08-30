// docs-code.js — wraps read-only documentation code blocks (`.html-content
// pre`, rendered by GitbookRenderer from fenced ``` blocks) in a permanent
// panel with a header bar (label + an ALWAYS-VISIBLE "Copiar" button — not
// hover-reveal), and paints the code with highlight.js.
// Scoped to [data-ak-docs-content] so it never touches the other
// `.html-content` consumers (F3 canvas node comments, flowSpec chat replies).
//
// Structural (wraps each <pre> once), so it needs a real init() with a
// WeakSet, unlike the page-level "Copiar Markdown" button (docs-copy.js),
// which is pure delegation.

const enhanced = new WeakSet()

const COPY_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>'

// The grammars are a chunk of their own (see docs-highlight.js) and this is
// where it gets fetched — once per page load, and only from a page that has
// at least one code block. Held as the PROMISE, not the module, so several
// init() passes (a slot swap mid-flight) share one request.
let highlighter = null

function loadHighlighter() {
    if (!highlighter) highlighter = import('./docs-highlight.js')

    return highlighter
}

function buildPanel(pre) {
    const panel = document.createElement('div')
    panel.className = 'ak-code-panel'

    const header = document.createElement('div')
    header.className = 'ak-code-header'
    header.innerHTML = `
        <span class="ak-code-label">Código</span>
        <button type="button" class="ak-code-copy" aria-label="Copiar código">
            ${COPY_ICON}<span data-label>Copiar</span>
        </button>
    `

    pre.parentNode.insertBefore(panel, pre)
    panel.appendChild(header)
    panel.appendChild(pre)

    return panel
}

document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.ak-code-copy')
    if (!btn) return
    e.preventDefault()

    const pre = btn.closest('.ak-code-panel')?.querySelector('pre')
    const code = pre?.dataset.akCode ?? ''
    const label = btn.querySelector('[data-label]')

    try {
        await navigator.clipboard.writeText(code)
        btn.classList.add('is-copied')
        label.textContent = 'Copiado!'
        setTimeout(() => {
            btn.classList.remove('is-copied')
            label.textContent = 'Copiar'
        }, 1500)
    } catch {
        window.Toast?.show('Não foi possível copiar o código.', 'error')
    }
})

export function init() {
    const blocks = Array.from(document.querySelectorAll('[data-ak-docs-content] pre'))
        .filter((pre) => !enhanced.has(pre))

    if (blocks.length === 0) return

    blocks.forEach((pre) => {
        enhanced.add(pre)

        // Captured before the panel/button markup is built — otherwise the
        // button's own label would leak into the copied text. It is also what
        // makes "Copiar" immune to the highlighting below, which replaces the
        // <code> element's children with a tree of colored spans.
        pre.dataset.akCode = pre.textContent
        buildPanel(pre)
    })

    loadHighlighter()
        .then(({highlightBlock}) => {
            blocks.forEach((pre) => {
                const language = highlightBlock(pre)
                if (!language) return

                const label = pre.closest('.ak-code-panel')?.querySelector('.ak-code-label')
                if (label) label.textContent = language
            })
        })
        .catch(() => {
            // Highlighting is decoration: the panel, its "Código" label and
            // the copy button are already in place and stay working.
        })
}
