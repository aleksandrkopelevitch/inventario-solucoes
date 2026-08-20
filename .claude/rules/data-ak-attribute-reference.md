---
paths:
  - "resources/js/modules/**"
  - "resources/views/**"
---

## data-ak-* attribute reference

All JS hooks use the `data-ak-*` prefix. Internal slots (`data-spinner`, `data-label`, `data-content`) are private to their component and exempt.

| Attribute | Module | Description |
|---|---|---|
| `data-ak-ajax="form-id"` | `ajax-post.js` | Triggers AJAX form submission on click |
| `data-ak-action="url"` | `ajax-post.js` | POST destination URL |
| `data-ak-confirm="msg"` | `ajax-post.js` | `window.confirm` gate before the AJAX fires |
| `data-ak-toggle="element-id"` | `toggle.js` | ID of element to toggle. Anything with id `<element-id>-opened-state` / `-closed-state` has its `hidden` flipped along with it — each independently, so a target may have only one of the two (the docs rail has no "opened" counterpart: collapsing it only ADDS the solution crumb to the top bar) |
| `data-ak-toggle-classes="cls"` | `toggle.js` | Space-separated classes to toggle on the target. A class may be deferred with `class:<ms>` (`hidden:300`) — only an **all-digits** suffix counts, so Tailwind variants (`md:!w-0`, `max-md:!translate-x-0`) pass through untouched. Splitting on the first `:` instead is what silently broke the documentation editor's pages rail until 2026-08-15: it toggled a class literally named `md` |
| `data-ak-toggle-event="click"` | `toggle.js` | Trigger event (default: `click`) |
| `data-ak-toggle-once` | `toggle.js` | Fire only once |
| `data-ak-toggle-blur="true"` | `toggle.js` | Close on outside click |
| `data-ak-toggle-mouseout` | `toggle.js` | Also toggle on mouseout |
| `data-ak-panel-open` | `side-panel.js` | Opens `#side-panel` |
| `data-ak-panel-url="url"` | `side-panel.js` | URL to fetch content from |
| `data-ak-panel-close` | `side-panel.js` | Closes `#side-panel` and clears content |
| `data-ak-panel-overlay="false"` | `side-panel.js` | Open without overlay |
| `data-ak-panel-size="small\|medium\|large"` | `side-panel.js` | Panel width — 1/4 (`w-96`, default), 1/2, or 3/4 of the viewport |
| `data-ak-tabs='{"targetId":"…"}'` | `tabs.js` | JSON config for tab switching |
| `data-ak-filters='{"formId":"…"}'` | `execute-filters.js` | JSON config for filter execution |
| `data-ak-filters-clear='{"formId":"…","field":"filter[x]","url":"…"}'` | `execute-filters.js` | Clears one filter field (active-filter chip ✕) and resubmits via AJAX |
| `data-ak-filters-clear-all='{"formId":"…","url":"…"}'` | `execute-filters.js` | Clears every `filter[...]` field except `filter[sort]` and resubmits ("Limpar tudo" / empty-state action) |
| `data-ak-filters-loading` | `execute-filters.js` | Shown (removes `hidden`) while a filter/search AJAX request is in flight |
| `data-ak-filters-dim` | `execute-filters.js` | Dimmed (`opacity-50 pointer-events-none`) while a filter/search AJAX request is in flight — put on a persistent wrapper *around* the swapped slot, not the slot node itself, since the slot node gets replaced wholesale |
| `data-ak-search='{"inputId":"…"}'` | `execute-search.js` | Debounced (350ms) search-as-you-type; searches immediately on Enter |
| `data-ak-search-hint="input-id"` | `execute-search.js` | Element whose text is set to the "digite N+ letras" hint while 1–2 chars are typed, cleared otherwise |
| `data-ak-avatar-upload='{"inputId":"…"}'` | `avatar-upload.js` | Avatar upload trigger |
| `data-ak-top-nav` | `top-nav.js` | Element that receives scroll shadow |
| `data-ak-inline-edit="{action,method}"` (root) + `-read`/`-open`/`-form`/`-confirm`/`-cancel`, and `data-ak-inline-edit-field="<field name>"` on each input | `inline-edit.js` | Edit-in-place on an otherwise read-only page — read mode is whatever `<x-ui.inline-edit>`'s caller rendered, and it opens on a **double click** or on a single click of the pencil button (`-open`) beside it; a single click over the value is left to reading/selecting/the ↗. A creator ("+ Adicionar …", `method: POST`) is the exception — its read mode is a chip, so one click opens it. The editor is one field (or a few, for the creators) plus a discreet confirm/cancel icon pair. Enter confirms, Esc cancels, Ctrl/Cmd+Enter in a textarea; clicking away closes it if nothing was typed. `method: POST` makes the same component a creator. The payload is built from the field hooks' own names, so there's no second list to keep in step. Reference implementation: the whole person detail page (`People\DetailHeader`, `People\Notes`, `People\Systems`) — text/select/textarea/photo, plus add/remove of contacts and system links. See the `inline-edit-pattern` rule for the full gesture/geometry/chrome contract |
| `data-ak-inline-edit-link` (on the ↗ anchor `x-ui.external-link` renders) | `inline-edit.js` | Escape hatch for the one place where navigation and editing compete — see the `inline-edit-pattern` rule |
| `data-ak-contacts` (root, + `data-ak-contacts-next` counter) + `data-ak-contacts-list`/`data-ak-contact-row`/`data-ak-contact-add`/`data-ak-contact-remove` | `person-contacts.js` | Additional-contacts repeater on the Person form — add/remove rows client-side; synced server-side by `PersonController::syncContacts()` |
| `data-ak-chips` (root) + `data-ak-chips-input`/`data-ak-chips-list`/`data-ak-chips-results`/`data-ak-chips-result`/`data-ak-chip`/`data-ak-chip-remove` | `chips.js` | Multi-select-with-autocomplete chips input (e.g. Person↔Solution role linking) |
| `data-ak-chips-add='{"name":"…","value":"…","label":"…"}'` | `chips.js` | Adds a chip straight to the named field from a JSON config, skipping the picker overlay entirely (e.g. flowSpec's "adicionar ao contexto" suggestion buttons, `thread.blade.php`) |
| `data-ak-chips-trigger` | `chips.js` | Opens the chips picker overlay; can be triggered externally via `.click()` from outside the component (e.g. flowSpec's 📎 attach menu, `data-ak-fs-open` below) |
| `data-ak-integration-select="slug"` (on a row) + `data-ak-integration-list` (on the container) | `integration-select.js` | Selects an integration row (`aria-pressed`), dispatches `ak:integration-selected` `{name, slug, graph}` |
| `data-ak-flowspec-poll="status-url"` | `flowspec-chat.js` | Presence in the thread slot = a reply is still generating; module polls the URL every 2.5s (capped at `MAX_POLL_ATTEMPTS`) until the slot swap removes this marker |
| `data-ak-flowspec-copy="pre-id"` | `flowspec-chat.js` | Copies the target element's `textContent` (not `innerHTML` — the flowSpec JSON's `jsonPath` has literal `&&`) to the clipboard |
| `data-ak-fs-*` (`-input`/`-send`/`-pills`/`-menu`/`-open="name"`/`-toggle-reference`/`-reference-input`/`-reference-pill`/`-reference-clear`/`-scroll`) | `flowspec-chat.js` | Private hooks of the flowSpec composer (`resources/views/components/flowspec/composer.blade.php`) — message textarea, 📎 attach menu (`-open="name"` clicks the matching chips field's `-trigger`), reference-flowSpec panel toggle/clear. Not reused outside that component |
| `data-ak-docs-chat-*` (`-input`/`-send`/`-scroll`/`-trigger`/`-status`) | `docs-chat.js` | "Assiste IA" as a CHAT (mirrors the flowSpec F8 composer): message textarea, send, scroll container, the button that opens the panel, and the "gerando…" indicator |
| `data-ak-docs-chat-poll` | `docs-chat.js` | Presence in the thread slot = a reply is still generating; the module polls until a slot swap removes this marker, with a give-up ceiling + Toast (same contract as `data-ak-flowspec-poll`) |
| `data-ak-docs-chat-lock` | `docs-chat.js` | Locks the editor while a generation runs, so the draft can't be applied onto content that shifted underneath it |
| `data-ak-docs-chat-resume` | `docs-chat.js` | Server-rendered marker: this user has an unresolved generation for this page/integration. On load the module re-locks + polls a pending one, or reopens the review for a finished one — this is what survives navigating away mid-generation |
| `data-ak-docs-chat-draft` / `-view-draft` | `docs-chat.js` | A reply's draft block (4-backtick fenced convention) and the button that opens it for review |
| `data-ak-docs-chat-review-template` + `-review-body` / `-review-close` / `data-ak-docs-chat-apply` | `docs-chat.js` | `<template>` cloned into `#main-modal` to review the draft as a diff (`docs-diff.js`) before it touches the editor. Apply is the intended exit; see the `closeOnEsc = false` caveat under Modal — the `close`-event handler is the real guarantee, since a draft left unresolved comes back on the next load |
| `data-ak-context-doc` (on a checkbox, `value`=media id) | `docs-chat.js` | A Solution context document the AI should consider; checked ids are sent as `media_ids[]` |
| `data-ak-context-upload` (on a file input, + `data-action="url"`) | `docs-chat.js` | Uploads the chosen context document immediately on `change` — there's no separate "Anexar" button, which users kept skipping |
| `data-ak-context-uploading` | `docs-chat.js` | "Enviando documento…" indicator for the upload above |
| `data-ak-docs-editor` (`data-config='{"uploadUrl":"…"}'`) | `docs-editor.js` | Editor.js mount point for a documentation page/integration; the module also self-tags the same element `data-ak-docs-holder` once mounted, for its own click-outside scoping |
| `data-ak-docs-source` | `docs-editor.js` | Hidden `<textarea>` with the raw Markdown the editor is built from on mount |
| `data-ak-docs-save` | `docs-editor.js` | Save button — the one thing `Ctrl/Cmd+S`, autosave and `setEditorLocked()` (raised by `docs-chat.js` during a generation) all enable/disable together |
| `data-ak-docs-status` | `docs-editor.js` | Autosave feedback text ("Salvando…"/"Salvo") |
| `data-ak-docs-toc` (nav target) + `data-ak-docs-content` (scope, read-only view) | `docs-toc.js` | "Nesta página" headings navigator — reads live Editor.js headings while editing, or the `.html-content`/`data-ak-docs-content` permalinks read-only |
| `data-ak-docs-copy` | `docs-copy.js` | "Copiar Markdown" button — reads `window.__akDocsGetMarkdown()` while editing, or the `data-ak-docs-markdown` textarea on the read-only view |
| `data-ak-docs-markdown` | `docs-copy.js` | Hidden `<textarea>` with the raw Markdown, rendered only on the read-only view (no live editor there to query) |
| `data-ak-node-kinds` (on the integrations-map root) | `integration-viz.js` | `App\Enums\ChainNodeKind` as JSON (`{value,label,system,placeholder}`), read once and cached: feeds the kind `<select>` of both block panels. `system` is the only kind that gets the Solution select |
| `data-ak-solutions`/`data-ak-protocols` (on the integrations-map root) | `integration-viz.js` | Same read-once-and-cache pattern as `data-ak-node-kinds` — the Solution/protocol option lists (JSON) feeding the block/edge editor panels. There is no `data-ak-statuses` any more: the integration's own status left the canvas for the page's top bar (see below) |
| `data-ak-integration-summary` (on an integration row) | `integration-viz.js` | Patched via `replaceChildren(document.createTextNode(...))` (never `innerHTML`) after a chain mutation, so the row's chain summary stays current without a page reload |
| `data-viz-*` (F3 canvas internals, e.g. `-toolbar`/`-toolbar-rename`/`-zoom-in`/`-lane-toolbar`) | `integration-viz.js` | Private hooks of the F3 canvas component, NOT `data-ak-*` — they're internal slots of `integration-viz.blade.php`, same exemption as `data-spinner`/`data-label`. `-toolbar-rename` opens the same inline label editor as double-clicking a block, which is the only path a touch device has (see `integration-viz-pointer-events` rule) |
