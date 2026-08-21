---
paths:
  - "app/Services/Flowspec/**"
  - "app/Actions/Flowspec/**"
  - "app/Http/Controllers/FlowspecChatController.php"
  - "app/Http/Controllers/FlowspecMessageController.php"
  - "app/Http/Controllers/FlowspecAttachmentController.php"
  - "app/Http/Requests/StoreFlowspec*.php"
  - "app/Http/Requests/Concerns/GuardsFlowspecContext.php"
  - "app/Models/FlowspecAttachment.php"
  - "app/Models/FlowspecChat.php"
  - "app/Support/Context/**"
  - "app/View/Components/Flowspec/**"
  - "resources/js/modules/flowspec-chat.js"
  - "resources/views/components/flowspec/**"
  - "resources/views/flowspec/**"
---

### flowSpec context — exactly two kinds, attached to the CONVERSATION, under a meter

The Especialista em Integrações (F8) accepts context in exactly **two** shapes,
and the composer's 📎 menu has exactly two items to match:

1. **Documents from the inventory** — a `DocumentationPage` (from a Solution
   *or* a standalone `DocumentationGroup`) or an `Integration`'s own
   documentation, chosen in the picker panel.
2. **Material the user brings** — a file from disk, or a long paste that becomes
   a text attachment on its own.

Don't add a third. The previous design had one (a standalone "flowSpec de
referência" editor) and it was removed on purpose: a pasted pipeline is
recognized for what it is by `AttachFlowspecText` and still gets minified and
still gets its own prompt section, so the user performs one gesture (paste)
instead of choosing which of three slots it belonged in. A pasted pipeline is a
`text` attachment carrying `is_flowspec_reference`, never a kind of its own.

#### Nothing enters a prompt that nobody attached

`FlowspecContextResolver` used to match Solution names in the request text and
fold up to 60k characters of their documentation into the prompt by itself. That
is gone, and it must not come back: it was spend the composer's meter could not
show before sending, so the number the user read and the request they paid for
were different numbers.

The name-matching itself survives as `suggestFor()` — no AI, no embeddings, just
a word-boundary scan over the Solution catalog — but it only ever produces
**suggestions**: a row of "adicionar ao contexto" buttons under the composer
(`data-ak-fs-suggest-add`) and next to a conversational reply. One click away
from being real context the meter can see.

The matching runs in PHP rather than SQL because `Str::ascii()` folds "ó"→"o"
portably between SQLite (dev) and PostgreSQL (prod) with no extension
(`unaccent`) or driver-specific collation.

#### Chat-scoped, not message-scoped

`flowspec_attachments` hangs off `FlowspecChat`. What you attach stays in the
conversation and is re-sent every turn — the mental model of a Claude project.
The old per-message `meta.solution_ids` / `document_refs` / `reference_flowspec`
were reset after each send, so the *second* question in a thread was answered
without the documentation the first one had.

Consequences to keep in mind when touching this:

- **`StoreFlowspecMessageRequest` carries no context fields at all.** Only
  `flowspec.store` (creating a chat, the one moment there is nothing to attach
  to) accepts staged context, as `documents[]` / `files[]` / `texts[]`.
- **A `document` attachment stores a REFERENCE, never a copy.** The page is read
  live when the prompt is built, so editing documentation updates every
  conversation pointing at it. That is also why its token cost is measured live
  (`FlowspecContextBudget`) instead of cached on the row — a cached size would
  drift the moment someone edits the page.
- **A `file`/`text` attachment does store its content**, because there is no
  other copy of it anywhere; its `token_estimate` is written once at ingest and
  is authoritative.

#### The ceiling is real, and the history is what gives

Two ceilings, and the difference is the whole design (`FlowspecContextUsage`):

- `limit` (`context_limit_tokens`) is the whole request's budget.
- `attachLimit` is `limit` minus `history_reserve_tokens` — the point past which
  **attaching is refused**, checked in `GuardsFlowspecContext` on the raw inputs
  *before* anything is ingested. Checking after would mean rolling back DB rows
  *and* files MediaLibrary already wrote to disk.

