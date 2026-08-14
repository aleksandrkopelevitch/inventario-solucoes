// Edit-in-place for a server record, on a read-only page. Read mode is whatever
// the Blade caller rendered; a DOUBLE click on it — or a single one on the
// pencil button beside it — swaps in the editor: one field, or a few when one
// gesture needs more than one answer (contact type + value, system + role),
// plus a confirm/cancel icon pair. Confirming sends only those fields. Markup
// contract: `resources/views/components/ui/inline-edit.blade.php`.
//
// A single click over the value is deliberately left alone: on a page where
// every datum is editable it's still, first, a page one READS — selecting a
// phone number to copy it, or hitting the ↗, must not be a near-miss for
// opening an editor. Creators ("+ Adicionar …") keep the single click, since
// their read mode is a button, not a value.
//
// Pure delegation on `document` — never per-element — because a successful save
// answers with the surrounding updatable slot, so `ajax-slot.js` replaces this
// whole subtree (editor included) on every mutation.
import {updateSlots} from './ajax-slot'
import {imageRejectionReason} from './avatar-upload'

const ROOT = '[data-ak-inline-edit]'
const FIELD = '[data-ak-inline-edit-field]'
// The ↗ of a linked datum (`x-ui.external-link`) sits INSIDE the read block,
// which double-clicks into the editor: without this, an impatient double click
// on the icon would open the editor on top of the navigation the user asked
// for. (A creator's read block IS a trigger, so the click path checks it too.)
const LINK = '[data-ak-inline-edit-link]'

// One editor open at a time: two editors open over the same record invite
// filling both in and expecting one confirm to save them together.
let openRoot = null

function parts(root) {
    return {
        config: JSON.parse(root.dataset.akInlineEdit || '{}'),
        read: root.querySelector('[data-ak-inline-edit-read]'),
        form: root.querySelector('[data-ak-inline-edit-form]'),
        fields: [...root.querySelectorAll(FIELD)],
        confirm: root.querySelector('[data-ak-inline-edit-confirm]'),
    }
}

const fieldName = (field) => field.dataset.akInlineEditField

/**
 * The value the SERVER is known to hold — the `value`/`selected` the page was
 * rendered with, which the browser never rewrites as the user types (unlike
 * `.value`). Same trick as solution-attributes.js, and what makes "cancel"
 * and "nothing actually changed" reliable without tracking state ourselves.
 */
function serverValue(field) {
    if (field.tagName === 'SELECT') {
        return [...field.options].find((option) => option.defaultSelected)?.value ?? ''
    }

    return field.defaultValue ?? ''
}

const isFile = (field) => field.type === 'file'
const chosenFile = (field) => (isFile(field) ? field.files?.[0] ?? null : null)

function open(root) {
    if (openRoot === root) return
    if (openRoot) close(openRoot)

    const {read, form, fields} = parts(root)
    openRoot = root
    read.classList.add('hidden')
    form.classList.remove('hidden')

    const first = fields[0]
    if (!first) return

    // A photo has nothing to type: go straight to the picker, the same gesture
    // clicking the profile tile gives. Cancel/confirm stay available either
    // way, including when the OS dialog is dismissed — the tile is still there
    // to click again.
    if (isFile(first)) {
        first.click()
        return
    }

    first.focus()
    // Pre-selected so typing replaces the old value, which is the common
    // intent when correcting a phone number or a job title.
    if (typeof first.select === 'function') first.select()

    // A select has nothing to type — its value IS the list — so it opens right
    // away and the whole edit stays one gesture, the same call the photo tile
    // gets above. `showPicker()` needs the user gesture we're already inside
    // (the click/Enter that opened the editor) and isn't implemented
    // everywhere; where it isn't, the select is merely focused, as before.
    if (first.tagName === 'SELECT' && typeof first.showPicker === 'function') {
        try {
            first.showPicker()
        } catch {
            // Not allowed here (no transient activation) — focus is enough.
        }
    }
}

function close(root, {restore = true} = {}) {
    const {read, form, fields} = parts(root)

    if (restore) {
        fields.forEach((field) => { field.value = isFile(field) ? '' : serverValue(field) })
    }

    form.classList.add('hidden')
    read.classList.remove('hidden')
    if (openRoot === root) openRoot = null
}

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content
}

/**
 * A picked image goes as multipart, and multipart can only be POSTed: PHP only
 * populates `$_FILES` for POST, so a genuine multipart PATCH arrives with no
 * file at all and fails validation for the wrong reason. Laravel's `_method`
 * spoofing puts it back on the intended route.
 */
function requestOptions(config, fields) {
    const upload = fields.find(chosenFile)

    if (upload) {
        const body = new FormData()
        if (config.method !== 'POST') body.append('_method', config.method)
        fields.forEach((field) => {
            const file = chosenFile(field)
            if (file) body.append(fieldName(field), file)
            else if (!isFile(field)) body.append(fieldName(field), field.value)
        })

        return {method: 'POST', headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf()}, body}
    }

    const payload = {}
    fields.forEach((field) => {
        // An empty file input means "no new image", not a value to send.
        if (isFile(field)) return
        // '' would reach a nullable column as an empty string and read as
        // "filled in" everywhere downstream (`filled()`, the blank-value
        // placeholder); the field being emptied means null.
        payload[fieldName(field)] = field.value === '' ? null : field.value
    })

    return {
        method: config.method || 'PATCH',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf()},
        body: JSON.stringify(payload),
    }
}

