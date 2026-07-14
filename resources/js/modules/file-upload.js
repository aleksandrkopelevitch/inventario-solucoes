// Delegate change event for file inputs inside our component
document.addEventListener('change', (e) => {
    const input = e.target.closest('[data-ak-file-upload] [data-input]')
    if (!input) return

    const root = input.closest('[data-ak-file-upload]')
    if (!root) return

    const placeholder = root.querySelector('[data-placeholder]')
    const previewWrap = root.querySelector('[data-preview]')
    const img = root.querySelector('[data-preview-img]')
    const clearFlag = root.querySelector('[data-clear-flag]')

    if (!previewWrap || !img || !placeholder) return

    const file = input.files && input.files[0]
    if (!file) {
        // No file selected, ensure placeholder is visible
        previewWrap.classList.add('hidden')
        placeholder.classList.remove('hidden')
        if (img.dataset.objectUrl) {
            URL.revokeObjectURL(img.dataset.objectUrl)
            delete img.dataset.objectUrl
        }
        img.removeAttribute('src')
        // If user canceled the picker after having an initial URL, keep placeholder
        if (clearFlag) clearFlag.value = '0'
        return
    }

    const objectUrl = URL.createObjectURL(file)
    img.src = objectUrl
    img.dataset.objectUrl = objectUrl
    if (clearFlag) clearFlag.value = '0'
    placeholder.classList.add('hidden')
    previewWrap.classList.remove('hidden')
}, { capture: true })

// Clear button handler
document.addEventListener('click', (e) => {
    const clearBtn = e.target.closest('[data-ak-file-upload] [data-clear]')
    if (!clearBtn) return

    const root = clearBtn.closest('[data-ak-file-upload]')
    const input = root?.querySelector('[data-input]')
    const placeholder = root?.querySelector('[data-placeholder]')
    const previewWrap = root?.querySelector('[data-preview]')
    const img = root?.querySelector('[data-preview-img]')
    const clearFlag = root?.querySelector('[data-clear-flag]')

    if (!root || !input || !placeholder || !previewWrap || !img) return

    // Reset file input
    input.value = ''
    // Revoke object URL if set
    if (img.dataset.objectUrl) {
        URL.revokeObjectURL(img.dataset.objectUrl)
        delete img.dataset.objectUrl
    }
    img.removeAttribute('src')
    if (clearFlag) clearFlag.value = '1'

    // Toggle UI
    previewWrap.classList.add('hidden')
    placeholder.classList.remove('hidden')

    // Focus back to trigger area for accessibility
    const label = placeholder.querySelector('label[for]')
    label?.focus()
})

export function init() {
    // Initialize previews from initial URLs, if present
    document.querySelectorAll('[data-ak-file-upload][data-initial-url]').forEach((root) => {
        const url = root.getAttribute('data-initial-url')
        const placeholder = root.querySelector('[data-placeholder]')
        const previewWrap = root.querySelector('[data-preview]')
        const img = root.querySelector('[data-preview-img]')
        if (!url || !img || !placeholder || !previewWrap) return
        img.src = url
        placeholder.classList.add('hidden')
        previewWrap.classList.remove('hidden')
    })
}
