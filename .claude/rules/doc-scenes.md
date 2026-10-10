---
paths:
  - "app/Support/Documentation/StepScene.php"
  - "app/Support/Documentation/BeforeAfterScene.php"
  - "app/Enums/SceneType.php"
  - "app/Services/Documentation/SceneDraftService.php"
  - "app/Services/Documentation/SceneDraftPromptBuilder.php"
  - "app/Http/Controllers/NotebookPageSceneController.php"
  - "app/Http/Requests/DraftPageSceneRequest.php"
  - "app/Exceptions/SceneDraftFailed.php"
  - "resources/js/modules/docs-scene.js"
  - "resources/js/modules/docs-tools/scene.js"
---

### Animated scenes — the model picks the words, the code draws the picture

A SCENE is an animated figure inside a documentation page — the seventh
construct of the dialect, the third of our own. Two types exist, and
`App\Enums\SceneType` is the one switch everything turns on (prompt, IR,
editor form, renderer):

- **"Fluxo em etapas"** — `{% scene type="steps" caption="…" %}` with
  `{% step title="…" highlight="true" %}detalhe{% endstep %}` inside
  (`StepScene`: 2–8 steps).
- **"Antes → depois"** — `{% scene type="before-after" from="Hoje" to="Com a
  integração" %}` with one self-closing `{% change aspect="…" before="…"
  after="…" %}` per row (`BeforeAfterScene`: 2–6 rows). An EMPTY side is a
  statement, not a gap: empty `before` = new ("Novo"), empty `after` = it stops
  existing ("Sai"); both empty or both equal is refused as "not a change".
  `from`/`to` name the two columns and default to "Antes"/"Depois".

A new type is a new `SceneType` case plus its IR, its prompt section
(`SceneDraftPromptBuilder`), its row spec in the editor tool's `TYPES` table and
its renderer in `docs-scene.js`'s `RENDERERS` — never a new endpoint. The editor
has ONE tool with one "/" entry per type (an Editor.js toolbox array), because
everything around the rows — generate, preview, caption — is the same.

**The split is the whole design.** The model (Gemini Flash, through
`services.documentation_ai`) only ever returns CONTENT — which steps or
changes, in what order, which one matters most — as a small JSON object
validated by the type's IR (short labels, at most one highlight), with one repair
round, the same shape as `DiagramDraftService` (and a way out, `{"error": …}`,
for a page with no sequence / no change in it). It never returns a coordinate,
a colour or SVG. `docs-scene.js` decides all of that. This is what makes a fast
model enough, and what keeps a scene from ever coming out crooked or off-brand:
a model asked to draw SVG directly varies wildly per call and hands the page
markup that could carry a script.

Four things that are easy to undo:

- **ONE renderer, in the browser.** GitbookRenderer emits the text (the steps as
  an `<ol>`, the changes as a `<table>`, both `.ak-scene__text`) plus the same
  data as JSON on the figure (`data-ak-scene`), and
  `docs-scene.js` draws over it; the editor's block calls the same
  `mountScene()` for its live preview. A PHP copy of the picture would drift,
  and only the browser can MEASURE the text to wrap it inside a card. The text
  is the no-JS view, what screen readers read, and what the search index and
  the MCP server see; once drawn it goes sr-only, never away.
- **Columns come from the measured width**, so a resize is a re-layout (the
  ResizeObserver repaints on a WIDTH change only — painting changes the
  height). Therefore a stage must never be hidden with `:empty`: a hidden stage
  measures 0px and draws nothing, which is exactly how the first build shipped
  blank. Hide it with a class that comes off BEFORE `mountScene()` runs. And
  measure the CONTENT box (`clientWidth` minus padding): the editor's preview
  box has padding, and measuring `clientWidth` drew the figure 2rem too wide.
- **The endpoint writes nothing.** `notebooks.pages.scene` answers with the
  scene; the editor drops it into the block and the page is saved by the
  editor's own save. It reads the editor's CURRENT Markdown (`content`), since
  the paragraph just written is usually the one the figure is for; the text is
  masked and stripped like every other page handed to a model, and a page that
  describes no sequence gets the model's own "não há fluxo aqui" (`error`) as a
  422 rather than invented steps.
- **The Assistant may not rewrite a scene.** `BlockVault` freezes the whole
  block, like the card grid: nothing in it needs an id, but it is a nested
  notation the model was never taught, and a rewrite into a numbered list would
  silently take the animation away.

The animation follows pending-drawing.js: one `@keyframes` per mark over ONE
shared cycle (cards land in order, each arrow draws with a lime marker riding
it, then data keeps flowing along the arrows until the cycle clears), class
names prefixed per figure since keyframes are document-global, colours from the
app's CSS variables, and the finished figure shown still under
`prefers-reduced-motion`.

**Every figure has a pause button** (`.ak-scene-toggle`, created by
`mountScene()`, so the reader and the editor's preview both get it). A loop that
runs as long as the page is open must be stoppable (WCAG 2.2.2). Paused shows
the FINISHED frame — the same CSS as reduced motion, `.is-paused` on the SVG —
because freezing mid-cycle would leave half the figure invisible; resuming
repaints, so the loop starts over. The state lives on the stage, so a re-layout
keeps it. The button straddles the corner of the FRAME (its nearest positioned
ancestor), never the cards; the SVG itself is `aria-hidden`, since the text
beside it is what assistive technology reads — which is also why the stage
must NOT be `aria-hidden` (the button lives in it).
