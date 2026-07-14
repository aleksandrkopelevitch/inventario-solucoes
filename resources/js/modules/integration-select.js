// Seleção de uma integração na lista do detalhe da solução
// (`Solutions\IntegrationsMap`). Destaca a linha clicada (aria-pressed) e
// dispara `ak:integration-selected` (bubbling) com o grafo resolvido daquela
// integração — quem desenha é `integration-viz.js`, mantendo este módulo
// responsável só pela seleção. Puro event delegation — cliques no botão de
// lixeira (que dispara AJAX) são ignorados para não selecionar junto.

document.addEventListener('click', (e) => {
    const row = e.target.closest('[data-ak-integration-select]')
    if (!row) return
    if (e.target.closest('button, a')) return // lixeira/form de criação não selecionam

    selectRow(row)
})

document.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' && e.key !== ' ') return

    const row = e.target?.closest?.('[data-ak-integration-select]')
    if (!row || e.target !== row) return

    e.preventDefault()
    selectRow(row)
})

function selectRow(row) {
    const list = row.closest('[data-ak-integration-list]') ?? document
    list.querySelectorAll('[data-ak-integration-select]').forEach((el) => el.setAttribute('aria-pressed', 'false'))
    row.setAttribute('aria-pressed', 'true')

    const name = row.getAttribute('data-integration-name') ?? ''
    const slug = row.getAttribute('data-ak-integration-select') ?? ''
    let graph = null
    const raw = row.getAttribute('data-integration-graph')
    if (raw) {
        try {
            graph = JSON.parse(raw)
        } catch {
            graph = null
        }
    }

    row.dispatchEvent(new CustomEvent('ak:integration-selected', {
        bubbles: true,
        detail: { name, slug, graph },
    }))
}

export function init() {} // no-op — listeners são de nível de módulo (delegação)
