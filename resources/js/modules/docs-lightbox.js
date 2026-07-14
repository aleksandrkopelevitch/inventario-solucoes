// docs-lightbox.js — clicar numa imagem da documentação read-only (dentro de
// `.html-content`) abre um lightbox em tela cheia: backdrop escuro, imagem em
// tamanho grande (object-contain), legenda embaixo, setas ‹ › para navegar
// entre todas as imagens do mesmo documento, e Esc / clique no fundo / ✕ para
// fechar. Delegação pura — init() é no-op (o listener vive no nível do módulo).
//
// Só atua em `.html-content` (visão read-only do GitbookRenderer), nunca no
// editor (`.ak-docs-editor`), onde clicar na imagem serve pra editá-la.

let overlay = null
let imgs = []
let index = 0

function build() {
    if (overlay) return overlay

    overlay = document.createElement('div')
    overlay.className = 'fixed inset-0 z-[100] hidden items-center justify-center bg-black/85 backdrop-blur-sm cursor-zoom-out'
    overlay.setAttribute('role', 'dialog')
    overlay.setAttribute('aria-modal', 'true')
    overlay.innerHTML = `
        <button type="button" data-lb-close aria-label="Fechar" class="absolute right-3 top-3 flex h-10 w-10 items-center justify-center rounded-full bg-white/10 text-2xl leading-none text-white transition hover:bg-white/20">&times;</button>
        <button type="button" data-lb-prev aria-label="Imagem anterior" class="absolute left-2 flex h-11 w-11 items-center justify-center rounded-full bg-white/10 text-3xl leading-none text-white transition hover:bg-white/20 sm:left-4">&lsaquo;</button>
        <button type="button" data-lb-next aria-label="Próxima imagem" class="absolute right-2 flex h-11 w-11 items-center justify-center rounded-full bg-white/10 text-3xl leading-none text-white transition hover:bg-white/20 sm:right-4">&rsaquo;</button>
        <figure class="m-0 flex max-h-[92vh] max-w-[94vw] cursor-default flex-col items-center gap-3">
            <img data-lb-img alt="" class="max-h-[82vh] max-w-[92vw] rounded-lg object-contain shadow-2xl">
            <figcaption data-lb-cap class="max-w-[80ch] text-center text-sm text-white/80"></figcaption>
        </figure>
    `

    document.body.appendChild(overlay)

    overlay.addEventListener('click', (e) => {
        if (e.target.closest('[data-lb-prev]')) { e.stopPropagation(); step(-1); return }
        if (e.target.closest('[data-lb-next]')) { e.stopPropagation(); step(1); return }
        if (e.target.closest('[data-lb-close]')) { close(); return }
        // Clique no fundo (fora da <figure>) fecha; na imagem/legenda, não.
        if (!e.target.closest('figure')) close()
    })

    return overlay
}

function render() {
    const img = imgs[index]
    if (!img) return

    const big = overlay.querySelector('[data-lb-img]')
    const cap = overlay.querySelector('[data-lb-cap]')

    big.src = img.currentSrc || img.src
    big.alt = img.alt || ''

    const text = img.closest('figure')?.querySelector('figcaption')?.textContent?.trim() || img.alt || ''
    cap.textContent = text
    cap.classList.toggle('hidden', !text)

    const multi = imgs.length > 1
    overlay.querySelector('[data-lb-prev]').classList.toggle('hidden', !multi)
    overlay.querySelector('[data-lb-next]').classList.toggle('hidden', !multi)
}

function open(img) {
    build()
    const scope = img.closest('.html-content') || document
    imgs = Array.from(scope.querySelectorAll('img'))
    index = Math.max(0, imgs.indexOf(img))
    render()
    overlay.classList.remove('hidden')
    overlay.classList.add('flex')
    document.body.style.overflow = 'hidden'
}

function close() {
    if (!overlay) return
    overlay.classList.add('hidden')
    overlay.classList.remove('flex')
    document.body.style.overflow = ''
}

function step(dir) {
    if (imgs.length < 2) return
    index = (index + dir + imgs.length) % imgs.length
    render()
}

document.addEventListener('click', (e) => {
    const img = e.target.closest('.html-content img')
    if (!img) return
    // Imagem dentro de um link: respeita o link, não sequestra o clique.
    if (img.closest('a')) return
    e.preventDefault()
    open(img)
})

document.addEventListener('keydown', (e) => {
    if (!overlay || overlay.classList.contains('hidden')) return
    if (e.key === 'Escape') close()
    else if (e.key === 'ArrowLeft') step(-1)
    else if (e.key === 'ArrowRight') step(1)
})

export function init() {}
