export function init() {
    let triggers = document.querySelectorAll('[data-ak-radio-group]')
    if (triggers[0]) {
        triggers.forEach((trigger) => {
            let data = trigger.dataset.akRadioGroup ? JSON.parse(trigger.dataset.akRadioGroup) : {}

            let options = {
                once: data.once || false,
            }

            verifyEachRadioElement(data, trigger)

            if (trigger.radioEventAdded !== true) {

                trigger.addEventListener('change', (event) => {

                    verifyEachRadioElement(data, trigger)
                    addFocusStateToSelectedElement(data, trigger)

                }, options)

                trigger.addEventListener('focus', (event) => {

                    addFocusStateToSelectedElement(data, trigger)

                }, options)

                trigger.addEventListener('blur', (event) => {

                    removeFocusStateToSelectedElement(data, trigger)

                }, options)

                trigger.radioEventAdded = true
            }
        })
    }
}

export function addFocusStateToSelectedElement(data, trigger) {

    let focusClasses = data.focus

    let radioGroupElements = document.querySelectorAll('[name=' + trigger.name + ']')

    radioGroupElements.forEach((element) => {
        if (element.checked) {
            focusClasses.forEach((focusClass) => {
                if (!element.parentElement.classList.contains(focusClass)) {
                    element.parentElement.classList.add(focusClass)
                }
            })
        } else {
            focusClasses.forEach((focusClass) => {
                if (element.parentElement.classList.contains(focusClass)) {
                    element.parentElement.classList.remove(focusClass)
                }
            })
        }
    })
}

export function removeFocusStateToSelectedElement(data, trigger) {

    let focusClasses = data.focus

    let radioGroupElements = document.querySelectorAll('[name=' + trigger.name + ']')

    radioGroupElements.forEach((element) => {

        focusClasses.forEach((focusClass) => {
            if (element.parentElement.classList.contains(focusClass)) {
                element.parentElement.classList.remove(focusClass)
            }
        })
    })
}

export function verifyEachRadioElement(data, trigger) {
    let activeClasses      = {}
    let inactiveClasses    = {}
    activeClasses.label    = data.labelActive
    activeClasses.border   = data.borderActive
    activeClasses.icon     = data.iconActive
    inactiveClasses.label  = data.labelInactive
    inactiveClasses.border = data.borderInactive
    inactiveClasses.icon   = data.iconInactive

    let radioGroupElements = document.querySelectorAll('[name=' + trigger.name + ']')

    radioGroupElements.forEach((element) => {
        if (element.checked) {
            setAsActive(element, activeClasses, inactiveClasses)
        } else {
            setAsInactive(element, activeClasses, inactiveClasses)
        }
    })
}

export function setAsActive(target, activeClasses, inactiveClasses) {

    activeClasses.label.forEach((activeClass) => {
        target.parentElement.classList.add(activeClass)
    })

    inactiveClasses.label.forEach((inactiveClass) => {
        target.parentElement.classList.remove(inactiveClass)
    })

    activeClasses.icon.forEach((activeClass) => {
        target.parentElement.querySelector('svg').classList.add(activeClass)
    })

    inactiveClasses.icon.forEach((inactiveClass) => {
        target.parentElement.querySelector('svg').classList.remove(inactiveClass)
    })

    activeClasses.border.forEach((activeClass) => {
        target.parentElement.querySelector('[data-border]').classList.add(activeClass)
    })

    inactiveClasses.border.forEach((inactiveClass) => {
        target.parentElement.querySelector('[data-border]').classList.remove(inactiveClass)
    })

}

export function setAsInactive(target, activeClasses, inactiveClasses) {
    activeClasses.label.forEach((activeClass) => {
        target.parentElement.classList.remove(activeClass)
    })

    inactiveClasses.label.forEach((inactiveClass) => {
        target.parentElement.classList.add(inactiveClass)
    })

    activeClasses.icon.forEach((activeClass) => {
        target.parentElement.querySelector('svg').classList.remove(activeClass)
    })

    inactiveClasses.icon.forEach((inactiveClass) => {
        target.parentElement.querySelector('svg').classList.add(inactiveClass)
    })

    activeClasses.border.forEach((activeClass) => {
        target.parentElement.querySelector('[data-border]').classList.remove(activeClass)
    })

    inactiveClasses.border.forEach((inactiveClass) => {
        target.parentElement.querySelector('[data-border]').classList.add(inactiveClass)
    })
}