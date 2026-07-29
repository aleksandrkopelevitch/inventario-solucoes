import * as ajaxModule from './ajax'

// Global delegation for [data-close] inside any <dialog>
document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-close]')
    if (!btn) return
    const dialog = btn.closest('dialog')
    if (dialog) Modal.close(dialog.id)
})

// Generic opener for #main-modal (or any dialog) via AJAX — mirrors
// data-ak-panel-open/data-ak-panel-url from side-panel.js.
//
// Open: <a data-ak-modal-open="main-modal" data-ak-modal-url="/url">
document.addEventListener('click', (e) => {
    const opener = e.target.closest('[data-ak-modal-open]')
    if (!opener) return
    e.preventDefault()
    Modal.loadFromURLAndOpen(opener.dataset.akModalOpen, opener.dataset.akModalUrl)
})

// Per-dialog behavior read by the two module-level listeners below, written by
// open(). Both listeners MUST live out here: registering them inside open()
// added one more on every open of the same dialog, and they were never removed
// — so a `closeOnEsc = true` from an earlier use kept closing a later use that
// explicitly asked for false (docs-chat's draft review is the caller that cares).
// Keyed by element, so the newest open() always wins.
const modalBehavior = new WeakMap()

// Esc key. `cancel` doesn't bubble, so the capture phase is what lets a single
// document-level listener see it for every dialog.
document.addEventListener('cancel', (event) => {
    const modal = event.target

    if (!(modal instanceof HTMLDialogElement)) return

    if (modalBehavior.get(modal)?.closeOnEsc === false) {
        event.preventDefault()
        return
    }

    Modal.close(modal.id)
}, true)

// Backdrop click — the event target is the <dialog> itself only when the click
// landed outside the dialog's own box.
document.addEventListener('click', (event) => {
    const modal = event.target

    if (!(modal instanceof HTMLDialogElement)) return

    if (modalBehavior.get(modal)?.closeOnBackdropClick) Modal.close(modal.id)
})

// Solution form selects (data-ak-attribute-select="category", etc.) are fed
// by attribute_options at render time. If the user opens the "Gerenciar
// atributos" modal from an already-open create/edit panel, adds/edits/deletes
// a value, then closes the modal, those <select>s need the fresh option list
// without a full form reload (would lose whatever else was already filled).
function refreshAttributeSelects() {
    document.querySelectorAll('[data-ak-attribute-select]').forEach((select) => {
        const url = select.dataset.akAttributeOptionsUrl
        if (!url) return

        const currentValue = select.value

        ajaxModule.init('GET', url)
            .then((response) => response.json())
            .then((options) => {
                const hasBlank = select.querySelector('option[value=""]')
                select.innerHTML = ''
                if (hasBlank) select.add(new Option('—', ''))
                options.forEach((o) => select.add(new Option(o.label, o.value)))
                if ([...select.options].some((o) => o.value === currentValue)) {
                    select.value = currentValue
                }
            })
            .catch(() => {})
    })
}

