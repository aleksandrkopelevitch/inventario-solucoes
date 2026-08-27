/**
 * Collapsible page tree for the documentation rails (public and authenticated).
 *
 * The SERVER decides what a page loads expanded — the ancestors of the page
 * being read, and nothing else (see DocumentationPageService::navRows()). This
 * module only handles what happens after a click, which is why the rail is
 * already correct with JavaScript still parsing.
 *
 * Rows are a FLAT list carrying `data-ak-docs-tree-item` + `data-page-id` +
 * `data-parent-id`, not nested `<ul>`s. That is deliberate and shared with the
 * markup's own reason: a flat list keeps one `$loop->index` per row, which is
 * what makes the hidden per-row form ids in the authenticated rail unique. It
 * costs this module a parent→children index, built once per rail.
 */

/** Collapsing a branch must hide its whole subtree, not just the first level. */
function descendantsOf(root, id, out = []) {
    root.querySelectorAll(`[data-ak-docs-tree-item][data-parent-id="${id}"]`).forEach((child) => {
        out.push(child)
        descendantsOf(root, child.dataset.pageId, out)
    })

    return out
}

function setExpanded(row, expanded) {
    const root = row.closest('[data-ak-docs-tree]')
    if (!root) return

    const toggle = row.querySelector('[data-ak-docs-tree-toggle]')
    if (toggle) toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false')
    row.dataset.expanded = expanded ? 'true' : 'false'

    if (expanded) {
        // Only the direct children come back. A grandchild stays hidden if its
        // own parent was collapsed when the branch was closed — reopening a
        // branch should restore what it looked like, not flatten it open.
        root.querySelectorAll(`[data-ak-docs-tree-item][data-parent-id="${row.dataset.pageId}"]`)
            .forEach((child) => child.classList.remove('hidden'))

        return
    }

    descendantsOf(root, row.dataset.pageId).forEach((child) => child.classList.add('hidden'))
}

document.addEventListener('click', (e) => {
    const toggle = e.target.closest('[data-ak-docs-tree-toggle]')
    if (!toggle) return

    // The chevron sits inside the row but OUTSIDE the page link, so this never
    // competes with navigating: clicking the title opens the page, clicking the
    // chevron opens the branch.
    e.preventDefault()

    const row = toggle.closest('[data-ak-docs-tree-item]')
    if (!row) return

    setExpanded(row, row.dataset.expanded !== 'true')
})

/**
 * No per-element setup: the initial state is server-rendered and the listener
 * above is delegated, so a slot swap (the authenticated rail is an updatable
 * slot) needs nothing re-bound.
 */
export function init() {}
