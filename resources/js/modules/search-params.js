// Único utilitário desta unidade em uso — o restante (init/checkSearchParams,
// abrir/fechar side panel via query string) veio do akop-pro e chamava uma
// API de `side-panel.js` (`openSidePanel`/`closeAllSidePanels`) que não
// existe mais nesta versão (reescrita para abrir/fechar só por clique, sem
// depender de query string). Nunca era registrado em `globalModules`, então
// nunca rodava — mas confundia quem lia o código à procura de bugs no painel.

export function getURLWithoutSearchParams() {
    //window.location.search.replace('?','')
    return window.location.href.replace(window.location.search, '');
}
