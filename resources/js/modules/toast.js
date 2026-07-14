export default window.Toast = {

    open(params) {
        if (params.content || params.title) {
            let container = document.getElementById('toast-container')

            let toastId = 'toast-' + Date.now()

            let toastTemplate = container.querySelector('#toast-template') // o componente Blade
            let newToast      = toastTemplate.cloneNode(true)

            // Update newly created element ID
            newToast.id = toastId
            newToast.classList.remove('hidden')

            setTimeout(() => {newToast.classList.remove('opacity-0')},100)

            newToast.querySelector('[data-slot="title"]').innerHTML   = params.title || ''
            newToast.querySelector('[data-slot="content"]').innerHTML = params.content || ''

            let possibleTypes = ['success', 'error', 'warning', 'info']

            possibleTypes.forEach(typeValue => {
                if (typeValue !== params.type) {
                    newToast.querySelector('[data-icon-' + typeValue + ']').classList.add('hidden')
                } else {
                    newToast.querySelector('[data-icon-' + typeValue + ']').classList.remove('hidden')
                }
            })

            container.prepend(newToast)

            newToast.querySelector('button').addEventListener('click', function () {
                Toast.close(newToast.id)
            })

            let timer = 100; // A quantidade de tempo (em percentual)
            let timerElement = newToast.querySelector('[data-timer]'); // O elemento da barra de tempo
            let interval = setInterval(() => {
                if (timer > 0) {
                    timer--;
                    timerElement.style.width = timer + '%';
                } else {
                    clearInterval(interval);
                    Toast.close(newToast.id)
                }
            }, 90);

            // Execute some code on close if needed
            if (params.onClose) {
                newToast.addEventListener('close', params.onClose)
            }
        }
    },

    // Convenience shorthand: Toast.show('Mensagem', 'success')
    show(message, type = 'success') {
        Toast.open({ content: message, type })
    },

    close(elementId) {
        const element = document.getElementById(elementId)

        if (!element) return;

        element.classList.add('translate-y-[30px]', 'opacity-0')

        setTimeout(() => {
            element.remove()
        }, 200)
    },
}
