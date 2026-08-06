// docs-toc.js — "Nesta página" headings navigator for documentation.
//
// Works on three surfaces:
//   - Public read-only  (layouts/public-docs): [data-ak-docs-content] .html-content
//   - Internal read-only (documentation/edit) : [data-ak-docs-content] .html-content
//   - Internal editor    (documentation/edit) : [data-ak-docs-editor] Editor.js mount
//
// Read-only reuses the anchor slugs GitbookRenderer's HeadingPermalinkExtension
// already emits (a.heading-permalink, see docs-anchors.js). The editor has no
// permalinks and its headings change as you type, so there we scroll to the
// element directly and rebuild the list on a debounced MutationObserver.
//
// H2 is indented under H1 (hierarchy by indentation). A scroll-spy highlights
// the section currently in view. Per-container init (WeakSet).

const initialized = new WeakSet()

function debounce(fn, ms) {
    let t
    return (...args) => {
        clearTimeout(t)
        t = setTimeout(() => fn(...args), ms)
    }
}

// Nearest scrollable ancestor — the document (null) on public pages, the
// fluid content pane on the internal editor/reader.
function scrollRootOf(el) {
    let p = el?.parentElement
    while (p && p !== document.body) {
        const oy = getComputedStyle(p).overflowY
        if (oy === 'auto' || oy === 'scroll') return p
        p = p.parentElement
    }
    return null
}

function headingText(h) {
    const clone = h.cloneNode(true)
    clone.querySelector('.heading-permalink')?.remove()
    return clone.textContent.trim()
}

function headingId(h) {
    const link = h.querySelector('.heading-permalink')
    const href = link?.getAttribute('href') || ''
    return href.replace(/^#/, '') || link?.id || h.id || ''
}

export function init() {
    document.querySelectorAll('[data-ak-docs-toc]').forEach((toc) => {
        if (initialized.has(toc)) return
        initialized.add(toc)

        const content =
            document.querySelector('[data-ak-docs-content]') ||
            document.querySelector('[data-ak-docs-editor]')
        if (!content) {
            toc.style.display = 'none'
            return
        }

        const scrollRoot = scrollRootOf(content)
        // Fixed top bar only overlaps document-scroll (public); the fluid pane
        // already starts below its own top bar, so a small margin is enough.
        const scrollMargin = scrollRoot ? 16 : 80
        let spy = null

        const render = () => {
            const headings = Array.from(content.querySelectorAll('h1, h2')).filter((h) => headingText(h))

            spy?.disconnect()
            toc.replaceChildren()

            // Nothing to navigate — collapse the rail so the content stays centered.
            if (!headings.length) {
                toc.style.display = 'none'
                return
            }
            toc.style.display = ''

            const label = document.createElement('p')
            // System ui-monospace, not the app's Space Mono webfont — matches
            // the "eyebrow"-style label in the approved documentation model
            // (artifact 895f7854's `.docs-toc .lbl`), scoped to this one spot
            // rather than the global --font-mono token used by F3/protocol tags.
            label.className = 'px-2 pb-2 text-[10px] font-bold uppercase tracking-[0.12em] text-muted'
            label.style.fontFamily = "ui-monospace, 'SFMono-Regular', Menlo, Consolas, monospace"
            label.textContent = 'Nesta página'

            const list = document.createElement('nav')
            list.className = 'flex flex-col gap-0.5'

            const byEl = new Map()
            headings.forEach((h) => {
                const id = headingId(h)
                h.style.scrollMarginTop = `${scrollMargin}px`

                const a = document.createElement('a')
                a.href = id ? `#${id}` : '#'
                a.textContent = headingText(h)
                a.className = [
                    'block truncate rounded-field py-1 text-[13px] no-underline transition-colors hover:text-accent',
                    h.tagName === 'H2' ? 'pl-5 pr-2 text-muted' : 'px-2 font-medium text-body',
                ].join(' ')

                a.addEventListener('click', (e) => {
                    e.preventDefault()
                    h.scrollIntoView({behavior: 'smooth', block: 'start'})
                    if (id) history.replaceState(null, '', `#${id}`)
                })

                list.appendChild(a)
                byEl.set(h, a)
            })

            toc.append(label, list)

            // Scroll-spy: highlight the heading near the top of the viewport/pane.
            let current = null
            spy = new IntersectionObserver(
                (entries) => {
                    entries.forEach((entry) => {
                        if (!entry.isIntersecting) return
                        const el = byEl.get(entry.target)
                        if (!el || el === current) return
                        current?.classList.remove('!text-accent', 'font-semibold')
                        el.classList.add('!text-accent', 'font-semibold')
                        current = el
                    })
                },
                {root: scrollRoot, rootMargin: scrollRoot ? '0px 0px -75% 0px' : '-80px 0px -70% 0px'},
            )
            headings.forEach((h) => spy.observe(h))
        }

        render()

        // Editor: headings appear after the async mount and change as the user
        // types — rebuild (debounced) whenever the block tree or text changes.
        if (content.matches('[data-ak-docs-editor]')) {
            const rebuild = debounce(render, 350)
            new MutationObserver(rebuild).observe(content, {childList: true, subtree: true, characterData: true})
        }
    })
}
