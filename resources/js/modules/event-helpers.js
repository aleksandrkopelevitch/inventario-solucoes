// Pure event delegation — registered once at module load, works with dynamic content.

document.addEventListener('keypress', (e) => {
    if (e.key !== 'Enter' && e.which !== 13) return

    const trigger = e.target.closest('[data-ak-enter-click]')
    if (!trigger) return

    e.preventDefault()
    document.getElementById(trigger.dataset.akEnterClick)?.click()
})

// No-op — delegation is registered at module load above.
export function init() {}
