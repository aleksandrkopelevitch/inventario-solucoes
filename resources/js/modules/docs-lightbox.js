// docs-lightbox.js — clicking an image in read-only documentation (inside
// `.html-content`) opens a fullscreen lightbox: dark backdrop, large image
// (object-contain), caption underneath, ‹ › arrows to navigate between all
// images in the same document, and Esc / click on backdrop / ✕ to close.
// Pure delegation — init() is a no-op (the listener lives at module level).
//
// Only acts within `.html-content` (GitbookRenderer's read-only view), never
// in the editor (`.ak-docs-editor`), where clicking the image edits it instead.
//
// The page shows an image's LIGHTER rendition (a WebP at most 1600px wide —
// see DocumentationMediaResponse). The lightbox is where somebody asked to see
// it properly, so it opens on the light one at once and swaps in the uploaded
// file as soon as that arrives.

let overlay = null
let imgs = []
let index = 0

/** The original being fetched for the image on screen, if any. */
let pending = null

/**
 * Where the uploaded file behind a documentation image lives: the same URL
 * with `?original=1`. Every route that serves one answers that way —
 * `/files/{id}`, `/docs/{caderno}/file/{id}`, `/public-docs/{token}/file/{id}`.
 * Anything else (an external image, a diagram's picture) is its own original.
 */
function originalOf(src) {
    let url
    try {
        url = new URL(src, window.location.href)
    } catch (_) {
        return null
    }

    if (url.origin !== window.location.origin || !/\/files?\/\d+$/.test(url.pathname)) return null

    url.searchParams.set('original', '1')

    return url.href
}

function forgetPending() {
    if (pending) pending.onload = pending.onerror = null
    pending = null
    overlay?.querySelector('[data-lb-loading]')?.classList.add('hidden')
}

/** Swaps the full image in once it has loaded, unless the reader moved on. */
function showOriginal(big) {
    forgetPending()

    const full = originalOf(big.src)
    if (!full) return

    const loader = new Image()
    pending = loader
    loader.onload = () => {
        if (pending !== loader) return
        big.src = full
        forgetPending()
    }
    loader.onerror = () => {
        if (pending === loader) forgetPending()
    }
    loader.src = full

    // Already in the cache: no point announcing a wait that is not coming.
    if (!loader.complete) overlay.querySelector('[data-lb-loading]').classList.remove('hidden')
}

function build() {
    if (overlay) return overlay

    overlay = document.createElement('div')
    overlay.className = 'fixed inset-0 z-[100] hidden items-center justify-center bg-black/85 backdrop-blur-sm cursor-zoom-out'
    overlay.setAttribute('role', 'dialog')
    overlay.setAttribute('aria-modal', 'true')
    overlay.innerHTML = `
        <span data-lb-loading class="absolute left-3 top-3 hidden animate-pulse rounded-full bg-white/10 px-3 py-1.5 text-xs text-white/80">Carregando imagem completa…</span>
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
        // Clicking the backdrop (outside the <figure>) closes it; the image/caption don't.
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
    showOriginal(big)

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
    forgetPending()
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
    // Image inside a link: respects the link, doesn't hijack the click.
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
