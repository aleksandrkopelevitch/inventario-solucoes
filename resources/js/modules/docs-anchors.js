// docs-anchors.js — permanent per-heading link in read-only documentation.
// GitbookRenderer (HeadingPermalinkExtension) places `<a class="heading-permalink"
// href="#slug" id="slug">#</a>` after every H1–H3. Clicking copies the
// URL#slug and scrolls to the section. Pure delegation — init() is a no-op.

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
