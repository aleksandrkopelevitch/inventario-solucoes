// Pure event delegation — registered once at module load, works with dynamic content.

document.addEventListener('click', (e) => {
    const trigger = e.target.closest('[data-ak-url]')
    if (!trigger) return

    const url = trigger.dataset.akUrl
    if (url) {
        window.location.replace(url)
        e.stopPropagation()
    }
})

// No-op — delegation is registered at module load above.
export function init() {}
