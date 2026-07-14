export function updateSlots(dataFromRequest) {
    if (Array.isArray(dataFromRequest.updatableSlots)) {
        dataFromRequest.updatableSlots.forEach((slotFromRequest) => {
            if (slotFromRequest.id !== undefined) {
                let slotIdsArray = slotFromRequest.id.split('|')
                slotIdsArray.forEach((id) => {
                    id = id.trim()
                    if (id.length > 3) {
                        let slotToReplace = document.getElementById(id)
                        if (slotToReplace) {
                            let newContent = document.createRange().createContextualFragment(slotFromRequest.content)
                            slotToReplace.parentNode.replaceChild(newContent, slotToReplace)
                        }
                    }
                })
            }
        })
        initAllModules()
    }
}
