// Instant persistence of the solution detail header's attributes
// (Solutions\DetailHeader) — each select (`[data-ak-solution-attribute]`)
// auto-persists on `change`, with no "Save" button. Delegation on `document`
// (not per-element) because
// `ajax-slot.js` replaces the whole container (`solution-detail-header-slot`)
// on every mutation.
import {updateSlots} from './ajax-slot'

// Last value the SERVER is known to hold for each select, for rollback: the
// browser has already repainted the select with the new value by the time
// `change` fires, so a rejected PATCH (validation error, expired session,
// network blip) would otherwise leave the badge showing a value the server
// never accepted — visually indistinguishable from a saved one until some
// unrelated mutation happens to re-render this slot.
//
// Keyed by element (not by name): `ajax-slot.js` replaces the whole
// `solution-detail-header-slot` on every successful mutation, so each fresh
// select is a new key and stale entries are collected with the old DOM.
const lastGoodValue = new WeakMap()

// `focusin` always runs before the interaction that can change the value,
// which is the only moment the pre-change value is still readable. Recorded
// once per element: a second focusin during an in-flight request would
// otherwise capture the not-yet-confirmed value and roll back to it.
document.addEventListener('focusin', (e) => {
    const select = e.target
    if (select.matches?.('[data-ak-solution-attribute]') && !lastGoodValue.has(select)) {
        lastGoodValue.set(select, select.value)
    }
})

/**
 * Falls back to the option carrying the server-rendered `selected` ATTRIBUTE
 * (`defaultSelected`, which the browser never rewrites on user interaction —
 * unlike `select.value`) when no focusin was recorded for this element.
 */
function serverValue(select) {
    if (lastGoodValue.has(select)) return lastGoodValue.get(select)

    return [...select.options].find((option) => option.defaultSelected)?.value ?? ''
}

document.addEventListener('change', (e) => {
    const select = e.target
    if (!select.matches('[data-ak-solution-attribute]')) return

    const panel = select.closest('[data-solution-attributes]')
    const action = panel?.dataset.action
    if (!action) return

    const rollbackTo = serverValue(select)
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content

    fetch(action, {
        method: 'PATCH',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrf,
        },
        body: JSON.stringify({[select.name]: select.value || null}),
    })
        .then((r) => r.json().then((data) => ({ok: r.ok, data})))
        .then(({ok, data}) => {
            if (!ok) throw new Error(data?.message || 'Não foi possível atualizar o atributo.')
            // Now the server's truth — matters when the response carries no
            // slot for this header, so the element survives the save.
            lastGoodValue.set(select, select.value)
            updateSlots(data)
            if (data.message) Toast.show(data.message, data.type ?? 'success')
        })
        .catch((err) => {
            // Put the select back on the value the server actually holds, so
            // the badge never advertises a change that didn't persist.
            select.value = rollbackTo
            Toast.show(err.message || 'Não foi possível atualizar o atributo.', 'warning')
        })
})

export function init() {}
