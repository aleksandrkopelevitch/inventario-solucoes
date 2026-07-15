import * as ajaxModule from './ajax.js'
import { updateSlots } from './ajax-slot.js'

/**
 * Chat do gerador de flowSpec (F8).
 *
 * Polling: enquanto o thread renderizar o marcador [data-ak-flowspec-poll]
 * (última mensagem é do usuário — o job GenerateFlowspecReply ainda roda),
 * consulta a rota de status; quando `pending` vira false, troca o slot do
 * thread pela resposta. O init() pós-slot-update derruba o timer quando o
 * marcador some. Desiste após MAX_POLL_ATTEMPTS (fila parada, worker caído,
 * sessão expirada) em vez de tentar pra sempre sem nenhum sinal ao usuário.
 *
 * Copiar: [data-ak-flowspec-copy="id-do-pre"] copia o textContent (não
 * innerHTML — o JSON tem `&&` em jsonPath, que viraria &amp;&amp;).
 */

const POLL_INTERVAL = 2500
const MAX_POLL_ATTEMPTS = 240 // ~10min a 2.5s/tick — bem acima do "alguns minutos" esperado
let timer = null
let attempts = 0

document.addEventListener('click', async (e) => {
    const trigger = e.target.closest('[data-ak-flowspec-copy]')
    if (!trigger) return

    const source = document.getElementById(trigger.dataset.akFlowspecCopy)
    if (!source) return

    await navigator.clipboard.writeText(source.textContent)
    Toast.show('JSON copiado — cole no canvas da Digibee.')
})

export function init() {
    const marker = document.querySelector('[data-ak-flowspec-poll]')

    if (!marker) {
        stopPolling()
        return
    }

    if (timer) return

    attempts = 0
    timer = setInterval(poll, POLL_INTERVAL)
}

function stopPolling() {
    if (timer) {
        clearInterval(timer)
        timer = null
    }
}

async function poll() {
    const marker = document.querySelector('[data-ak-flowspec-poll]')

    if (!marker) {
        stopPolling()
        return
    }

    attempts += 1

    if (attempts > MAX_POLL_ATTEMPTS) {
        stopPolling()
        Toast.show('A geração está demorando mais que o esperado — atualize a página para conferir o status.', 'warning')
        return
    }

    try {
        const response = await ajaxModule.init('GET', marker.dataset.akFlowspecPoll)
        const data = await response.json()

        if (!data.pending) {
            updateSlots(data) // reinicializa os módulos; init() encerra o timer
        }
    } catch (error) {
        // falha transitória — o próximo tick tenta de novo, até MAX_POLL_ATTEMPTS
    }
}