History is never refused — locking someone out of their own conversation is a
worse outcome than dropping its oldest turns — so
`FlowspecPromptBuilder::historySection()` trims oldest-first to fit whatever is
left, always keeping the newest turn even when it alone blows the allowance, and
reports the count so `thread.blade.php` can say so. A chat that quietly forgot
its own beginning reads as the model losing track.

Because attaching is refused rather than trimmed, **nothing downstream may trim
attached context again**. `documentationSection()` has no "omitted by budget"
note any more, and adding one back would contradict the meter the user read. The
one exception is the aggregate byte ceiling on native attachments
(`max_attachment_bytes`), which is the provider's request limit, not our budget —
and even that is reported, never silent.

Every estimate **over-counts on purpose** (`TokenEstimator`: 3.5 chars/token for
PT-BR and minified JSON, both of which tokenize worse than the familiar English
"~4"). A guard that undershoots is the failure it exists to prevent.

#### Traps already paid for

- **`NativeAttachmentType` is the single place that decides what rides along to
  the model as a PDF/image.** It is asked twice — at ingest, to price the file,
  and at prompt time, to actually send it. A file the budget charged for but the
  prompt dropped is a meter that lies. It consults the **extension** alongside
  the mime type, because a stored mime is whatever the server sniffed and is
  regularly less specific than the file plainly is.
- **`ContextExtractionState::Skipped` is not one thing.** `SourceTextExtractor`
  returns it both for a PDF/image (deliberately unextracted — it goes natively,
  and it costs) and for a format it simply cannot read (it goes nowhere, and it
  costs nothing). Only the mime/extension tells them apart; the state alone
  never does.
- **Never `SUM(LENGTH(...))` a `jsonb` column.** `flowspec_examples.flow_spec`
  is `jsonb`: PostgreSQL has no `length(jsonb)` and errors, while SQLite stores
  it as TEXT and answers happily — a query that passes every test and 500s in
  production. `FlowspecContextBudget::worstCaseCorpusTokens()` measures in PHP
  for exactly this reason. `documentation` is `longText`, so `LENGTH()` on *it*
  is portable.
- **The picker must list `DocumentationGroup` pages, not only a Solution's.**
  Almost all of this inventory's documentation lives in imported GitBook spaces
  (38 groups, 613 of 617 documented pages). Listing only Solutions offered 4 of
  them, which reads as "there is nothing to attach".
- **The picker renders every row and filters in the browser** (~1MB of HTML,
  ~200ms server, ~430ms to open, measured 2026-08-21 against 618 rows). Groups
  start collapsed and the filter opens what matches. A debounced round trip per
  keystroke is the one thing that would take instant narrowing away; if the
  corpus grows enough to make this hurt, paginate the *groups*, not the filter.
- **Staged files reach the server through a `DataTransfer`.** `input.files` is a
  read-only `FileList`, and rebuilding it from the staged array is what lets a
  plain `new FormData(form)` in `ajax-post.js` carry them with the first message
  — no custom submit path. Clear the input after an immediate upload on an
  existing chat, or the next message re-sends the same bytes.
- **A multi-file pick uploads one file at a time, awaited.** The ceiling is
  enforced per REQUEST, against the conversation as it stands when that request
  is validated, so N parallel uploads are each measured against the state before
  any of them landed — files that individually fit collectively blow a limit all
  of them passed, and the last response repaints the panel from a snapshot taken
  before its siblings committed. Batching them into one `files[]` body would be
  atomic but exceeds `post_max_size` long before `max:20480` per file does, so
  the fix is the `for…of` with `await` in `attachFiles()`, not a batch.
- **`withValidator`'s `after` callback sees RAW input, not a validated set.**
  `Validator::passes()` fires those callbacks unconditionally — the rules above
  them having already failed does not skip them. So `count($this->input('documents'))`
  in `guardContextCount()` met a `documents=page:1` scalar the `array` rule had
  just rejected and threw a TypeError: a 500 out of a request whose 422 was
  already written. Count through the same parsers the controller attaches with
  (`documentRefs()`/`pastedTexts()`/`uploadedFiles()`), which ignore anything
  that isn't the shape they expect.