export default window.Modal = {
    loadFromURLAndOpen(modalId, url, closeOnEsc = true) {

        if (modalId && url) {

            let modal = document.getElementById(modalId) || null

            if (modal === null) {
                return false
            }

            Modal.open(modal.id, closeOnEsc)

            // Navigation within an already-open modal (e.g. integrations
            // list → edit form): resets to the loading placeholder before
            // the fetch, instead of keeping the previous content visible.
            modal.querySelector('[data-content]').innerHTML = ''
            modal.querySelector('[data-loading]').classList.remove('hidden')

            ajaxModule.init('GET', url)
                .then((response) => response.json())
                .then((data) => {
                    modal.querySelector('[data-loading]').classList.add('hidden')
                    modal.querySelector('[data-content]').innerHTML = data.content
                    ajaxModule.includeScripts(modal)
                    initAllModules()
                    modal.scrollTop = 0
                })
                .catch(() => {
                    Modal.loadAlert({
                        'title': 'Não encontrado', 'type': 'warning',
                    })
                    Modal.close(modal.id)
                })
        }
    },

    loadAlert(params) {

        if (params.content || params.title) {

            let modal = document.getElementById('alert-modal') || null

            modal.classList.remove('transition-all','duration-300','-translate-y-[30px]', 'opacity-0');
            if (modal === null) {
                return false
            }

            if (params.title === '' || params.title === undefined) {
                modal.querySelector('[data-title]').classList.add('hidden')
            } else {
                modal.querySelector('[data-title]').classList.remove('hidden')
                modal.querySelector('[data-title]').innerHTML = params.title
            }

            if (params.content === '' || params.content === undefined) {
                modal.querySelector('[data-content]').classList.add('hidden')
            } else {
                modal.querySelector('[data-content]').classList.remove('hidden')
                modal.querySelector('[data-content]').innerHTML = params.content
            }

            let possibleTypes = ['success', 'error', 'warning', 'info']

            possibleTypes.forEach(typeValue => {
                if (typeValue !== params.type) {
                    modal.querySelector('[data-icon-' + typeValue + ']').classList.add('hidden')
                } else {
                    modal.querySelector('[data-icon-' + typeValue + ']').classList.remove('hidden')
                }
            })

            Modal.open(modal.id)

            if (params.timerToClose > 0) {
                /*let timeoutBar = modal.querySelector('[data-timeout-bar]')
                let originalWidth = timeoutBar.clientWidth
                modal.addEventListener('mouseover', () => {
                    timeoutBar.style.width = originalWidth+'px'
                });*/
                Modal.resumeTimer(modal, timerToClose)
            }

            // {once: true} — this callback belongs to THIS alert, not to every
            // future one shown in the shared #alert-modal.
            if (params.onClose) {
                modal.addEventListener('close', params.onClose, {once: true})
            }

        }
    },

    resumeTimer(modal, timerToClose) {
        let timeoutBar = modal.querySelector('[data-timeout-bar]')
        timeoutBar.classList.remove('hidden')
        let originalWidth             = timeoutBar.clientWidth
        let totalTimeInSeconds        = timerToClose * 1000
        let widthToSubtractEachSecond = timeoutBar.clientWidth / (totalTimeInSeconds / 100)

        let timer = setInterval(() => {
            if (totalTimeInSeconds === 0) {
                clearInterval(timer)
                Modal.close(modal.id)
                timeoutBar.style.width = originalWidth + 'px'
                timeoutBar.classList.add('hidden')
            }
            totalTimeInSeconds     = totalTimeInSeconds - 100
            timeoutBar.style.width = (timeoutBar.clientWidth - widthToSubtractEachSecond) + 'px'
        }, 150)
    },

    open(modalId, closeOnEsc = true, closeOnBackdropClick = false) {
        let modal = document.getElementById(modalId) || null

        if (modal === null) return false

        // showModal() on an already-open dialog throws InvalidStateError —
        // the guard lets open() be reused to navigate between different
        // content in the same modal.
        if (!modal.open) modal.showModal()

        document.body.classList.add('overflow-y-hidden')

        modalBehavior.set(modal, { closeOnEsc, closeOnBackdropClick })
    },

    close(modalId) {
        let modal = document.getElementById(modalId) || null

        if (modal === null) return false

        modal.classList.add('transition-all','duration-150','-translate-y-[30px]', 'opacity-0');

        setTimeout(() => {
            if (modal.querySelector('[data-content]'))
                modal.querySelector('[data-content]').innerHTML = ''

            if (modal.querySelector('[data-loading]'))
                modal.querySelector('[data-loading]').classList.remove('hidden')

            modal.close();
            document.body.classList.remove('overflow-y-hidden')

            // close() adds these to animate the dialog out — must be removed once the
            // animation finishes, otherwise the dialog stays opacity-0/translated on
            // every subsequent open() (content loads fine underneath, just invisible).
            modal.classList.remove('transition-all', 'duration-150', '-translate-y-[30px]', 'opacity-0')

            if (modalId === 'main-modal') refreshAttributeSelects()
        }, 200);

    },
}
