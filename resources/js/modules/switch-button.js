// Pure event delegation — registered once at module load, works with dynamic content.

document.addEventListener('click', (e) => {
    const trigger = e.target.closest('[data-ak-switch]')
    if (!trigger) return

    const data = trigger.dataset.akSwitch ? JSON.parse(trigger.dataset.akSwitch) : {}
    toggleElement(trigger, data.mainToggleClasses, data.tickerToggleClasses, data.checkName, e)
})

// No-op — delegation is registered at module load above.
export function init() {}

export function toggleElement(switchElement, mainToggleClasses, tickerToggleClasses, checkName, event) {
    const switchElementTicker = switchElement.children[0]
    const checkbox            = document.getElementsByName(checkName)[0]

    mainToggleClasses.forEach((c) => switchElement.classList.toggle(c))
    tickerToggleClasses.forEach((c) => switchElementTicker.classList.toggle(c))

    checkbox.checked = !checkbox.checked
    event.cancelBubble = true
}
