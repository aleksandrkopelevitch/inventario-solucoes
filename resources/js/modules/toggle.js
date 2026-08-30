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
                // The event's PATH, not just its target. `composedPath()` is
                // built when the event is dispatched, so it still names the
                // popover (and the trigger) even when a handler that ran
                // earlier in the same click DETACHED the node that was
                // clicked — `contains()` on a node the DOM no longer holds is
                // false, which reads as "outside" and closes a popover the
                // user is in the middle of filling in. That is exactly what
                // the ✕ on a chip does (chips.js removes the chip on click),
                // so removing one solution from "Soluções documentadas" shut
                // the panel before it could be saved. `contains()` stays as
                // the fallback for anything that dispatches an event without
                // a composed path.
                const path = typeof ev.composedPath === 'function' ? ev.composedPath() : []
                const hit = (el) => path.includes(el) || el.contains(ev.target)

                // Click on the trigger itself: handleToggleEvent already
                // handles opening/closing. Removes this listener (reopening
                // registers a new one) to avoid accumulating orphan listeners.
                if (hit(trigger)) {
                    document.removeEventListener('click', handleClickOutside)
                    return
                }

                // Click INSIDE the popover: keeps it open and preserves the
                // listener — survives the inner slot being swapped via AJAX
                // (e.g. generate/copy link, toggle coverage), which replaces
                // only the content, not the container captured in
                // `targetElement`.
                if (hit(targetElement)) return

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

// A class can be deferred by suffixing it with `:<milliseconds>` — and that
// syntax has to coexist with Tailwind's own colons, which are variants, not
// delays. Only a suffix that is ENTIRELY digits counts: `hidden:300` waits
// 300ms, while `md:!w-0` / `max-md:!translate-x-0` are ordinary class names.
//
// Splitting on the first `:` instead (as this did until 2026-08-15) turned
// `md:!w-0` into `classList.toggle('md')` after `setTimeout(…, '!w-0')` — the
// responsive class was never touched, so the documentation editor's pages rail
// simply never collapsed, with nothing failing anywhere to say why.
const DELAYED = /^(.+):(\d+)$/

export function toggleElement(elementId, toggleClasses, event) {
    const element            = document.getElementById(elementId)
    const triggerClosedState = document.getElementById(`${elementId}-closed-state`)
    const triggerOpenedState = document.getElementById(`${elementId}-opened-state`)

    if (element) {
        toggleClasses.forEach((toggleClass) => {
            const delayed = toggleClass.match(DELAYED)

            if (delayed) {
                const [, actualClass, timeToWait] = delayed
                setTimeout(() => { element.classList.toggle(actualClass) }, Number(timeToWait))
            } else if (toggleClass) {
                element.classList.toggle(toggleClass)
            }
        })
    }

    // Optional companion elements that flip with the target: `<id>-opened-state`
    // shows while it's open, `<id>-closed-state` while it's closed. Each is
    // toggled on its own — a target may legitimately have only one of them
    // (the docs rail has no "opened" counterpart: its collapse only ADDS a
    // crumb to the top bar, it doesn't swap one label for another).
    triggerClosedState?.classList.toggle('hidden')
    triggerOpenedState?.classList.toggle('hidden')

    event.stopPropagation()
}
