// button-loading.js — the `<x-forms.button>` loading state (spinner in, label
// out, button disabled), shared by the modules that submit without going
// through ajax-post.js: docs-editor.js (save serializes the editor first) and
// docs-ai.js (generate collects the panel's fields first).
//
// Helper module, not a behavior module: no `init()`, nothing registered in
// window.globalModules — same shape as docs-diff.js / docs-markdown.js.
//
// ajax-post.js deliberately keeps its OWN, richer version: it also handles
// labels carrying `hidden`/`opacity-100` and toggles `active:shadow-inner`, for
// the wider set of (older) markup it has to drive. Don't "unify" that one into
// this without checking those buttons.

/**
 * @param {HTMLElement} button  an <x-forms.button> (holds [data-spinner] + [data-label])
 * @param {boolean} loading
 */
export function setButtonLoading(button, loading) {
    if (!button) return

    const spinner = button.querySelector('[data-spinner]')
    const label = button.querySelector('[data-label]')

    // `absolute` comes from the component's markup and never changes — the
    // spinner is positioned over the label, so only the opacity swaps.
    spinner?.classList.toggle('opacity-0', !loading)
    label?.classList.toggle('opacity-0', loading)
    button.classList.toggle('cursor-progress', loading)

    if (loading) button.setAttribute('disabled', 'disabled')
    else button.removeAttribute('disabled')
}
