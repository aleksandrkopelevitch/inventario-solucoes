// docs-share.js — generates/revokes/copies the documentation's public
// ("magic link") link AND rotates the caderno's secret code, from the
// Notebooks\SharePanel panel in the toolbar. Both live here because both are
// the same panel and the same question: who can read what of this caderno.
// Delegation on `document` (not per-element) because `ajax-slot.js` swaps
// the whole container (`docs-share-slot`) on every mutation. Follows the
// same pattern as the other auto-persistence modules (fetch + updateSlots + Toast).
import {updateSlots} from './ajax-slot'

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content
}

function mutate(url, method) {
    return fetch(url, {
        method,
        headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrf(),
        },
    })
        .then((r) => r.json())
        .then((data) => {
            updateSlots(data)
            if (data.message) Toast.show(data.message, data.type ?? 'success')
        })
        .catch(() => Toast.show('Erro ao atualizar o link público.', 'warning'))
}

document.addEventListener('click', (e) => {
    const panel = e.target.closest('[data-ak-share-panel]')
    if (!panel) return

    if (e.target.closest('[data-ak-share-generate]')) {
        mutate(panel.dataset.shareUrl, 'POST')
        return
    }

    if (e.target.closest('[data-ak-share-revoke]')) {
        if (!window.confirm('Revogar o acesso público? O link atual deixará de funcionar.')) return
        mutate(panel.dataset.unshareUrl, 'DELETE')
        return
    }

    if (e.target.closest('[data-ak-secret-code-rotate]')) {
        // A confirm, like revoking: the previous code is what people were
        // already given, and rotating it is what breaks their access.
        if (!window.confirm('Gerar um novo código? O código atual deixará de funcionar para quem já o recebeu.')) return
        mutate(panel.dataset.secretCodeUrl, 'POST')
        return
    }

    if (e.target.closest('[data-ak-secret-code-copy]')) {
        copyField(panel.querySelector('[data-ak-secret-code-field]'), 'Código copiado.')
        return
    }

    if (e.target.closest('[data-ak-share-copy]')) {
        copyField(panel.querySelector('[data-ak-share-url-field]'), 'Link copiado.')
    }
})

// Both fields in this panel are read-only inputs somebody has to pass on, and
// both fall back the same way: the clipboard API is refused in plenty of
// contexts, and selecting the text is the only useful thing left to offer.
function copyField(field, message) {
    if (!field) return

    navigator.clipboard.writeText(field.value)
        .then(() => Toast.show(message))
        .catch(() => {
            field.select()
            Toast.show('Selecione e copie manualmente.', 'warning')
        })
}

export function init() {}
