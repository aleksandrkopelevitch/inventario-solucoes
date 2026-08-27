import * as toggle from './modules/toggle'
import * as tabs from './modules/tabs'
import * as modal from './modules/modal'
import * as toast from './modules/toast'
import * as ajaxPost from './modules/ajax-post.js'
import * as themeSwitch from './modules/theme-switch.js'
import * as formSubmit from './modules/form-submit.js'
import * as sidePanel from './modules/side-panel.js'
import * as topNav from './modules/top-nav.js'
import * as avatarUpload from './modules/avatar-upload.js'
import * as chips from './modules/chips.js'
import * as personContacts from './modules/person-contacts.js'
import * as executeFilters from './modules/execute-filters.js'
import * as executeSearch from './modules/execute-search.js'
import * as sortableTable from './modules/sortable-table.js'
import * as inlineEdit from './modules/inline-edit.js'
import * as chainSelect from './modules/chain-select.js'
import * as chainViz from './modules/chain-viz.js'
import * as ecosystemMap from './modules/ecosystem-map.js'
import * as docsEditor from './modules/docs-editor.js'
import * as docsAnchors from './modules/docs-anchors.js'
import * as docsLightbox from './modules/docs-lightbox.js'
import * as docsShare from './modules/docs-share.js'
import * as docsCopy from './modules/docs-copy.js'
import * as docsCode from './modules/docs-code.js'
import * as docsToc from './modules/docs-toc.js'
import * as docsChat from './modules/docs-chat.js'
import * as docsSearch from './modules/docs-search.js'
import * as flowspecChat from './modules/flowspec-chat.js'
import * as catiChat from './modules/cati-chat.js'
import * as mobileNav from './modules/mobile-nav.js'

import.meta.glob([
    '../img/**',
])

window.globalModules = {
    "toggle"       : toggle,
    "tabs"         : tabs,
    "themeSwitch"  : themeSwitch,
    "formSubmit"   : formSubmit,
    "sidePanel"    : sidePanel,
    "topNav"        : topNav,
    "avatarUpload"  : avatarUpload,
    "chips"         : chips,
    "personContacts": personContacts,
    "executeFilters": executeFilters,
    "executeSearch" : executeSearch,
    "sortableTable" : sortableTable,
    "inlineEdit"         : inlineEdit,
    "chainSelect" : chainSelect,
    "chainViz"    : chainViz,
    "ecosystemMap"      : ecosystemMap,
    "docsEditor"        : docsEditor,
    "docsAnchors"       : docsAnchors,
    "docsLightbox"      : docsLightbox,
    "docsShare"         : docsShare,
    "docsCopy"          : docsCopy,
    "docsCode"          : docsCode,
    "docsToc"           : docsToc,
    "docsChat"          : docsChat,
    "docsSearch"        : docsSearch,
    "flowspecChat"      : flowspecChat,
    "catiChat"          : catiChat,
    "mobileNav"         : mobileNav,
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
