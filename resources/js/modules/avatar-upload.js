export function init() {
    let selectFileTriggers = document.querySelectorAll('[data-ak-avatar-upload]')
    if (selectFileTriggers[0]) {
        selectFileTriggers.forEach((trigger) => {

            let data = trigger.dataset.akAvatarUpload ? JSON.parse(trigger.dataset.akAvatarUpload) : {}

            let options = {
                once: data.once || false,
            }

            if (!data.inputId) {
                return false
            }

            if (trigger.fileInputEventAdded !== true) {

                trigger.addEventListener(data.event || 'click', (event) => {

                    if (data.action === 'addAvatar') {
                        fireFileInput(data.inputId, event)
                    }

                    if (data.action === 'removeAvatar') {
                        if (confirm(data.confirm)){
                            removeFile(data.targetImgBgId, data.defaultAvatarImgUrl, data.removeAvatarInputId, event)
                        }
                    }

                }, options)

                let fileInput = document.getElementById(data.inputId)
                fileInput.addEventListener('change', (event) => {
                    loadImageFromFile(data.inputId, data.targetImgBgId, data.removeButtonId)
                }, false)

                trigger.fileInputEventAdded = true
            }
        })
    }
}

export function removeFile(targetImgBgId, defaultAvatarImgUrl, removeAvatarInputId, e) {
    let removeAvatarInput = document.getElementById(removeAvatarInputId)
    let targetImgBg = document.getElementById(targetImgBgId)

    removeAvatarInput.value = 'remove'
    targetImgBg.style.backgroundImage    = "url('" + defaultAvatarImgUrl + "')"

    e.currentTarget.classList.add('hidden')

    e.stopPropagation()
}

export function fireFileInput(inputId, e) {
    let fileInput = document.getElementById(inputId)
    if (!fileInput || (fileInput && fileInput.type !== 'file')) {
        return false
    }

    fileInput.click()
    e.stopPropagation()
}

export function loadImageFromFile(inputId, targetImgBgId, removeButtonId) {
    let fileInput = document.getElementById(inputId)
    let removeButton = document.getElementById(removeButtonId)

    if (fileInput.files && fileInput.files[0]) {
        let reader    = new FileReader()
        reader.onload = (e) => {
            let targetImgBg = document.getElementById(targetImgBgId)

            targetImgBg.style.backgroundImage    = "url('" + e.target.result + "')"
            targetImgBg.style.backgroundSize     = 'cover'
            targetImgBg.style.backgroundPosition = 'center'
        }

        reader.readAsDataURL(fileInput.files[0])
        if (removeButton){
            removeButton.classList.remove('hidden')
        }
    }
}
