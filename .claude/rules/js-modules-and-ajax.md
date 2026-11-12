---
paths:
  - "resources/js/**"
---

## JavaScript — use modules before creating new ones

Before writing any new JS behavior, check if an existing module in `resources/js/modules/` already handles it. The project has modules for: toggle, tabs, side panel, AJAX form submission, filters, search, chips (multi-select with autocomplete), and more. Several modules inherited from akop-pro have been deleted for having zero consumers: a module with no `data-ak-*` hook in any Blade view is dead, whether or not `app.js` registers it in `globalModules`.

Only create a new module when the behavior is genuinely not covered. When creating one, follow the delegation pattern:

```js
// Prefer pure event delegation (no init() guard needed)
document.addEventListener('click', (e) => {
    const trigger = e.target.closest('[data-ak-my-thing]')
    if (!trigger) return
    // handle
})

export function init() {} // no-op — keeps globalModules interface
```

For modules that need per-element initialization (e.g., mounting a library instance), use WeakSet:

```js
const initialized = new WeakSet()
export function init() {
    document.querySelectorAll('[data-ak-my-thing]').forEach((el) => {
        if (initialized.has(el)) return
        initialized.add(el)
        // initialize
    })
}
```

Register new modules in `window.globalModules` in `app.js`.

### A module that carries a library or a whole screen is loaded on demand

`app.js` bundles every static import into the one file every page loads, and
on 2026-11-12 that file was 336 KB (108 KB gzip) of which three quarters was
six modules used by a handful of screens: the diagram canvas (with
html-to-image and gif.js), the ecosystem map (d3-force), the Editor.js glue,
the documentation chat (which imports the editor), the scenes with their icon
catalogue, and the spreadsheet. They are `lazy()` stubs now
(`resources/js/modules/lazy.js`) — same `{init}` shape in `globalModules`, but
`init()` imports the module (Vite emits it as its own chunk) only when the
page holds its root hook, and re-runs the real `init()` on every later call.
The bundle every page loads is 86 KB (27 KB gzip).

Three things to keep in mind when adding to that list, or adding a module
that belongs on it:

