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

// Mirrors what the server accepts for every image upload in the app (Person
// photo, Solution/Company logo — all six Store/Update requests share
// `['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']`). Keep the two
// in step: `accept="image/*"` on the input is only a picker hint and enforces
// nothing, so this list is the only thing standing between the user and a
// confusing round-trip rejection.
//
// SVG used to be in those `mimes:` rules and was removed rather than enabled:
// Laravel 13's bare `image` rule rejects SVG unless written
// `image:allow_svg`, so it never actually got through — and an SVG served from
// the public disk executes its own scripts when opened directly by URL, which
// isn't worth it for a logo.
const ACCEPTED_IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp']
const MAX_IMAGE_BYTES = 2048 * 1024 // `max:2048` is in kilobytes

/**
 * Why the server would refuse this file, or null when it should go through.
 * Exported for `inline-edit.js` (the person header's click-the-photo editor),
 * so the two upload surfaces can't drift apart on what they pre-reject.
 */
export function imageRejectionReason(file) {
    if (!ACCEPTED_IMAGE_MIMES.includes(file.type)) {
        return 'Formato de imagem não suportado. Use JPG, PNG ou WEBP.'
    }

    if (file.size > MAX_IMAGE_BYTES) {
        return 'Imagem muito grande. O limite é 2 MB.'
    }

    return null
}

export function loadImageFromFile(inputId, targetImgBgId, removeButtonId) {
    let fileInput = document.getElementById(inputId)
    let removeButton = document.getElementById(removeButtonId)

    if (fileInput.files && fileInput.files[0]) {
        const file = fileInput.files[0]

        // Checked BEFORE the preview: rendering it first told the user the
        // upload had worked, and the contradicting validation error only
        // arrived on save — with the preview still showing the rejected
        // image. Clearing the input also keeps that file out of the submit.
        const rejection = imageRejectionReason(file)
        if (rejection) {
            fileInput.value = ''
            Toast.show(rejection, 'warning')
            return
        }

        let reader    = new FileReader()
        reader.onload = (e) => {
            let targetImgBg = document.getElementById(targetImgBgId)

            targetImgBg.style.backgroundImage    = "url('" + e.target.result + "')"
            targetImgBg.style.backgroundSize     = 'cover'
            targetImgBg.style.backgroundPosition = 'center'
        }

        reader.readAsDataURL(file)
        if (removeButton){
            removeButton.classList.remove('hidden')
        }
    }
}
