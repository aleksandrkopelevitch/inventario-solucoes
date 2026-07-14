const initialized = new WeakSet()

export function init() {
    document.querySelectorAll('[data-ak-copy]').forEach((trigger) => {
        if (initialized.has(trigger)) return
        initialized.add(trigger)

        const data = trigger.dataset.akCopy ? JSON.parse(trigger.dataset.akCopy) : {}
        if (!data.fromId) return

        const copyFrom = document.getElementById(data.fromId)
        if (copyFrom) trigger.innerHTML = copyFrom.innerHTML
    })
}
