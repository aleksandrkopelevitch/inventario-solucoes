// Click events are delegated — no guards needed, works with dynamic content.
// init() handles selectedOnInit only, using WeakSet to avoid re-running on slot updates.

const initialized = new WeakSet()
const NEXT_KEYS = ['ArrowRight', 'ArrowDown']
const PREV_KEYS = ['ArrowLeft', 'ArrowUp']

document.addEventListener('click', (e) => {
    const trigger = e.target.closest('[data-ak-tabs]')
    if (!trigger) return

    const data = trigger.dataset.akTabs ? JSON.parse(trigger.dataset.akTabs) : {}
    if (!data.targetId || !data.targetContainerId) return

    closeAllTabs(data.targetContainerId)
    unmarkAllTriggers(data.targetContainerId, data)
    markSelectedTrigger(trigger, data)
    openTab(data.targetId)
})

// Roving-tabindex keyboard nav: Arrow keys / Home / End move between triggers
// that share the same targetContainerId, matching the native tablist pattern.
document.addEventListener('keydown', (e) => {
    if (![...NEXT_KEYS, ...PREV_KEYS, 'Home', 'End'].includes(e.key)) return

    const trigger = e.target.closest('[data-ak-tabs]')
    if (!trigger) return

    const data = trigger.dataset.akTabs ? JSON.parse(trigger.dataset.akTabs) : {}
    if (!data.targetContainerId) return

    const group = siblingTriggers(data.targetContainerId)
    const currentIndex = group.indexOf(trigger)
    if (currentIndex === -1) return

    let nextIndex = currentIndex
    if (NEXT_KEYS.includes(e.key)) nextIndex = (currentIndex + 1) % group.length
    else if (PREV_KEYS.includes(e.key)) nextIndex = (currentIndex - 1 + group.length) % group.length
    else if (e.key === 'Home') nextIndex = 0
    else if (e.key === 'End') nextIndex = group.length - 1

    e.preventDefault()
    group[nextIndex].focus()
    group[nextIndex].click()
})

function siblingTriggers(targetContainerId) {
    return Array.from(document.querySelectorAll('[data-ak-tabs]')).filter((el) => {
        const data = el.dataset.akTabs ? JSON.parse(el.dataset.akTabs) : {}
        return data.targetContainerId === targetContainerId
    })
}

export function init() {
    document.querySelectorAll('[data-ak-tabs]').forEach((trigger) => {
        if (initialized.has(trigger)) return
        initialized.add(trigger)

        const data = trigger.dataset.akTabs ? JSON.parse(trigger.dataset.akTabs) : {}
        if (data.selectedOnInit === true) {
            closeAllTabs(data.targetContainerId)
            unmarkAllTriggers(data.targetContainerId, data)
            markSelectedTrigger(trigger, data)
            openTab(data.targetId)
        }
    })
}

export function openTab(targetId) {
    const tab = document.getElementById(targetId)
    if (tab) tab.classList.remove('hidden')
}

export function closeAllTabs(targetContainerId) {
    const container = document.getElementById(targetContainerId)
    if (!container) return

    Array.from(container.children).forEach((tab) => tab.classList.add('hidden'))
}

export function markSelectedTrigger(trigger, data) {
    data.inactiveClasses.forEach((c) => trigger.classList.remove(c))
    data.activeClasses.forEach((c) => trigger.classList.add(c))

    trigger.setAttribute('aria-selected', 'true')
    trigger.setAttribute('tabindex', 0)

    trigger.parentNode.scrollLeft = trigger.offsetLeft
    trigger.parentNode.scrollTop = trigger.offsetTop
}

export function unmarkAllTriggers(targetContainerId, data) {
    document.querySelectorAll('[data-ak-tabs]').forEach((trigger) => {
        const triggerData = trigger.dataset.akTabs ? JSON.parse(trigger.dataset.akTabs) : {}
        if (triggerData.targetContainerId !== targetContainerId) return

        data.activeClasses.forEach((c) => trigger.classList.remove(c))
        data.inactiveClasses.forEach((c) => trigger.classList.add(c))

        trigger.setAttribute('aria-selected', 'false')
        trigger.setAttribute('tabindex', -1)
    })
}