- **The guard selector must name a hook that is on the page at the FIRST
  `init()`.** A module whose only hook arrives later (a side panel's content)
  still loads — `initAllModules()` runs after every swap — but a click in the
  few hundred milliseconds before the import resolves is lost, which is why
  the chats that are mostly delegation (flowSpec, CATI) stayed static.
- **A static import from a static module pulls the whole thing back.**
  `docs-chat.js` imports `flushSave`/`setEditorLocked` from the editor, so
  making the editor lazy only worked once the chat was lazy too. `grep` for
  importers before moving a module.
- **The dev build and the production build differ here**: Vite serves every
  module on demand in dev, so a chunk that fails to split shows up only in
  `npm run build`'s output. Check the `app-*.js` size there.

After slot updates, prefer re-initializing only the affected modules instead of all:

```js
// Re-initialize everything (called automatically after slot updates)
window.initAllModules()

// Preferred after partial updates — only re-init what's needed
window.initListOfModules(['toggle', 'avatarUpload'])
```

All JS hooks use `data-ak-*` attributes. Internal component slots (`data-spinner`, `data-label`, `data-content`, etc.) are exempt.

### Empty states: `x-ui.empty-state` + inlined unDraw illustrations

A list with nothing in it gets `x-ui.empty-state` (illustration + what's
missing + what to do), not a line of grey text — see both columns of the
solution detail card. `illustration="foo"` resolves to
`x-illustrations.empty-foo` under `resources/views/components/illustrations/`:
an unDraw SVG (free license, no attribution required) inlined into a Blade
component, with its `width`/`height` stripped, its primary color rewritten to
`currentColor` and its greys/darks to `--color-line` / `--color-ink` /
`--color-surface`. Keep new ones on that recipe — a linked or unrecolored SVG
costs a request and reads as someone else's artwork. Illustrations don't share
an aspect ratio, so the caller caps the one it uses (`illustration-class`).

## `ajax.js` — Promise contract, not XHR

`resources/js/modules/ajax.js::init(method, url, formData?)` is an
**async, `fetch`-based function** — it returns a `Promise<Response>`
(rejects on `!response.ok`, with `error.response` attached). It **doesn't**
have the `XMLHttpRequest` API (`.onload`, `.status`, `.response`, `.send()`)
— that's a leftover from the old, XHR-based `ajax.js` from akop-pro.

```js
// Correct
ajaxModule.init('GET', url)
    .then((response) => response.json())
    .then((data) => updateSlots(data))
    .catch((error) => { /* error.response, if it came from a non-2xx status */ })

// Broken — ajaxObj is a Promise, it has no .onload/.send()
let ajaxObj = ajaxModule.init('GET', url)
ajaxObj.onload = function () { ... }
ajaxObj.send()
```

Real incident (2026-07-02): `execute-filters.js::applyFilters()` and
`modal.js::loadFromURLAndOpen()` still used the old XHR API against the
already-rewritten `ajax.js`, so every filter/search click threw
`TypeError: ajaxObj.send is not a function` — swallowed by the listener,
leaving search and filter with no effect at all. When adding a new consumer of
`ajaxModule.init()`, always treat the return value as a Promise.

## AJAX — Form submission

The `ajax-post.js` module intercepts **clicks** on `[data-ak-ajax]` and also form **submit** (Enter or native submit), preventing the native submission in both cases.

```html
<form id="my-form">
    @csrf
    <!-- fields -->
</form>

<x-forms.button data-ak-ajax="my-form" data-ak-action="{{ route('my.route') }}">Salvar</x-forms.button>
```

No `onsubmit` needed on the form. Enter and click both work automatically.

### Never pass `type="button"` on a button with `data-ak-ajax`

The click still works either way, but **Enter stops working**, with no
visible error. The cause is the HTML, not the module: implicit submission
(Enter inside a text field) only fires if the form has a **submit** button.
Without one, the browser only auto-submits when there's exactly one text
field — so the bug disappears in single-field forms and shows up in forms
with two or more, which makes it look intermittent.

With no `submit` button there's no `submit` event, so `ajax-post.js`'s
listener never runs.

```blade
{{-- ✅ the default — omit `type` and the component already uses submit --}}
<x-forms.button data-ak-ajax="my-form" data-ak-action="{{ route('my.route') }}">Salvar</x-forms.button>

{{-- ❌ Enter dies silently --}}
<x-forms.button type="button" data-ak-ajax="my-form" data-ak-action="{{ route('my.route') }}">Salvar</x-forms.button>
```

**Button outside the form** (e.g. a side panel's footer): associate it via
the `form` attribute — that's what gets Enter through to `ajax-post.js`.

```blade
<form id="my-form"> ... </form>

<x-forms.button form="my-form" data-ak-ajax="my-form" data-ak-action="{{ route('my.route') }}">Salvar</x-forms.button>
```

`type="button"` is still correct on an action button with no fields to
submit — like the trash-icon delete button next to the "Salvar" button in
`attribute-options/group-list.blade.php`, which posts to its own
`data-ak-ajax` target and has nothing for Enter to submit.

## The three chat composers share `chat-composer.js`

The documentation assistant, the Especialista em Integrações and the committee
interview each carried their own Enter-to-send, auto-grow, error reading, slot
POST, one-file-at-a-time upload, paste label and drag-and-drop. Those live in
`chat-composer.js` now (a helper module, not registered): `sendOnEnter`,
`autoGrow(el, max)` (remembers a size picked by dragging the resize handle),
`responseMessage`, `runServerJs`, `postForSlots` (resolves to whether it
landed), `eachUntilRefused`, `labelForPaste`, `bindFileDrop`. What stays in each
chat is where a paste or a file GOES, which really differs. A fourth chat
starts from these, and its polling is `marker-poll.js` (`data-ak-poll`).

Mind `autoGrow`'s second parameter: `list.forEach(autoGrow)` passes the index
as `max` and pins the textarea at 0px — call it as `forEach((el) => autoGrow(el))`.
