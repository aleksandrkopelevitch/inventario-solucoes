// Heroicon outline padrão de cada estilo de callout (hint). Espelha
// GitbookRenderer::DEFAULT_HINT_ICON (PHP). Módulo-folha sem imports, para que
// docs-markdown.js possa reusar o mapa sem arrastar hint.js/heroicon-picker.js
// para o bundle global (eles só são carregados sob demanda no editor).
export const DEFAULT_HINT_ICON = {
    info: 'information-circle',
    warning: 'exclamation-triangle',
    danger: 'exclamation-circle',
    success: 'light-bulb',
}
