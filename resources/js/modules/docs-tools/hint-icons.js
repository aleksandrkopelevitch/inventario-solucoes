// Default outline heroicon for each callout (hint) style. Mirrors
// GitbookRenderer::DEFAULT_HINT_ICON (PHP). Leaf module with no imports, so
// docs-markdown.js can reuse the map without dragging hint.js/heroicon-picker.js
// into the global bundle (they're only loaded on demand in the editor).
export const DEFAULT_HINT_ICON = {
    info: 'information-circle',
    warning: 'exclamation-triangle',
    danger: 'exclamation-circle',
    success: 'light-bulb',
}