async function save(root) {
    const {config, fields, confirm} = parts(root)
    if (!fields.length || !config.action) return

    const upload = fields.find(chosenFile)
    if (upload) {
        const rejection = imageRejectionReason(chosenFile(upload))
        if (rejection) {
            upload.value = ''
            Toast.show(rejection, 'warning')
            return
        }
    } else if (fields.every((field) => field.value === serverValue(field))) {
        // Nothing to save — don't spend a round trip (or a toast) on it. Also
        // what makes an accidentally-opened creator close quietly.
        close(root)
        return
    }

    if (confirm) confirm.disabled = true

    try {
        const response = await fetch(config.action, requestOptions(config, fields))
        // A 419/500 can answer with HTML; `.json()` would throw over the real error.
        const data = await response.json().catch(() => ({}))

        if (!response.ok) throw new Error(data.message || 'Não foi possível salvar a alteração.')

        updateSlots(data)
        if (data.message) Toast.show(data.message, data.type ?? 'success')

        // Normally the response carried this element's slot, so the whole
        // subtree (this editor included) is already gone. It survives only if
        // the endpoint answered without one — then close it by hand, and make
        // the just-saved values the new rollback target.
        if (root.isConnected) {
            fields.forEach((field) => {
                if (field.tagName === 'SELECT') {
                    [...field.options].forEach((option) => { option.defaultSelected = option.selected })
                } else if (!isFile(field)) {
                    field.defaultValue = field.value
                }
            })

            close(root, {restore: false})
        } else if (openRoot === root) {
            // The slot swap took the whole editor with it — don't keep a
            // detached node as "the open one".
            openRoot = null
        }
    } catch (error) {
        // Editor stays open with the typed value — the user's text is the one
        // thing they can't recover, and a rejected value is usually one edit
        // away from being accepted.
        Toast.show(error.message || 'Não foi possível salvar a alteração.', 'warning')
    } finally {
        if (confirm?.isConnected) confirm.disabled = false
    }
}

document.addEventListener('click', (e) => {
    if (e.target.closest(LINK)) return

    const opener = e.target.closest('[data-ak-inline-edit-open]')
    if (opener) {
        open(opener.closest(ROOT))
        return
    }

    const confirm = e.target.closest('[data-ak-inline-edit-confirm]')
    if (confirm) {
        save(confirm.closest(ROOT))
        return
    }

    const cancel = e.target.closest('[data-ak-inline-edit-cancel]')
    if (cancel) close(cancel.closest(ROOT))
})

// The other way in: double-click the value itself. `[data-ak-inline-edit-open]`
// above covers the pencil (and a creator's chip); this covers everything a
// caller rendered as read mode, without asking it for a hook of its own.
document.addEventListener('dblclick', (e) => {
    if (e.target.closest(LINK)) return

    const read = e.target.closest('[data-ak-inline-edit-read]')
    if (!read) return

    // The second click left the word under the cursor selected; the editor
    // opens with its own value selected instead, so drop that first — the two
    // highlights overlapping read as a glitch.
    window.getSelection()?.removeAllRanges()

    open(read.closest(ROOT))
})

// Clicking away closes an editor nobody filled in — the usual way one gets
// opened is by accident, and a lone editor left open over a page of read-only
// data is exactly the seam this component exists to hide. It only ever closes
// an UNTOUCHED one: a half-typed value is the one thing the user can't get
// back, so an editor with changes stays put and waits for confirm/cancel/Esc.
document.addEventListener('pointerdown', (e) => {
    if (!openRoot || openRoot.contains(e.target)) return

    const {fields} = parts(openRoot)
    const untouched = fields.every((field) => !chosenFile(field) && field.value === serverValue(field))

    if (untouched) close(openRoot)
})

document.addEventListener('keydown', (e) => {
    const field = e.target.closest?.(FIELD)

    if (field) {
        // In a textarea Enter is a newline the user meant to type, so saving
        // moves to Ctrl/Cmd+Enter there.
        const saveKey = field.tagName === 'TEXTAREA'
            ? e.key === 'Enter' && (e.ctrlKey || e.metaKey)
            : e.key === 'Enter'

        if (saveKey) {
            e.preventDefault()
            save(field.closest(ROOT))
        }

        if (e.key === 'Escape') {
            e.preventDefault()
            close(field.closest(ROOT))
        }

        return
    }

    if (e.target.closest?.(LINK)) return

    // A creator's read block is a `role="button"`, so it owes the keyboard the
    // same Enter/Space a real button would answer to. The pencil needs nothing
    // here — it IS a `<button>`, and the browser turns Enter/Space into a click.
    const opener = e.target.closest?.('[data-ak-inline-edit-open][role="button"]')
    if (opener && (e.key === 'Enter' || e.key === ' ')) {
        e.preventDefault()
        open(opener.closest(ROOT))
    }
})

export function init() {}
