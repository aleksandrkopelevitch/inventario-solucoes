// docs-copy.js — "Copiar Markdown" button on documentation screens.
//
// Markdown source, per surface:
//   - Editing: window.__akDocsGetMarkdown() (serialized CURRENT editor state),
//     registered by docs-editor.js on mount.
//   - Read-only (private/public): <textarea data-ak-docs-markdown hidden> on the page.
//
// Toast is a global singleton (see CLAUDE.md) — no import.

document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-ak-docs-copy]')
    if (!btn) return
    e.preventDefault()

    try {
        let markdown = ''
        if (typeof window.__akDocsGetMarkdown === 'function') {
            markdown = await window.__akDocsGetMarkdown()
        } else {
            const src = document.querySelector('[data-ak-docs-markdown]')
            markdown = src ? src.value : ''
        }

        await navigator.clipboard.writeText(markdown || '')
        Toast.show('Markdown copiado.')
    } catch {
        Toast.show('Não foi possível copiar o Markdown.', 'error')
    }
})

export function init() {} // no-op — listener at module level (delegation)
