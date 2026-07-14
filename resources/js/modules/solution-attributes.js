// Persistência instantânea dos atributos do cabeçalho de detalhe da solução
// (Solutions\DetailHeader) — cada select (`[data-ak-solution-attribute]`)
// autopersiste no `change`, sem botão "Salvar". Delegação em `document`
// (não por elemento) porque
// `ajax-slot.js` substitui o container inteiro (`solution-detail-header-slot`)
// a cada mutação.
import {updateSlots} from './ajax-slot'

document.addEventListener('change', (e) => {
    const select = e.target
    if (!select.matches('[data-ak-solution-attribute]')) return

    const panel = select.closest('[data-solution-attributes]')
    const action = panel?.dataset.action
    if (!action) return

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content

    fetch(action, {
        method: 'PATCH',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrf,
        },
        body: JSON.stringify({[select.name]: select.value || null}),
    })
        .then((r) => r.json().then((data) => ({ok: r.ok, data})))
        .then(({ok, data}) => {
            if (!ok) throw new Error(data?.message || 'Não foi possível atualizar o atributo.')
            updateSlots(data)
            if (data.message) Toast.show(data.message, data.type ?? 'success')
        })
        .catch((err) => Toast.show(err.message || 'Não foi possível atualizar o atributo.', 'warning'))
})

export function init() {}
