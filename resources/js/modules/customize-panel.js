// Delegation — handles gradient/photo swatch clicks inside the customize panel.
// Sends PATCH to the endpoint defined in [data-customize-panel][data-action].
// Updates #dashboard-bg immediately via the `js` field in the JSON response.

document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-ak-customize]')
    if (!btn) return

    const [type, value] = btn.dataset.akCustomize.split(':')
    const panel = btn.closest('[data-customize-panel]')
    if (!panel) return

    const action = panel.dataset.action
    const csrf   = document.querySelector('meta[name="csrf-token"]')?.content

    // Optimistic active state — hide all rings, show only on selected button
    panel.querySelectorAll('.active-ring').forEach(el => el.classList.add('hidden'))
    btn.querySelectorAll('.active-ring').forEach(el => el.classList.remove('hidden'))

    fetch(action, {
        method: 'PATCH',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrf,
        },
        body: JSON.stringify({ type, value }),
    })
    .then(r => r.json())
    .then(data => {
        if (data.js) {
            // eslint-disable-next-line no-new-func
            new Function(data.js)()
        }
        if (data.message) {
            Toast.show(data.message, data.type ?? 'success')
        }
    })
    .catch(() => {
        Toast.show('Erro ao salvar preferência.', 'warning')
    })
})

export function init() {}
