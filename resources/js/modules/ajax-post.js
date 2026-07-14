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

    try {
        setButtonLoadingState(button, true)
        const response = await ajaxModule.init('POST', action, formData)
        await handleAjaxResponse(response, button)
    } catch (error) {
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

async function handleAjaxResponse(response, button) {
    let data
    try {
        data = await response.json()
    } catch (error) {
        console.error('Error parsing JSON:', error)
        showWarning({message: 'Error parsing server response'})
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
        window.location.replace(data.redirect)
    }

    if (data.modalIdToClose) {
        Modal.close(data.modalIdToClose)
    }
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
