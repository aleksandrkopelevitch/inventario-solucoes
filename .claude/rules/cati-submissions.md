---
paths:
  - "app/Models/Submission*.php"
  - "app/Models/ApprovedTopology.php"
  - "app/Enums/Submission*.php"
  - "app/Enums/ConformanceVerdict.php"
  - "app/Support/Cati/**"
  - "app/Services/Cati/**"
  - "app/Actions/Cati/**"
  - "app/Http/Controllers/Submission*.php"
  - "app/Jobs/GenerateSubmissionChatReply.php"
  - "app/Jobs/PreReviewSubmission.php"
  - "app/Jobs/CondenseSubmissionForSlides.php"
  - "resources/views/submissions/**"
  - "resources/views/components/submissions/**"
  - "resources/js/modules/cati-chat.js"
  - "scripts/render_deck.py"
  - "resources/cati/**"
---

### The Comitê de Arquitetura module (`/submissions`)

Preparing a proposal for Leo's architecture committee: gather the material,
interview whoever brings it, render the deck, record the deliberation. Four
tabs — Preparação, Documento, Diagramas, Comitê — all reachable at any point.
The reasoning behind each phase is in `docs/cati-fase-{1,2,3,4}.md`; this file
is only what a change here has to keep true.

The submission's own drawings are covered elsewhere: the canvas contract in
`.claude/rules/diagram-chain-canvas.md`, the AS IS × TO BE comparison in
`.claude/rules/diagram-models.md`, the media collections in
`.claude/rules/media-and-uploads.md`.

### The workbench REPORTS a stage, it never enforces one

`SubmissionStages` derives four stages from the record on every render — no
model call, no stored column. Preparing a submission is genuinely
back-and-forth (a question about costs sends you back to the material,
confirming a section reopens the one before it), so a real stepper would be a
lie: nothing is locked, nothing has to be clicked to advance, and a stage flips
to done because its condition became true.

There is deliberately **no "skipped" state and no way to tick a stage by
hand**. A checkbox claiming the material is gathered when it is not is worse
than an honest empty circle; a stage that really was skipped just stays
unfinished while the pointer moves past it.

### The checklist keeps two questions apart, and that separation is the feature

`SubmissionRequirements` answers with three lists, and the split is why this
module lives inside the inventory instead of in a document tool:

- **`facts`** — what the catalog ALREADY knows about the Solution. They are
  reported so they can be confirmed, and must never be turned into questions.
  Asking an architect which cloud their own system runs on, when the record
  right there says `gcp`, is exactly the friction this replaces.
- **`sections` / `structural`** — what genuinely nobody has answered yet.

A fact carries the sections it INFORMS rather than being filed under one: the
vendor shows up in the summary, in the operating model and in the costs, and a
1:1 mapping would throw that away.

It is advisory — it never blocks saving or submitting — and it feeds both the
checklist widget and the interview's prompt, so the assistant cannot ask about
something it can already read. Same contract as
`App\Support\Documentation\DocumentationRequirements`; change one and look at
the other.

### State is per SECTION, and only a human's signature counts

`SubmissionSectionState` is the trust model of a generated document:
`Drafted` means the assistant proposed the text and nobody signed it,
`Confirmed` means a person read it and took responsibility. Only `Confirmed`
ticks a box in the ticket's final checklist, and the UI keeps a visible mark on
anything still `Drafted`. Collapsing the two — or letting a draft count — turns
the whole document into something nobody actually said.

### The interview writes SECTIONS, not messages

`SubmissionChatService` calls the model once per turn and splits the answer
into conversation plus per-section drafts, each in a **4-backtick**
` ````rascunho:<section_key> ` fence (the same convention the documentation
assistant uses, and for the same reason: the reply has to be readable as a
reply while carrying a draft).

- **No correction loop**, unlike the flowSpec generator: Markdown prose has no
  strict shape to validate against.
- The one thing that IS validated is the **section key**. A draft addressed to
  a section that does not exist is DROPPED, never written somewhere
  approximate — and the rejection is reported rather than swallowed.
- The job hands the service a freshly deserialized model with no relations
  loaded, and strict mode does not arm on a single fetch, so the eager load is
  explicit. See `.claude/rules/eloquent-strict-and-search.md`.

Prompt changes here are verified with **live turns**, not string assertions
against the built prompt: a prompt that contains the right sentence and still
interviews badly passes every such test.

### `ConformanceChecks` is the single source of truth for the standards signals

It grades the submission against the corporate standards the CATI form asks
about — SDLC, diagrams, observability, security, and the M2C target cloud —
deterministically and for free, so the committee argues only about the
exceptions.

`DeviationRules` used to carry its own copy of the keyword sets and its own
idea of what counts as covered. It now derives its questions from the verdicts
here, so a question fires exactly when a check is not `Ok` and the two cannot
drift. **Do not reintroduce a second list.**

Two properties to keep: the term lists hold **one spelling each** (haystack and
needle are both folded through `Fold` before they meet, so an accent is not a
second entry to remember — the list used to carry duplicates half-heartedly),
and the check reports whether the submission SAYS something about a standard,
never whether what it says is any good. The second is a judgement it cannot
make, and claiming it would be the dishonest half.

### The deck: spec in PHP, `.pptx` in Python

`BuildDeckSpec` → `DeckSpecValidator` → `scripts/render_deck.py` (python-pptx
sidecar, opening `resources/cati/cati-template.pptx`). Same discipline as the
flowSpec generator: whatever decides CONTENT produces JSON, it is validated,
and only then does something else write the file — **nothing downstream of the
validator can invent a slide**. The fidelity lives in the corporate template,
not in the script, so a layout fix belongs in the `.pptx`.

A diagram enters the deck as an **image with a link**, never as a native
PowerPoint shape. A native shape would create a second place where the drawing
can be edited, and somebody nudging a box during the meeting is precisely the
drift this module exists to eliminate.

### Approval does NOT write topology

A submission's TO BE is a free graph: it may describe several diagrams at once,
or one that does not exist yet. An approval that guessed the target would
overwrite real topology with a guess, so approving records an
**`ApprovedTopology`** — a SNAPSHOT of the chain at approval — and a human
chooses which `Diagram` it becomes.

- Applying goes through `writeChain()` + `afterChainMutation()`, the same door
  the canvas uses, so the derived columns re-derive without `ApplyApprovedTopology`
  knowing they exist. Assigning `chain` directly would be the one place in the
  app where topology changes without them following.
- The snapshot is what gets written, not whatever the drawing has become since.
- A pending row is visible on BOTH sides (the submission's Comitê tab and a
  notice on the Solution), and closes two ways that say different things:
  **APLICADA** ("the catalog now says this") and **JÁ REFLETIDA** ("the catalog
  was already right"). Silence is what caused the drift this record exists to
  end.

This record exists because `SubmissionDiagram::afterChainMutation()` is
deliberately empty — a proposal must not write the catalog, because a proposal
can be rejected — which reopened a hole an earlier phase had declared closed.

**`approved_topologies.chain` is the third table storing the
`{solution_id, label, kind}` node shape**, and its foreign key only covers the
solution the submission was ABOUT. Anything sweeping chains for a departing
solution has to include it as a snapshot rather than as a `ChainCanvas` —
nothing draws or edits it, so the contract's media and URL half would be a
costume. See `App\Actions\DeleteSolution`.
