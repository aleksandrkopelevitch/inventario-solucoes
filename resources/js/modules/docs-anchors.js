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

/**
 * An in-page anchor inside rendered documentation — either a heading link the
 * author wrote with the Link tool (`#slug`, see docs-tools/link.js) or one that
 * arrived from the imported corpus.
 *
 * Handled rather than left to the browser for one reason: the reading column is
 * a nested scroll container on the internal screen (`.ak-docs-scroll`), and a
 * native hash jump lands there instantly, mid-page, with nothing to tell the
 * reader they moved. `scrollIntoView` scrolls whichever ancestor actually
 * scrolls, smoothly, and honours the `scroll-margin-top` docs-toc.js already
 * puts on every heading so the target doesn't end up under the top bar.
 *
 * Registered AFTER the permalink handler above and scoped to links that are not
 * one, so the two can't both answer the same click.
 */
document.addEventListener('click', (e) => {
    const link = e.target.closest('a[href^="#"]')
    if (!link || link.classList.contains('heading-permalink')) return
    if (!link.closest('.html-content')) return

    const id = decodeURIComponent((link.getAttribute('href') || '').slice(1))
    const target = id ? document.getElementById(id) : null
    if (!target) return

    e.preventDefault()
    target.scrollIntoView({behavior: 'smooth', block: 'start'})
    history.replaceState(null, '', '#' + id)
})

export function init() {}
