// copy-field.js — copies the value of a read-only field to the clipboard.
//
// Generic on purpose: three places now hand somebody a string they have to pass
// on (the caderno's public link, its secret code, and a person's access link),
// and each had been growing its own copy handler with its own fallback. This is
// the one behaviour they share.
//
// Pure delegation, so it survives the slot swaps all three live inside.
//
//   <div data-ak-copy>
//       <input data-ak-copy-field value="…" readonly>
//       <button data-ak-copy-trigger data-ak-copy-message="Link copiado.">…</button>
//   </div>
//
// `docs-share.js` still has its own copy for the share panel; it predates this
// and can adopt these hooks whenever that file is next touched.

document.addEventListener('click', (e) => {
    const trigger = e.target.closest('[data-ak-copy-trigger]')
    if (!trigger) return

    const field = trigger.closest('[data-ak-copy]')?.querySelector('[data-ak-copy-field]')
    if (!field) return

    const message = trigger.dataset.akCopyMessage ?? 'Copiado.'

    navigator.clipboard
        .writeText(field.value)
        .then(() => Toast.show(message))
        .catch(() => {
            // The clipboard API is refused in plenty of contexts (no user
            // gesture chain, an insecure origin, a locked-down browser).
            // Selecting the text is the only useful thing left to offer.
            field.select()
            Toast.show('Selecione e copie manualmente.', 'warning')
        })
})

export function init() {}
