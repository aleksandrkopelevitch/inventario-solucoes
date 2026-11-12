import * as toggle from './modules/toggle'
import * as tabs from './modules/tabs'
import * as modal from './modules/modal'
import * as toast from './modules/toast'
import * as ajaxPost from './modules/ajax-post.js'
import * as formSubmit from './modules/form-submit.js'
import * as sidePanel from './modules/side-panel.js'
import * as avatarUpload from './modules/avatar-upload.js'
import * as chips from './modules/chips.js'
import * as personContacts from './modules/person-contacts.js'
import * as executeFilters from './modules/execute-filters.js'
import * as executeSearch from './modules/execute-search.js'
import * as sortableTable from './modules/sortable-table.js'
import * as inlineEdit from './modules/inline-edit.js'
import * as docsAnchors from './modules/docs-anchors.js'
import * as docsLightbox from './modules/docs-lightbox.js'
import * as docsCopy from './modules/docs-copy.js'
import * as docsCode from './modules/docs-code.js'
import * as docsToc from './modules/docs-toc.js'
import * as docsTree from './modules/docs-tree.js'
import * as docsSearch from './modules/docs-search.js'
import * as docsSwitcher from './modules/docs-switcher.js'
import * as docsSecret from './modules/docs-secret.js'
import * as copyField from './modules/copy-field.js'
import * as flowspecChat from './modules/flowspec-chat.js'
import * as markerPoll from './modules/marker-poll.js'
import * as catiChat from './modules/cati-chat.js'
import * as mobileNav from './modules/mobile-nav.js'
import * as railScroll from './modules/rail-scroll.js'
import * as digibeeSessionImport from './modules/digibee-session-import.js'
import { lazy } from './modules/lazy.js'

// Loaded on demand, by the root hook each one mounts on (see lazy.js). The
// diagram canvas alone — with html-to-image and gif.js — was a third of the
// bundle, on pages that have no canvas. docs-chat is here because it imports
// the editor (flushSave/setEditorLocked), so a static import of it would drag
// Editor.js's glue back into every page; its own hooks only exist on the
// editor screen anyway.
const chainViz = lazy('[data-ak-chain-viz]', () => import('./modules/chain-viz.js'))
const ecosystemMap = lazy('[data-ak-ecosystem-map]', () => import('./modules/ecosystem-map.js'))
const docsEditor = lazy('[data-ak-docs-editor]', () => import('./modules/docs-editor.js'))
const docsChat = lazy(
    '[data-ak-docs-chat-trigger], [data-ak-docs-chat-input], [data-ak-docs-chat-resume], [data-ak-docs-chat-poll], [data-ak-docs-chat-lock]',
    () => import('./modules/docs-chat.js'),
)
const docsScene = lazy('figure[data-ak-scene]', () => import('./modules/docs-scene.js'))
const solutionsSheet = lazy('[data-ak-sheet]', () => import('./modules/solutions-sheet.js'))

// Static images referenced from Blade with `Vite::asset('resources/img/…')`.
// `eager` with `?url` on purpose: a plain (lazy) glob whose result nothing
// reads is tree-shaken away by Vite 8 before its files are emitted, so the
// images never reached the manifest and every `Vite::asset()` call threw.
// The fonts are here for the same reason: the layouts preload Inter's latin
// file with `Vite::asset()`, which needs the file in the manifest. Vite emits
// the CSS's `url()` and this glob's import as ONE hashed asset.
import.meta.glob(['../img/**', '../fonts/**'], { eager: true, query: '?url', import: 'default' })

window.globalModules = {
    "toggle"       : toggle,
    "tabs"         : tabs,
    "formSubmit"   : formSubmit,
    "sidePanel"    : sidePanel,
    "avatarUpload"  : avatarUpload,
    "chips"         : chips,
    "personContacts": personContacts,
    "executeFilters": executeFilters,
    "executeSearch" : executeSearch,
    "sortableTable" : sortableTable,
    "inlineEdit"         : inlineEdit,
    "chainViz"    : chainViz,
    "ecosystemMap"      : ecosystemMap,
    "docsEditor"        : docsEditor,
    "docsAnchors"       : docsAnchors,
    "docsLightbox"      : docsLightbox,
    "docsCopy"          : docsCopy,
    "docsCode"          : docsCode,
    "docsToc"           : docsToc,
    "docsTree"          : docsTree,
    "docsChat"          : docsChat,
    "docsSearch"        : docsSearch,
    "docsSwitcher"      : docsSwitcher,
    "docsSecret"        : docsSecret,
    "docsScene"         : docsScene,
    "copyField"         : copyField,
    "flowspecChat"      : flowspecChat,
    "markerPoll"        : markerPoll,
    "catiChat"          : catiChat,
    "mobileNav"         : mobileNav,
    "solutionsSheet"    : solutionsSheet,
    "railScroll"        : railScroll,
    "digibeeSessionImport": digibeeSessionImport,
}

/*------------------------------------------------
    Triggers after document load
-------------------------------------------------*/
document.addEventListener('DOMContentLoaded', () => {
    initAllModules()
})

/*------------------------------------------------
    Make the initAllModules method global
-------------------------------------------------*/
window.initAllModules = () => {
    Object.entries(globalModules).forEach(([moduleName, module]) => {
        module.init()
    })
}

/*------------------------------------------------
    Init only specific modules
-------------------------------------------------*/
window.initListOfModules = (listOfModulesToInit) => {
    listOfModulesToInit.forEach((module) => {
        globalModules[module].init()
    })
}
