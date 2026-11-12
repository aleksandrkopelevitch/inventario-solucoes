/*------------------------------------------------
    A module loaded only on the pages that use it

    `app.js` imports every module statically and bundles them into the one
    file every page loads. Most modules are a few hundred bytes of event
    delegation and belong there; a handful carry a library (the diagram
    canvas with html-to-image and gif.js, the ecosystem map with d3-force)
    or a page's whole feature (the Editor.js editor, the scenes and their
    icon catalogue, the spreadsheet) and were costing every list and detail
    page three quarters of the bundle for nothing.

    `lazy(selector, load)` returns the same `{init}` shape `globalModules`
    expects. Its `init()` looks for the module's root hook; when the page has
    none it returns, and when it has one the module is imported (once — Vite
    emits it as its own chunk) and its real `init()` runs, on this call and on
    every later one (slot swaps re-run `initAllModules()`). A module that
    registers document-level listeners at import time keeps working: they are
    registered the moment its hook is on the page, before anyone can click.
--------------------------------------------------*/
export function lazy(selector, load) {
    let loading = null
    let loaded = null

    return {
        init() {
            if (!document.querySelector(selector)) return

            if (loaded) {
                loaded.init()

                return
            }

            // A second init() before the import resolves is dropped on
            // purpose: the first one's `.then` runs the real init against
            // whatever the DOM holds by then, which covers both.
            loading ??= load().then((module) => {
                loaded = module
                module.init()
            })
        },
    }
}
