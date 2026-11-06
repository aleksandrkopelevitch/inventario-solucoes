/**
 * The window the "Enviar sessão ao iSol" bookmarklet opens from the Digibee
 * (App\Support\Digibee\Monitor\SessionBookmarklet has the other half):
 *
 *   <div data-ak-digibee-import='{"storeUrl","digibeeOrigin"}'>
 *       <p data-ak-digibee-import-status>…</p>
 *       <button data-ak-digibee-import-close>
 *
 * It tells its opener it is ready — addressed to the Digibee origin only — and
 * accepts exactly one kind of message back: `isol-digibee-session`, from that
 * same opener window and that origin. The token goes straight into the POST;
 * nothing here logs or keeps it.
 *
 * "Ready" is sent again on every load, so a person who had to sign in to iSol
 * first (the window went through the login screen and came back) still gets
 * the session: the bookmarklet keeps listening for ten minutes.
 */

import * as ajaxModule from './ajax.js'
import {updateSlots} from './ajax-slot.js'

const mounted = new WeakSet()

export function init() {
    document.querySelectorAll('[data-ak-digibee-import]').forEach((root) => {
        if (mounted.has(root)) return
        mounted.add(root)
        mount(root)
    })
}

function mount(root) {
    const config = JSON.parse(root.dataset.akDigibeeImport)
    const status = root.querySelector('[data-ak-digibee-import-status]')
    const close = root.querySelector('[data-ak-digibee-import-close]')
    let busy = false

    const show = (text, tone = 'muted') => {
        status.textContent = text
        status.classList.remove('text-muted', 'text-hot', 'text-cat-emerald-ink')
        status.classList.add({muted: 'text-muted', error: 'text-hot', ok: 'text-cat-emerald-ink'}[tone])
    }

    close.addEventListener('click', () => window.close())

    if (!window.opener) {
        show('Esta janela só funciona aberta pelo favorito “Enviar sessão ao iSol”, de dentro da Digibee.', 'error')
        return
    }

    window.addEventListener('message', async (event) => {
        if (event.origin !== config.digibeeOrigin || event.source !== window.opener) return
        if (event.data?.type !== 'isol-digibee-session' || busy) return

        busy = true
        show('Conferindo a sessão com a Digibee…')

        const data = new FormData()
        data.append('token', String(event.data.token ?? ''))
        data.append('apikey', String(event.data.apikey ?? ''))

        try {
            const response = await ajaxModule.init('POST', config.storeUrl, data)
            const json = await response.json()
            updateSlots(json)
            show(json.message ?? 'Sessão guardada.', 'ok')
            close.classList.remove('hidden')
        } catch (error) {
            let message = 'Não foi possível guardar a sessão. Tente de novo.'
            try {
                message = (await error.response.json()).message ?? message
            } catch (_) {}
            show(message, 'error')
            busy = false
        }
    })

    window.opener.postMessage({type: 'isol-digibee-ready'}, config.digibeeOrigin)
}
