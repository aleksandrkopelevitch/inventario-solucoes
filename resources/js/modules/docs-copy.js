// docs-copy.js — botão "Copiar Markdown" das telas de documentação.
//
// Fonte do Markdown, por superfície:
//   - Edição: window.__akDocsGetMarkdown() (estado ATUAL do editor serializado),
//     registrado por docs-editor.js ao montar.
//   - Read-only (privada/pública): <textarea data-ak-docs-markdown hidden> na página.
//
// Toast é singleton global (ver CLAUDE.md) — sem import.

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

export function init() {} // no-op — listener no nível do módulo (delegação)
