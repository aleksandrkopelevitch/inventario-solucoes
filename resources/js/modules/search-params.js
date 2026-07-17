// The only utility in this unit still in use — the rest (init/checkSearchParams,
// opening/closing the side panel via query string) came from akop-pro and
// called a `side-panel.js` API (`openSidePanel`/`closeAllSidePanels`) that no
// longer exists in this version (rewritten to open/close only by click,
// without depending on the query string). It was never registered in
// `globalModules`, so it never ran — but it confused anyone reading the code
// while chasing panel bugs.

export function getURLWithoutSearchParams() {
    //window.location.search.replace('?','')
    return window.location.href.replace(window.location.search, '');
}
