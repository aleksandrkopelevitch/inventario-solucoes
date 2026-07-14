// top-nav.js
// Toggle "shadow-xl" em elementos com o atributo [data-ak-top-nav] quando houver scroll
function applyShadowState() {
    const targets = document.querySelectorAll('[data-ak-top-nav]')
    if (!targets[0]) return

    const scrolled = window.scrollY > 0
    targets.forEach((el) => {
        // smooth animation
        el.classList.add('transition-all', 'duration-300')
        el.classList.toggle('shadow-sm/10', scrolled)
        el.classList.toggle('py-6', !scrolled)
    })
}

document.addEventListener('scroll', applyShadowState, { passive: true })

export function init() {
    applyShadowState()
}
