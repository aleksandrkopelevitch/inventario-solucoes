// docs-code.js — wraps read-only documentation code blocks (`.html-content
// pre`, rendered by GitbookRenderer from fenced ``` blocks) in a permanent
// dark panel with a header bar (label + an ALWAYS-VISIBLE "Copiar" button —
// not hover-reveal), matching the approved documentation model exactly.
// Scoped to [data-ak-docs-content] so it never touches the other
// `.html-content` consumers (F3 canvas node comments, flowSpec chat replies).
//
// Structural (wraps each <pre> once), so it needs a real init() with a
// WeakSet, unlike the page-level "Copiar Markdown" button (docs-copy.js),
// which is pure delegation.

const enhanced = new WeakSet()

const COPY_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>'

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
    document.querySelectorAll('[data-ak-docs-content] pre').forEach((pre) => {
        if (enhanced.has(pre)) return
        enhanced.add(pre)

        // Captured before the panel/button markup is built — otherwise the
        // button's own label would leak into the copied text.
        pre.dataset.akCode = pre.textContent
        buildPanel(pre)
    })
}
