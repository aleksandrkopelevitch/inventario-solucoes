import * as ajaxModule from './ajax.js'

/**
 * "Assiste IA" da documentação.
 *
 * Ao clicar em "Gerar rascunho" (no side-panel), coleta o prompt, os documentos
 * de contexto marcados e o Markdown ATUAL do editor (window.__akDocsGetMarkdown),
 * dispara a geração (job assíncrono) e fecha o painel. Enquanto o job roda, faz
 * polling na URL de status; ao concluir, carrega o Markdown gerado no editor
 * (window.__akDocsSetMarkdown) para revisão — nada é salvo automaticamente.
 * Desiste após MAX_POLL_ATTEMPTS com um Toast, em vez de tentar pra sempre.
 */

const POLL_INTERVAL = 2500
const MAX_POLL_ATTEMPTS = 240 // ~10min a 2.5s/tick
let timer = null
let attempts = 0
// Markdown do editor no momento do submit — o rascunho é gerado a partir dele.
// Se o editor mudar durante a geração (o painel fecha e o editor fica editável),
// carregar o rascunho apagaria essas edições, então confirmamos antes.
let submittedSnapshot = ''

document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-ak-docs-ai-generate]')
    if (!btn) return
    e.preventDefault()
    generate(btn)
})

async function generate(btn) {
    const prompt = (document.querySelector('[data-ak-docs-ai-prompt]')?.value || '').trim()
    if (!prompt) {
        Toast.show('Escreva o que a IA deve fazer.', 'warning')
        return
    }

    const mediaIds = Array.from(document.querySelectorAll('[data-ak-context-doc]:checked')).map((c) => c.value)
    const existing = window.__akDocsGetMarkdown ? await window.__akDocsGetMarkdown() : ''
    submittedSnapshot = existing

    const formData = new FormData()
    formData.append('prompt', prompt)
    formData.append('existing_content', existing)
    mediaIds.forEach((id) => formData.append('media_ids[]', id))

    setButtonLoading(btn, true)
    try {
        const response = await ajaxModule.init('POST', btn.dataset.action, formData)
        const data = await response.json()

        closePanel()
        showStatus(true)
        startPolling(data.pollUrl)
    } catch (error) {
        let message = 'Não consegui iniciar a geração.'
        if (error.response) {
            try {
                message = (await error.response.json()).message ?? message
            } catch (_) {
                // mantém a mensagem padrão
            }
        }
        Toast.open({content: message, title: 'Atenção', type: 'warning'})
    } finally {
        setButtonLoading(btn, false)
    }
}

function startPolling(pollUrl) {
    stopPolling()
    attempts = 0
    timer = setInterval(() => poll(pollUrl), POLL_INTERVAL)
}

function stopPolling() {
    if (timer) {
        clearInterval(timer)
        timer = null
    }
}

async function poll(pollUrl) {
    attempts += 1

    if (attempts > MAX_POLL_ATTEMPTS) {
        stopPolling()
        showStatus(false)
        Toast.show('A geração está demorando mais que o esperado — tente novamente.', 'warning')
        return
    }

    try {
        const response = await ajaxModule.init('GET', pollUrl)
        const data = await response.json()

        if (data.pending) return

        stopPolling()
        showStatus(false)

        if (data.failed) {
            Toast.open({content: data.error || 'Falha ao gerar a documentação.', title: 'Atenção', type: 'error'})
            return
        }

        if (window.__akDocsSetMarkdown) {
            // O editor ficou editável durante a geração. Se o conteúdo atual
            // divergir do snapshot do submit, o usuário editou nesse meio-tempo —
            // carregar o rascunho apagaria essas edições, então confirmamos.
            const current = window.__akDocsGetMarkdown ? await window.__akDocsGetMarkdown() : submittedSnapshot
            if (current.trim() !== submittedSnapshot.trim()) {
                const replace = window.confirm(
                    'Você editou o conteúdo enquanto a IA gerava o rascunho. Substituir suas edições pelo rascunho gerado?',
                )
                if (!replace) {
                    Toast.show('Rascunho descartado — suas edições foram mantidas.')
                    return
                }
            }

            await window.__akDocsSetMarkdown(data.result || '')
        }
        Toast.show('Rascunho gerado — revise e salve.')
    } catch (error) {
        // falha transitória — o próximo tick tenta de novo, até MAX_POLL_ATTEMPTS
    }
}

function closePanel() {
    document.querySelector('[data-ak-panel-close]')?.click()
}

function showStatus(on) {
    const el = document.querySelector('[data-ak-docs-ai-status]')
    if (!el) return
    el.classList.toggle('hidden', !on)
    el.classList.toggle('inline-flex', on)
}

function setButtonLoading(button, loading) {
    const spinner = button.querySelector('[data-spinner]')
    const label = button.querySelector('[data-label]')
    if (loading) {
        spinner?.classList.remove('opacity-0')
        label?.classList.add('opacity-0')
        button.setAttribute('disabled', 'disabled')
        button.classList.add('cursor-progress')
    } else {
        spinner?.classList.add('opacity-0')
        label?.classList.remove('opacity-0')
        button.removeAttribute('disabled')
        button.classList.remove('cursor-progress')
    }
}

// Delegação pura no nível do módulo — init() é no-op (mantém a interface de globalModules).
export function init() {}
