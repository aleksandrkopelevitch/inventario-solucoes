---
paths:
  - "app/Models/Submission*.php"
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

The submission's four pictures (AS IS, TO BE, C4 C1/C2) are uploads; their
media collection is in `.claude/rules/media-and-uploads.md`. AS IS / TO BE
were drawn on an in-app canvas, with an AS IS × TO BE diff, until 2026-11-27
(docs-and-specs/done/solution-relationships-from-uml.md, phase 4b).

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

A diagram enters the deck as an **image**, never as a native PowerPoint
shape. A native shape would create a second place where the drawing
can be edited, and somebody nudging a box during the meeting is precisely the
drift this module exists to eliminate.

### Approval does NOT write topology

A proposal can be rejected, so nothing in a submission writes the catalog's
topology. Who talks to whom is the documentation's: the UML
diagrams in caderno pages and the interconnections recorded on the solution
page (`.claude/rules/solution-relationships.md`). An approval publishes the
proposal's prose and its TO BE picture into the solution's caderno
(`PromoteApprovedSubmission`); the relationships follow once somebody documents
them there. Until 2026-11-27 an approval recorded an `ApprovedTopology` for a
person to apply into a catalog drawing; both went with the topology drawings.

**Recording an APPROVAL asks the module it writes into** (since 2026-11-02): it
publishes into the solution's caderno, so `RecordSubmissionDecisionRequest`
also wants `create` on `Notebook` (a 422 that says why; a rejection writes
nothing outside the submission and needs only `update` on it). Before that, a
Committee Editor with no Documentation level could publish a page.

### Only an admin or the committee leader records the deliberation (2026-11-03)

`SubmissionPolicy::decide` — `isAdmin()` or `User::isCommitteeLeader()` (the
`users.committee_leader` flag, set by an admin on the account's access card,
and only while the committee module is open to that account). It gates
"Registrar deliberação" (`RecordSubmissionDecisionRequest`), the form itself on
the page, and ticking ressalvas. Before, `update` covered it, so an author
could record the verdict on their own proposal.

The header's status select never takes a verdict (`UpdateSubmissionFieldRequest`
refuses aprovada / aprovada com ressalvas / reprovada for everyone — a verdict
set there also skipped the publication), and a decided submission's status
moves only for someone who may decide.

An approval still also needs documentation rights (above): a leader with no
documentation level can reject, not approve.
