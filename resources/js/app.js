import * as toggle from './modules/toggle'
import * as tabs from './modules/tabs'
import * as modal from './modules/modal'
import * as toast from './modules/toast'
import * as ajaxPost from './modules/ajax-post.js'
import * as themeSwitch from './modules/theme-switch.js'
import * as formSubmit from './modules/form-submit.js'
import * as sidePanel from './modules/side-panel.js'
import * as topNav from './modules/top-nav.js'
import * as fileUpload from './modules/file-upload.js'
import * as avatarUpload from './modules/avatar-upload.js'
import * as chips from './modules/chips.js'
import * as personContacts from './modules/person-contacts.js'
import * as customizePanel from './modules/customize-panel.js'
import * as executeFilters from './modules/execute-filters.js'
import * as executeSearch from './modules/execute-search.js'
import * as solutionAttributes from './modules/solution-attributes.js'
import * as integrationSelect from './modules/integration-select.js'
import * as integrationViz from './modules/integration-viz.js'
import * as ecosystemMap from './modules/ecosystem-map.js'
import * as docsEditor from './modules/docs-editor.js'
import * as docsAnchors from './modules/docs-anchors.js'
import * as docsLightbox from './modules/docs-lightbox.js'
import * as docsShare from './modules/docs-share.js'
import * as docsCopy from './modules/docs-copy.js'
import * as docsAi from './modules/docs-ai.js'
import * as flowspecChat from './modules/flowspec-chat.js'

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
    "fileUpload"    : fileUpload,
    "avatarUpload"  : avatarUpload,
    "chips"         : chips,
    "personContacts": personContacts,
    "customizePanel": customizePanel,
    "executeFilters": executeFilters,
    "executeSearch" : executeSearch,
    "solutionAttributes" : solutionAttributes,
    "integrationSelect" : integrationSelect,
    "integrationViz"    : integrationViz,
    "ecosystemMap"      : ecosystemMap,
    "docsEditor"        : docsEditor,
    "docsAnchors"       : docsAnchors,
    "docsLightbox"      : docsLightbox,
    "docsShare"         : docsShare,
    "docsCopy"          : docsCopy,
    "docsAi"            : docsAi,
    "flowspecChat"      : flowspecChat,
}

/*------------------------------------------------
    Triggers after document load
-------------------------------------------------*/
document.addEventListener('DOMContentLoaded', () => {
    initAllModules()

    if (document.keyboardShortcutEventAdded !== true) {
        let ignoreKeys = ['ArrowUp', 'ArrowRight', 'ArrowDown', 'ArrowLeft', 'Alt', 'Shift', 'Meta']
        document.addEventListener('keydown', (event) => {
            if (ignoreKeys.indexOf(event.key) <= -1 && event.ctrlKey && event.key === 'b') {
                // some action / click / etc
            }

            if (ignoreKeys.indexOf(event.key) <= -1 && event.key === 'Escape') {
                // some action / click / etc
                // Example: Modal.close('main-modal')
            }
        }, {once: false})
        document.keyboardShortcutEventAdded = true
    }
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
