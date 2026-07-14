// Pure event delegation — registered once at module load, works with dynamic content.
// Supported trigger events via data-ak-toggle-event: click (default), mouseenter.
// Mouseout handled separately via data-ak-toggle-mouseout attribute.

document.addEventListener('click',      handleToggleEvent)
document.addEventListener('mouseenter', handleToggleEvent, true)

document.addEventListener('mouseout', (e) => {
    if (!(e.target instanceof Element)) return
    const trigger = e.target.closest('[data-ak-toggle][data-ak-toggle-mouseout]')
    if (!trigger) return

    const id            = trigger.dataset.akToggle
    const toggleClasses = (trigger.dataset.akToggleClasses || '').split(' ')
    toggleElement(id, toggleClasses, e)
})

function handleToggleEvent(e) {
    if (!(e.target instanceof Element)) return
    const trigger = e.target.closest('[data-ak-toggle]')
    if (!trigger) return

    const configuredEvent = trigger.dataset.akToggleEvent || 'click'
    if (configuredEvent !== e.type) return
    if (trigger.hasAttribute('data-ak-toggle-fired')) return

    const id            = trigger.dataset.akToggle
    const toggleClasses = (trigger.dataset.akToggleClasses || '').split(' ')

    toggleElement(id, toggleClasses, e)

    if (trigger.hasAttribute('data-ak-toggle-once')) {
        trigger.setAttribute('data-ak-toggle-fired', '')
    }

    if (trigger.dataset.akToggleBlur === 'true') {
        const targetElement = document.getElementById(id)

        // Só arma o fechamento-ao-clicar-fora quando ESTE clique de fato ABRIU o
        // alvo (`hidden` acabou de sair). Se o clique FECHOU (o toggle inverte a
        // cada clique no gatilho), não registra nada — senão um clique fora
        // posterior inverteria `hidden` de volta, reabrindo o popover a cada
        // clique na tela. Também evita empilhar um listener novo a cada abre/fecha.
        if (targetElement && !targetElement.classList.contains('hidden')) {
            const handleClickOutside = (ev) => {
                // Clique no próprio gatilho: o handleToggleEvent já cuida de
                // abrir/fechar. Retira este listener (a reabertura registra um
                // novo) para não acumular listeners órfãos.
                if (trigger.contains(ev.target)) {
                    document.removeEventListener('click', handleClickOutside)
                    return
                }

                // Clique DENTRO do popover: mantém aberto e preserva o listener
                // — sobrevive à troca do slot interno via AJAX (ex.: gerar/copiar
                // link, alternar cobertura), que substitui só o conteúdo, não o
                // container capturado em `targetElement`.
                if (targetElement.contains(ev.target)) return

                // Clique fora: fecha EXPLICITAMENTE (nunca inverte) e só se ainda
                // estiver aberto, então listeners empilhados não podem reabrir.
                document.removeEventListener('click', handleClickOutside)
                if (!targetElement.classList.contains('hidden')) {
                    toggleElement(id, toggleClasses, ev)
                }
            }
            // Adiado para o próprio clique de abertura não fechar imediatamente.
            setTimeout(() => document.addEventListener('click', handleClickOutside), 0)
        }
    }
}

// No-op — delegation is registered at module load above.
// Kept so initAllModules() can call it safely after slot updates.
export function init() {}

export function toggleElement(elementId, toggleClasses, event) {
    const element            = document.getElementById(elementId)
    const triggerClosedState = document.getElementById(`${elementId}-closed-state`)
    const triggerOpenedState = document.getElementById(`${elementId}-opened-state`)

    if (element) {
        toggleClasses.forEach((toggleClass) => {
            if (toggleClass.includes(':')) {
                const [actualClass, timeToWait] = toggleClass.split(':')
                setTimeout(() => { element.classList.toggle(actualClass) }, timeToWait)
            } else {
                element.classList.toggle(toggleClass)
            }
        })
    }

    if (triggerClosedState && triggerOpenedState) {
        triggerClosedState.classList.toggle('hidden')
        triggerOpenedState.classList.toggle('hidden')
    }

    event.stopPropagation()
}
