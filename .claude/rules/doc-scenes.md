---
paths:
  - "app/Support/Documentation/StepScene.php"
  - "app/Support/Documentation/BeforeAfterScene.php"
  - "app/Support/Documentation/TreeScene.php"
  - "app/Support/Documentation/ComparisonScene.php"
  - "app/Support/Documentation/TimelineScene.php"
  - "app/Support/Documentation/SceneIcons.php"
  - "resources/data/scene-icons.json"
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
construct of the dialect, the third of our own. Five types exist, and
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
- **"Árvore"** — `{% scene type="tree" %}` with one self-closing
  `{% node level="0|1|2" label="…" detail="…" %}` per item, in reading order
  (`TreeScene`: 3–16 items, at most 6 children each, three levels). The model
  answers NESTED (`root` + `children` — nothing can dangle) and the page stores
  a FLAT outline with depths (what the editor's ⇤/⇥ rows and the reader both
  speak); `TreeScene` is the bridge, and `normalizeLevels()` (mirrored by
  `treeNodes()`/`clampLevels()` in JS) repairs a ragged hand-edited outline:
  one root, no jump of more than one level, nothing past level 2. In the
  editor every gesture moves the BRANCH — removing a parent lifts its children,
  ⇤/⇥ carry the subtree, and ⇥ is refused where it would push a child past the
  last level. Drawn as an org chart when 2–4 branches fit side by side, as an
  indented outline (capped at 620px, centred) otherwise; its lines have no
  arrowheads and no streaming dots — they mean "contains", not "goes to".
- **"Comparação lado a lado"** — `{% scene type="compare" %}` with one
  self-closing `{% option name detail icon highlight %}` per column, then one
  `{% criterion name icon %}` per row whose BODY is one value per option, in
  option order, each line led by its mark: `+` good (atende), `~` partial,
  `-` bad, `·` none — EVERY line carries one, so a text that starts with "-"
  is never misread (`GitbookRenderer::MARK_PREFIXES`, mirrored in
  docs-markdown.js). `ComparisonScene`: 2–4 options, 2–6 criteria, at most one
  recommended option. Unlike an icon, a MARK is content — an unknown one is a
  validation error — and a criterion whose value count does not match the
  options is refused, since a value in the wrong column is a wrong answer
  that looks right; a hand-edited one is padded/cut to the column count on
  both ends instead. It is not the "antes → depois": that compares two
  moments of one thing, this compares alternatives. Drawn as a matrix (rows
  fill in, marks stamp, then the recommended column's lime band + "Recomendada"
  land last as the verdict), stacked per criterion on a narrow screen. Its
  editor is CompareForm, not the generic rows: two linked lists, so adding,
  moving or removing an option does the same to that column of every
  criterion.
- **"Linha do tempo"** — `{% scene type="timeline" %}` with one
  `{% event when="…" title="…" status="done|current|planned" %}detalhe
  {% endevent %}` per event, in order (`TimelineScene`: 2–8 events). It is not
  the "fluxo em etapas": a process repeats and has no date, a timeline happens
  once — every event carries `when`, written EXACTLY as the page writes it
  ("Jan/2026", "Sprint 3", "Curto prazo"), never parsed or converted, since
  the order is the list's and the corpus dates things a dozen ways; a page
  with no dates gets the model's refusal. `status` is content like a mark (an
  unknown one is a validation error) and at most ONE event is `current` —
  where the reader stands; the model is told not to infer it from today's
  date. Drawn along an axis, solid through the past and dashed past "now"
  (the current event, else the last done one, else — when the page gave no
  status — all solid), with an arrowhead into the future; events alternate
  above and below it, "Agora" pulses, a milestone (`highlight`, "Marco") is a
  diamond. Vertical below ~560px. A card may be wider than one spacing since
  neighbours sit on opposite sides, but NOT wider than `(2.5·spacing − 14) /
  1.5`: the first card is pushed inwards to stay in the figure, and the next
  card on its side must still clear it — the editor's narrower preview is
  where that overlap first showed. The editor's rows carry a status button
  (· ✓ ● ○) on the first line with the date and the title; choosing "agora"
  clears it from every other row.

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
for a page with no sequence / change / hierarchy in it). A provider that does
not answer within `scene_timeout` becomes `SceneDraftFailed::unavailable()` — a
422 saying "tente de novo", not a 500: a real run hung for 45s on a small page
that answered in 7s next time. It never returns a coordinate,
a colour or SVG. `docs-scene.js` decides all of that. This is what makes a fast
model enough, and what keeps a scene from ever coming out crooked or off-brand:
a model asked to draw SVG directly varies wildly per call and hands the page
markup that could carry a script.

Four things that are easy to undo:

- **ONE renderer, in the browser.** GitbookRenderer emits the text (the steps as
  an `<ol>`, the changes and a comparison as `<table>`s, a tree as nested
  `<ul>`s, all `.ak-scene__text`) plus the same
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

**Icons come from a closed catalogue, and ONE file is it.**
`resources/data/scene-icons.json` (key → PT-BR label + Heroicons v2 outline
paths, the set the app's own interface draws with) is read by `SceneIcons` to
validate and to list in the prompt, imported by `docs-scene.js` to draw, and
offered by the editor's picker (search + grid + "Sem ícone"). Two lists would
drift the first time an icon was added to one. Any step, change or tree node
may carry `icon="…"`. An icon is DECORATION: a name outside the catalogue is
dropped (`SceneIcons::sanitize()`), never a validation error — failing a scene
or spending a repair round over a picture is the wrong trade. Adding an icon is
one entry in the JSON (copy the paths from `o-{name}.svg` in
vendor/blade-ui-kit/blade-heroicons); keys stay ASCII kebab-case, since the
model writes them.
