---
paths:
  - "resources/css/**"
  - "resources/views/**"
  - "resources/js/modules/docs-*.js"
  - "resources/js/modules/docs-tools/**"
  - "app/Support/GitbookRenderer.php"
  - "app/Support/MarkdownText.php"
---

## CSS — Tailwind utilities over custom classes

Default to composing Tailwind utility classes directly on elements, encapsulated
inside a Blade component when the markup repeats. Do **not** reach for a custom
CSS class or a `<style>` block just because a component has several elements —
express the whole thing in utility classes on each element instead.

```blade
{{-- ✅ Utilities on the element, no custom class --}}
<button class="rounded-md px-2.5 py-1 text-xs font-medium text-ink hover:bg-accent-soft">

{{-- ❌ Custom class + separate <style> block for something Tailwind already expresses --}}
<button class="fc-tbtn">
<style>.fc-tbtn { padding: 4px 11px; border-radius: 6px; ... }</style>
```

Use design tokens from `@theme` in `resources/css/app.css` (`bg-surface`,
`text-ink`, `border-line`, `rounded-card`, `rounded-field`, etc.) — never
hardcode a hex color or a one-off `border-radius` that already has a token.

**Legitimate exceptions** (custom CSS is the right tool):
- **Rendered Markdown**, in all three of its flavours — the HTML comes out of a
  renderer, so there is no element to hang a utility class on before it exists:
  `.ak-viz-md` (inline `<style>` in `components/chain/viz.blade.php`, a node's
  comment preview in the F3 canvas), `.html-content` (`app.css`, a whole
  GitBook documentation PAGE — `documentation/partials/_reader.blade.php`
  renders it and `docs-toc.js` reads it; an older note here claimed it was
  dead, it isn't), and `.ak-rich-text`
  (`resources/css/components/rich-text.css`, the short free-text fields — see
  "Two Markdown renderers" below). Keep them separate: `.html-content` types a
  document, `.ak-rich-text` deliberately sets no size and no color so the text
  keeps reading as part of the card it sits in.
- The **code-block theme** — the `--mk-*` palette scoped to `.html-content` in
  `app.css`, plus the `.hljs-*` token rules under it. A syntax theme is a
  CLOSED palette (a family of hues that has to stay distinguishable from
  itself), not a composition of brand tones, and the colored spans are emitted
  by highlight.js, so there is no element to hang a utility on. See "Code
  blocks" below.
- Global browser-chrome overrides that have no per-element target, e.g. the
  scrollbar styling in `resources/css/components/scrollbar.css`.
- `resources/views/components/ecosystem-map.blade.php` (the DOM+SVG ecosystem
  map, radial hub-and-spoke layout) and
  `resources/views/components/chain/viz.blade.php` (the F3 diagram canvas,
  shared by a `Diagram` and a submission's drawings) share the same scoped
  `--viz-*` token set and `.ak-viz-node`/`.ak-viz-node-avatar` classes, so both
  render nodes identically — a legitimate exception since the content (JS-built
  graph nodes/edges) never passes through Blade.

Before adding a new custom class or `<style>` block, check whether the same
result is reachable with `@class([...])`, arbitrary-value utilities (`w-[172px]`),
or an inline `style="height: {{ $dynamic }}"` for genuinely runtime-computed values.
If a past reviewer left dead custom CSS behind (unused decorative classes,
leftover from a copied reference bundle), delete it rather than leaving it —
`grep` the class name across `resources/views` first to confirm it's unused.

### Two Markdown renderers, on purpose

| | `App\Support\GitbookRenderer` | `App\Support\MarkdownText` |
|---|---|---|
| for | documentation pages, authored in the Editor.js block editor | the short free-text fields: a person's/company's `notes`, a solution's `description` and `support_operation_note` |
| speaks | Markdown **+ GitBook notation** (`{% hint %}`, `{% tabs %}`, `{% file %}`) plus one of our own, `{% diagram slug="…" %}` | plain Markdown (GFM), nothing else |
| raw HTML in the source | `html_input=allow` — it's how images arrive as `<figure><img src="/files/{id}">` | `html_input=strip`, plus `allow_unsafe_links=false`: a `<script>` in someone's notes must never run for the next reader |
| single newline | a normal Markdown soft break | rendered as `<br>` (`renderer.soft_break`), because these fields were plain textareas read back with `whitespace-pre-line` and every note already in the database relies on it |
| read side | `.html-content` + `GitbookRenderer` | `x-ui.markdown` + `.ak-rich-text` |
| write side | Editor.js (`docs-editor.js`) | still a plain `<textarea>` — `x-ui.inline-edit type="textarea"`, which prints its own "Aceita Markdown" hint; the panel forms say the same via `x-forms.field`'s `hint` |

Don't merge the two without deciding which of those `html_input` contracts
wins — and don't reach for the Editor.js editor for a small field: it's a
page-level singleton (one `[data-ak-docs-save]`, module-level `dirty`/`locked`
state) with its own save endpoint and a media collection behind
`App\Contracts\Documentable`.

### The reading screen's three regions

`documentation/edit.blade.php` is a flex row of up to four columns: the pages
rail, the reader, the assistant when it is docked, and a right rail. What goes
where follows one rule — **the top bar is for acting on the page, the right
rail is for facts about it.**

- The top bar's right end is the two live indicators, then **Salvar**, then
  every icon button after it. Salvar is the only thing there anyone presses on
  purpose; when a labelled pill ("Abrir especialista") sat between the icons and
  Salvar, the primary action was third in a row of six. "Abrir especialista" is
  an icon now — the panel it opens announces itself in full the moment it does.
- The right rail opens with **"Esse caderno contempla o(s) sistema(s): …"**
  (`x-notebooks.documented-systems`) above the page's own headings. That used
  to be a squares icon in the top bar, which stated nothing: you had to open a
  dropdown to learn something the screen could simply say. Clicking the sentence
  opens the same popover, which moved there whole.
**One stacking order governs the whole screen, and every number in it is
load-bearing**: `.codex-editor` (1, Editor.js's own) < right rail (`@3xl:z-10`)
< top bar (`z-30`). `position: sticky` ALWAYS opens a stacking context, so a
popover's `z-20` only ranks it against its siblings *inside* the rail — the
RAIL is what has to be ranked. Both halves were learned the hard way: at
`z-index: auto` the rail sat under the editor, which swallowed every click
meant for "Salvar vínculos"; at `z-20` it tied with the top bar's share
popover, and a tie in the same stacking context is broken by DOM order, so the
rail (later) painted its text straight through it. Put chrome above content by
ranking the REGION, not the popover: anything the top bar opens is above the
reader and the rail without an argument of its own.

A table is the one block that can break out of that layout: its MIN-content
width can exceed the width it was given, so `width: 100%` does not bind it and
a wide one painted straight over the rail. `GitbookRenderer` wraps every table
in `<div class="ak-table-scroll" tabindex="0">` (a `HtmlDecorator` over
commonmark's own `TableRenderer`, registered at a higher priority — decorate,
don't reimplement), and the frame moves to that div: a rounded border painted on
the table itself scrolls away with it. A table pasted as raw HTML never reaches
the Table node and so is never wrapped, which is why `.html-content table` still
carries the whole frame on its own.

### A diagram citation is PREVIEWED in the editor, not described

The `diagram` block stores a slug and nothing else — the name and the picture
are resolved at render time, so a renamed or redrawn diagram updates every page
citing it without anybody editing prose. That is also why the editor has to
resolve them too: it used to print the raw slug, so an author had no way to tell
whether they had cited the right drawing, and a page reopened later read as
`zfl-bloq-desbloq-cliente`.

`docs-tools/diagram.js` now draws the same card `GitbookRenderer::renderDiagram()`
will, with the same three states (picture / "sem imagem ainda" / "removido"),
from the catalog it already fetches — `diagrams.catalog` carries `pictureUrl`
(null when the canvas has never been saved with one) and `url` per entry, both
built with `route()` server-side, which is what keeps the tool free of a path
of its own. The media is eager-loaded there: `picture()` is a `getFirstMedia()`
per row on a payload meant to be one query.

**A tool that paints itself asynchronously must be `data-mutation-free`.**
Editor.js decides a block changed by watching its DOM, so a preview that
arrives when a fetch resolves reads as an edit: it marks the page dirty and
autosave writes it, seconds after someone merely opened it. The wrapper carries
`data-mutation-free="true"` (Editor.js drops any mutation whose nodes sit inside
one) and the tool dispatches the one real change itself, via
`options.block.dispatchChange()` when a diagram is picked — the same shape as
`docs-tools/code.js`'s language field. Get one half without the other and you
either autosave on load or lose the citation the author just made.

One more distinction the block has to keep: an EMPTY catalog and an
UNREACHABLE one look identical from the lookup table, and they must not read
the same. "Diagrama removido" is a claim about the author's citation; a failed
fetch is a fact about the request, and reporting the first for the second tells
someone their drawing was deleted because a request timed out.

### Code blocks — the fence's LANGUAGE is data, and it is easy to lose

A documentation code block is light (`--mk-*`, a Monokai-light palette) and
syntax-highlighted, and the whole chain hangs off one token: the `xml` in
```` ```xml ````. `GitbookRenderer` hands it to commonmark, which emits
`<code class="language-xml">`; `docs-code.js` wraps each `<pre>` in a panel and
hands it to `docs-highlight.js`, which picks the grammar and returns the name
the panel header shows.

Three things about that chain:

- **highlight.js is loaded on demand and only where there is code.**
  `docs-code.js` reaches it through a dynamic `import()`, so it is a chunk of
  its own and a page with no `<pre>` never downloads it. Highlighting is
  decoration: if that import fails, the panel, its label and "Copiar" are
  already on screen and stay working.
- **An UNLABELED fence is auto-detected, from three grammars and above a
  measured threshold** (`AUTO_SUBSET` / `AUTO_MIN_RELEVANCE`). Both numbers came
  from running every unlabeled block in the dev corpus through
  `highlightAuto` — `bash` and `php` claimed a bare URL at relevance 22 and 14,
  which is why the subset is small. Widen either without measuring and plain
  output starts arriving in four colors.
- **The editor used to DESTROY the token on every save.** `docs-markdown.js`
  matched the fence's language and dropped it, and always serialized a bare
  ```` ``` ````, so opening a page and pressing nothing but Salvar rewrote
  every ```` ```xml ```` as ```` ``` ````. It is carried now (`normalizeLanguage()`,
  one helper both ends share) and `@editorjs/code` is subclassed
  (`docs-tools/code.js`) to hold it in the block's data and offer a field for
  it — upstream's data is `{code}` and nothing else, so a plain extra key would
  not survive its `save()`. Any new tool whose Markdown form carries an
  attribute needs the same treatment: parse it, hold it in block data, write it
  back.
