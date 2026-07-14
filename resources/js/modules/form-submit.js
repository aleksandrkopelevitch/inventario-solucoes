// Listener registered once at module load — not inside init() to avoid accumulation
// when initAllModules() is called after slot updates.

document.addEventListener('submit', (e) => {
    const form = e.target.closest('form')
    if (!form) return

    const submitButton = e.submitter || form.querySelector('button[type="submit"]')
    if (!submitButton) return

    const spinner = submitButton.querySelector('[data-spinner]')
    const label   = submitButton.querySelector('[data-label]')

    submitButton.setAttribute('disabled', 'disabled')
    submitButton.dataset.state = 'loading'

    if (spinner) {
        spinner.classList.remove('opacity-0')
        spinner.classList.add('absolute')
    }

    if (label) {
        label.classList.remove('hidden')
        label.classList.remove('opacity-100')
        label.classList.add('opacity-0')
    }
}, { capture: true })

// No-op — listener is registered at module load above.
export function init() {}
