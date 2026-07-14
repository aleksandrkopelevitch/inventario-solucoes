// docs-share.js — gera/revoga/copia o link público ("magic link") da
// documentação, a partir do painel Solutions\SharePanel na toolbar. Delegação
// em `document` (não por elemento) porque `ajax-slot.js` troca o container
// inteiro (`docs-share-slot`) a cada mutação. Segue o mesmo padrão dos demais
// módulos de auto-persistência (fetch + updateSlots + Toast).
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

    if (e.target.closest('[data-ak-share-copy]')) {
        const field = panel.querySelector('[data-ak-share-url-field]')
        if (!field) return
        navigator.clipboard.writeText(field.value)
            .then(() => Toast.show('Link copiado.'))
            .catch(() => {
                field.select()
                Toast.show('Selecione e copie o link.', 'warning')
            })
    }
})

export function init() {}
