import {str2Slug} from "./string-helpers"

export function doSearchInContainer(searchContainerId, searchTerms) {

    let searchContainer = document.getElementById(searchContainerId || false)

    if (!searchContainer) {
        return false
    }

    let nodes                    = searchContainer.querySelectorAll('[data-ak-searchable]')
    let notFoundMessageContainer = searchContainer.querySelector('.not-found-message-container')

    searchTerms = str2Slug(searchTerms.toLowerCase(), {'delimiter': ' '})

    if (nodes[0]) {

        let foundItemsCount = 0

        // Hide all items at first
        nodes.forEach((node) => {
            let searchableParentId = node.dataset.akSearchableParent
            let searchableParent   = document.getElementById(searchableParentId) || false
            if (searchableParent && !searchableParent.classList.contains('hidden')) {
                searchableParent.classList.add('hidden')
            }
        })

        nodes.forEach((node) => {
            let nodeText          = str2Slug(node.innerText, {'delimiter': ' '})
            let searchInArrayForm = searchTerms.split(' ')
                .filter((value) => {
                    return value.length >= 2
                })

            let show = []

            searchInArrayForm.forEach((search) => {
                show.push(nodeText.includes(search))
            })

            if (!show.includes(false)) {
                let searchableParentId = node.dataset.akSearchableParent
                let searchableParent   = document.getElementById(searchableParentId) || false
                if (searchableParent) {
                    if (!searchableParent.dataset.isSelected){
                        searchableParent.classList.remove('hidden')
                        foundItemsCount++
                    }
                }
            }
        })

        if (foundItemsCount === 0) {
            notFoundMessageContainer.classList.remove('hidden')
        } else {
            notFoundMessageContainer.classList.add('hidden')
        }

    }

}