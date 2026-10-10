---
paths:
  - "app/Support/Documentation/StepScene.php"
  - "app/Services/Documentation/SceneDraftService.php"
  - "app/Services/Documentation/SceneDraftPromptBuilder.php"
  - "app/Http/Controllers/NotebookPageSceneController.php"
  - "app/Http/Requests/DraftPageSceneRequest.php"
  - "app/Exceptions/SceneDraftFailed.php"
  - "resources/js/modules/docs-scene.js"
  - "resources/js/modules/docs-tools/scene.js"
---

### Animated scenes — the model picks the words, the code draws the picture

A SCENE is an animated figure inside a documentation page. One type exists, the
"Fluxo em etapas" (`{% scene type="steps" caption="…" %}` with
`{% step title="…" highlight="true" %}detalhe{% endstep %}` inside — the seventh
construct of the dialect, the third of our own). More types are meant to follow
as new values of `type`, not new endpoints.

**The split is the whole design.** The model (Gemini Flash, through
`services.documentation_ai`) only ever returns CONTENT — which steps, in what
order, which one is the "ponto-chave" — as a small JSON object validated by
`StepScene` (2–8 steps, short labels, at most one highlight), with one repair
round, the same shape as `DiagramDraftService`. It never returns a coordinate,
a colour or SVG. `docs-scene.js` decides all of that. This is what makes a fast
model enough, and what keeps a scene from ever coming out crooked or off-brand:
a model asked to draw SVG directly varies wildly per call and hands the page
markup that could carry a script.

Four things that are easy to undo:

- **ONE renderer, in the browser.** GitbookRenderer emits the steps as an `<ol>`
  plus the same data as JSON on the figure (`data-ak-scene`), and
  `docs-scene.js` draws over it; the editor's block calls the same
  `mountScene()` for its live preview. A PHP copy of the picture would drift,
  and only the browser can MEASURE the text to wrap it inside a card. The list
  is the no-JS view, what screen readers read, and what the search index and
  the MCP server see; once drawn it goes sr-only, never away.
- **Columns come from the measured width**, so a resize is a re-layout (the
  ResizeObserver repaints on a WIDTH change only — painting changes the
  height). Therefore a stage must never be hidden with `:empty`: a hidden stage
  measures 0px and draws nothing, which is exactly how the first build shipped
  blank. Hide it with a class that comes off BEFORE `mountScene()` runs.
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
