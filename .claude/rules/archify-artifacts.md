---
paths:
  - "app/Support/Archify/**"
  - "app/Services/Documentation/PageArtifactService.php"
  - "app/Services/Documentation/PageArtifactPromptBuilder.php"
  - "app/Http/Controllers/NotebookPageArtifactController.php"
  - "app/Enums/ArtifactDiagramType.php"
  - "app/Exceptions/PageArtifactFailed.php"
  - "app/View/Components/Documentation/PageArtifacts.php"
  - "resources/views/components/documentation/page-artifacts.blade.php"
  - "app/Actions/Cati/CompareSubmissionTopologies.php"
  - "app/Http/Controllers/SubmissionTopologyDeltaController.php"
  - "app/Exceptions/TopologyCompareFailed.php"
  - "scripts/archify/**"
---

### Archify artifacts — a vendored renderer, and the four questions the canvas can't answer

`scripts/archify/` is a pinned copy of tt-a1i/archify (MIT): a Node CLI that
compiles a typed JSON spec into a self-contained interactive HTML diagram. It
renders the four types the F3 canvas cannot — **sequence, dataflow, lifecycle,
workflow** — from a documentation page's prose, and stores each as media on that
page.

**`architecture` is deliberately not among them.** That question already has an
answer in this app, editable, feeding the ecosystem map; a second architecture
of the same systems is the second truth the diagrams module was collapsed to
avoid. An artifact is never a chain, never becomes one, and derives nothing.

What is vendored is the RUNTIME only (`bin`, `renderers`, `schemas`, `delta`,
`assets`, `brand-marks`, `scripts`) — about 2.4 MB of the 8.5 MB package; its
tests, examples and authoring docs are not part of the copy. **`scripts/` is not
optional**, which is easy to get wrong when pruning: `deliver` shells out to
`scripts/check-render-output.mjs`, and without it every delivery fails with
"Final artifact check failed without a classified diagnostic" — a message that
names nothing.

Five things to keep:

- **Archify's schema is the contract the model answers, not an IR of ours.** Its
  validator knows its five schemas and its geometry rules; a layer of our own in
  front of it would be a second vocabulary to keep in step with a vendored one,
  and the first drift would surface in the renderer instead of the validator.
  (`ChainDraft` is the opposite case and stays that way — see
  `.claude/rules/page-to-diagram-draft.md` — because the chain is ours.)
- **We fill geometry that is mechanical, never semantic.** A sequence's `y` is
  assigned from message order by `PageArtifactService::normalize()`, along with
  the type, the schema version and the visual preset. Everything the model is
  asked for is a fact about the flow.
- **Lifecycle lane ids are RESERVED**: `main` is required (the phase rail),
  `terminal` is the outcome band, every other id shares the middle event band,
  four lanes maximum. A prompt that invents lane ids fails validation every
  time — found on the first run of the test that covers it.
- **The model may answer "this page has no such diagram"**, and that answer is
  surfaced as itself (`PageArtifactFailed::notDescribed`). It is the most useful
  thing this feature can say about a page, and it is worth more than four boxes
  invented to satisfy the request. Verified against a real page: "Logs de
  integrações" describes where to find logs, not a call sequence, and said so.
- **One repair round, against Archify's own diagnostics** — which name the node
  and suggest a fix ("labelAt [171, 202] or labelDy +55"). A spec still refused
  after that is not converging.

### An artifact is served sandboxed, and that is not decoration

It is a full HTML document with inline scripts, produced deterministically but
carrying labels a model wrote from somebody's page. It is therefore NOT in the
`docs` collection: `MediaController::show()` authorizes `/files/{id}` by that
collection name alone, so putting it there would serve it from this app's origin
with the session's cookies attached. It has its own collection
(`DocumentationPage::ARTIFACTS_COLLECTION`), its own controller, and a response
carrying `Content-Security-Policy: … sandbox allow-scripts` — the CSP directive,
which gives the document a unique opaque origin: its scripts run so the diagram
stays interactive, and `document.cookie` inside it throws `SecurityError`
(verified in a browser, not asserted). `show()` checks the owner AND the
collection, because `{media}` is an id in a URL and nothing about an id is
scoped.

### AS IS × TO BE is the one architecture Archify draws here

`CompareSubmissionTopologies` is the exception to "architecture is not among
the types", and it earns it by not drawing an architecture at all: it COMPARES
two drawings the app already holds. `SubmissionDiagramKind`'s own docblock says
the AS IS and TO BE are drawn rather than uploaded because a picture is
"diffable against nothing" — this is that diff, and no model is involved, so
the same two canvases produce the same answer every time.

`ArchitectureIr::fromCanvas()` is the mapper, and three of its choices are
load-bearing:

- **Grid placement, never free `pos`.** Canvas coordinates are pixels somebody
  dragged blocks to, and two blocks left overlapping is an ordinary state of a
  working canvas — Archify's geometry check refuses that, so passing the pixels
  through would fail a comparison on somebody's drawing habits. Rows and
  columns keep the drawing's READING (what is left of what) and cannot collide.
- **Node identity is the chain INDEX** (`n0`, `n1`, …), because `compare`
  matches components by id and the canvas has no stable identity of its own
  (`removeNode()` reindexes). A block that kept its index reads as moved or
  relabelled; one that did not reads as removed plus added. Anything cleverer
  would be inventing an identity the data does not have.
- **Gaps are widened to 120/80 and connection labels capped at 14 characters.**
  A label is painted at the middle of its line: between two columns that is the
  gap, but between two cells of the SAME column it lands on the blocks, so a
  vertical connection's label carries `labelDx`. All three numbers came from
  the validator refusing the defaults.

`isFilled()` — not "has nodes" — decides whether a canvas counts as drawn, on
both the panel and the action. `SubmissionDiagram::open()` seeds a root block,
so an untouched canvas has one node and would otherwise compare as a drawing of
one box.

### The sidecar's timeout is wall-clock, and this box's clock steps

`services.archify.timeout` guards a CLI that answers in ~0.4 s. On the WSL2 dev
box a loop of 15 identical invocations had one report 81 s and the next report a
NEGATIVE duration — the clock jumping forward and back (the same machine fault
`.claude/rules/testing-time.md` documents for rate-limit windows). Symfony's
process timeout reads that as a hung sidecar. Tests raise the timeout in a
`beforeEach` for that reason and no other; don't "fix" a flake here by loosening
the production default.
