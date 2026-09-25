---
paths:
  - "app/Support/Documentation/ChainDraft.php"
  - "app/Actions/Documentation/CreateDiagramFromDraft.php"
  - "app/Services/Documentation/DiagramDraftService.php"
  - "app/Services/Documentation/DiagramDraftPromptBuilder.php"
  - "app/Http/Controllers/NotebookPageDiagramController.php"
  - "app/Exceptions/DiagramDraftFailed.php"
  - "app/Support/DiagramSlug.php"
---

### Drawing a page — the IR is not the chain, and that is the whole design

"Desenhar esta página" reads a `DocumentationPage` and creates an ordinary
`Diagram` from what its prose describes. Four pieces, in one direction:
`DiagramDraftService` (one model call, at most one repair round) →
`ChainDraft` (a pure value object that validates the IR) →
`CreateDiagramFromDraft` (resolves names, builds the chain, creates the row) →
the canvas.

**`ChainDraft` deliberately is NOT `chain`.** A chain addresses its edges by
INTEGER INDEX into `nodes` — right for a canvas that reindexes on delete
(see `.claude/rules/diagram-chain-canvas.md`), wrong to ask a language model
for: an off-by-one in `{"from": 3}` is invisible in the output, passes every
syntactic check, and draws a confident arrow between the wrong two systems. In
the IR a node carries a string `id` it chose itself and an edge names those ids,
so a wrong reference is a name that does not exist and the validator says which.
The conversion to indices happens once, in the action, against a map it built.

**A `solution` is a NAME, resolved EXACTLY** (`whereFoldedIs`, never
`whereFolded`). The prompt is handed the whole catalog — all of it or none of
it, the same rule `pageCatalog()` follows, because a truncated list reads as
complete — so the model can only echo a real name back. A name that resolves to
nothing becomes a FREE-TEXT block carrying that name, never a near match:
`SyncDiagramFromChain` derives `participants` from `solution_id` and the
ecosystem map is a reading of those, so a fuzzy match would not merely mislabel
a block, it would draw a relationship in the map between two systems that have
none. "SAP" must not silently become "SAP S/4HANA".

Three more things that are easy to undo:

- **The fallback label is the `solution` NAME, not `label`.** The normal draft
  — and the one the prompt demonstrates — identifies a system by `solution`
  alone and carries no `label` at all, so reading only `label` when the name
  fails to resolve produces an EMPTY block. Caught by a test, not by review.
- **This is the one model call in the app that answers inside a request.** The
  three queued ones are conversations (history, minutes, a thread to show
  "gerando…" in); this is one button and one small object, so the button's own
  spinner is the progress indicator and `services.documentation_ai.diagram_timeout`
  (60s, its own key — the shared 180s is a hung tab here) is the ceiling. If it
  grows a real loop, move THIS SERVICE behind a job: nothing above it assumes
  the call is fast, only that it is one call.
- **It shares no contract with the Documentation Assistant, on purpose.** That
  one returns prose plus at most one 4-backtick block that REPLACES the page;
  this returns JSON and never writes the page at all. Folding the second into
  the first would mean a reply whose KIND has to be guessed from its content,
  and `.claude/rules/documentation-assistant.md` is a long account of what that
  guess costs. The page is read (masked and stripped, like every other surface
  that hands a page's text to a model) and left exactly as it was.

Nothing links the page and the drawing afterwards. A page reaches a diagram by
CITING it (`{% diagram %}`) — the `documentation_pages.diagram_id` FK was
removed for reasons written up in `.claude/rules/cadernos-notebooks.md`, and
this feature must not quietly reintroduce it.

**Which is exactly why the drawing opens in a NEW TAB** (`data-ak-ajax-target`
on all five menu items — see `.claude/rules/data-ak-attribute-reference.md`).
With no link back, a diagram's own arrow goes to the diagrams index, so
replacing the document left whoever pressed the button on a list, one click
from prose they had not finished — and, on an unsaved page, past the edits they
had not committed. The tab is opened in the click handler rather than when the
answer arrives: this is the ONE synchronous model call in the app, and by then
the gesture's user activation is long expired and every browser blocks the
popup silently. The response's own message says a tab was opened; the sentence
about checking the blocks is FLASHED, so it is read in the tab it applies to.
