import * as ajaxModule from './ajax'
import {updateSlots} from './ajax-slot'

document.addEventListener('DOMContentLoaded', () => {
    document.body.addEventListener('click', function (e) {
        const ajaxElement = e.target.closest('[data-ak-ajax]')
        if (!ajaxElement) return

        e.preventDefault() // prevents native form submission on button click
        e.stopPropagation()

        const confirmMessage = ajaxElement.getAttribute('data-ak-confirm')
        if (confirmMessage && !window.confirm(confirmMessage)) return

        postData(e, ajaxElement)
    })

    document.body.addEventListener('submit', function (e) {
        const ajaxButton = e.target.querySelector('[data-ak-ajax]')
        if (!ajaxButton) return

        e.preventDefault()
        postData(e, ajaxButton)
    })
})

export async function postData(e, ajaxElement) {
    const button   = ajaxElement
    const formId   = button.getAttribute('data-ak-ajax')
    const form     = document.getElementById(formId)
    const action   = button.getAttribute('data-ak-action')
    const formData = new FormData(form)

    // Opened HERE — synchronously, still inside the click's own task — and not
    // where the answer arrives. `window.open()` needs the transient user
    // activation the gesture carries, and that expires in seconds, while the
    // request behind this attribute can take a minute (generating a diagram is
    // one model call, answered inside the request). Called from the response
    // handler instead, every browser blocks it as a popup, silently.
    const tab = button.getAttribute('data-ak-ajax-target') === '_blank'
        ? openPendingTab(button)
        : null

    try {
        setButtonLoadingState(button, true)
        const response = await ajaxModule.init('POST', action, formData)
        await handleAjaxResponse(response, button, tab)
    } catch (error) {
        // Nothing to show there any more, and a stranded "Preparando…" tab
        // reads as a request that is still running.
        tab?.close()
        let data     = {message: 'An unexpected error occurred', type: 'warning'}
        let errorBody = {}

        if (error.response) {
            try {
                errorBody = await error.response.json()
            } catch (_) {
                errorBody = {}
            }

            const status = error.response.status
            const messages = {
                400: 'Requisição inválida.',
                403: 'Proibido. Você não possui acesso a este recurso.',
                404: 'Recurso não encontrado.',
                422: 'Erro de validação.',
                500: 'Erro interno no servidor.',
            }
            data.message = errorBody.message ?? messages[status] ?? `Erro inesperado: ${status}`
        } else {
            console.error('Network error or unexpected issue:', error)
            data.message = 'Erro de rede/conexão. Verifique sua conexão.'
        }

        console.error('Error during fetch:', error)
        showWarning(data)

        if (errorBody.js) {
            try {
                new Function(errorBody.js)()
            } catch (jsError) {
                console.error('Error executing server-provided JS (from error response):', jsError)
            }
        }
    } finally {
        setButtonLoadingState(button, false)
    }
}

async function handleAjaxResponse(response, button, tab = null) {
    let data
    try {
        data = await response.json()
    } catch (error) {
        console.error('Error parsing JSON:', error)
        showWarning({message: 'Error parsing server response'})
        tab?.close()
        return
    }

    showSuccessAlert(data)
    updateSlots(data)

    if (data.js) {
        try {
            new Function(data.js)()
        } catch (error) {
            console.error('Error executing server-provided JS:', error)
        }
    }

    if (data.redirect) {
        // A tab this call opened takes the destination; this one stays where
        // it is, which is the whole point of asking for one.
        if (tab) {
            tab.location.replace(data.redirect)
            tab.focus()
        } else {
            window.location.replace(data.redirect)
        }
    } else {
        // No destination came back: the tab has nothing to become.
        tab?.close()
    }

    if (data.modalIdToClose) {
        Modal.close(data.modalIdToClose)
    }
}

/**
 * The blank tab a `data-ak-ajax-target="_blank"` call navigates once its
 * answer arrives, painted with something to look at meanwhile.
 *
 * `about:blank` is same-origin, so it can be written into; a browser that
 * refuses the write (or the window entirely) still leaves a usable tab — it
 * just stays empty until the redirect lands.
 */
function openPendingTab(button) {
    const tab = window.open('', '_blank')
    if (!tab) return null

    const label = button.getAttribute('data-ak-ajax-pending')
        || button.getAttribute('aria-label')
        || 'Preparando…'

    try {
        tab.document.write(
            '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">'
            + '<title>' + escapeText(label) + '</title></head>'
            + '<body style="margin:0;display:grid;place-items:center;min-height:100vh;'
            + 'font:500 14px system-ui,sans-serif;color:#57606a;background:#fbfbfa">'
            + escapeText(label) + '</body></html>',
        )
        tab.document.close()
    } catch (_) {
        // A tab we cannot write into still works as a destination.
    }

    return tab
}

function escapeText(value) {
    return String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
}

function setButtonLoadingState(button, isLoading) {
    const spinnerEl = button.querySelector('[data-spinner]')
    const labelEl   = button.querySelector('[data-label]')

    if (isLoading) {
        if (spinnerEl) {
            spinnerEl.classList.remove('opacity-0')
            spinnerEl.classList.add('absolute')
        }
        if (labelEl) {
            labelEl.classList.remove('hidden', 'opacity-100')
            labelEl.classList.add('opacity-0')
        }
        button.setAttribute('disabled', 'disabled')
        button.classList.add('cursor-progress')
        button.classList.remove('active:shadow-inner')
    } else {
        if (spinnerEl) {
            spinnerEl.classList.add('opacity-0', 'absolute')
        }
        if (labelEl) {
            labelEl.classList.remove('opacity-0')
            labelEl.classList.add('opacity-100')
        }
        button.removeAttribute('disabled')
        button.classList.remove('cursor-progress')
        button.classList.add('active:shadow-inner')
    }
}

export function showValidationAlert(data) {
    Modal.loadAlert(data)
}

export function showWarning(data) {
    Modal.loadAlert({title: 'Atenção', content: data.message, type: 'warning'})
}

export function showSuccessAlert(data) {
    if (data.reload === 1) {
        Modal.loadAlert({
            content: data.message,
            title  : data.title || 'Alerta',
            type   : data.type || 'success',
            onClose: () => {
                if (data.goToURL) window.location.replace(data.goToURL)
                else window.location.reload()
            },
        })
        return
    }
    Toast.open({content: data.message, title: data.title || 'Alerta', type: data.type || 'success'})
}
