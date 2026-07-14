// docs-anchors.js — link permanente por título na documentação read-only.
// O GitbookRenderer (HeadingPermalinkExtension) coloca `<a class="heading-permalink"
// href="#slug" id="slug">#</a>` após cada H1–H3. Clicar copia a URL#slug e rola
// até a seção. Delegação pura — init() é no-op.

document.addEventListener('click', async (e) => {
    const link = e.target.closest('.heading-permalink')
    if (!link) return

    e.preventDefault()
    const id = (link.getAttribute('href') || '').replace(/^#/, '') || link.id
    if (!id) return

    const url = location.origin + location.pathname + '#' + id
    try {
        await navigator.clipboard.writeText(url)
        window.Toast?.show('Link da seção copiado.')
    } catch (_) {
        window.Toast?.open({content: url, title: 'Copie o link', type: 'warning'})
    }

    history.replaceState(null, '', '#' + id)
    document.getElementById(id)?.scrollIntoView({behavior: 'smooth', block: 'start'})
})

export function init() {}
