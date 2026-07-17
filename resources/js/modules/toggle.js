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

        // Only arms the close-on-outside-click when THIS click actually
        // OPENED the target (`hidden` was just removed). If the click
        // CLOSED it (the toggle flips on every click on the trigger), it
        // registers nothing — otherwise a later outside click would flip
        // `hidden` back, reopening the popover on every click on the page.
        // Also avoids stacking a new listener on every open/close.
        if (targetElement && !targetElement.classList.contains('hidden')) {
            const handleClickOutside = (ev) => {
                // Click on the trigger itself: handleToggleEvent already
                // handles opening/closing. Removes this listener (reopening
                // registers a new one) to avoid accumulating orphan listeners.
                if (trigger.contains(ev.target)) {
                    document.removeEventListener('click', handleClickOutside)
                    return
                }

                // Click INSIDE the popover: keeps it open and preserves the
                // listener — survives the inner slot being swapped via AJAX
                // (e.g. generate/copy link, toggle coverage), which replaces
                // only the content, not the container captured in
                // `targetElement`.
                if (targetElement.contains(ev.target)) return

                // Click outside: closes EXPLICITLY (never flips) and only if
                // still open, so stacked listeners can't reopen it.
                document.removeEventListener('click', handleClickOutside)
                if (!targetElement.classList.contains('hidden')) {
                    toggleElement(id, toggleClasses, ev)
                }
            }
            // Deferred so the opening click itself doesn't close it immediately.
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
