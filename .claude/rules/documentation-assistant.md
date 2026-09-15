---
paths:
  - "app/Services/Documentation/DocumentationChat*.php"
  - "app/Services/Documentation/ContextPage*.php"
  - "app/Services/Documentation/ContextDocument*.php"
  - "app/Support/Documentation/BlockVault.php"
  - "app/Support/Documentation/LiteralVault.php"
  - "app/Support/Documentation/DocumentationRequirements.php"
  - "app/Http/Controllers/Concerns/AssistsDocumentation.php"
  - "resources/js/modules/docs-chat.js"
---

### The assistant reads other pages; it rewrites exactly one

A Documentation Assistant turn can be handed OTHER documentation pages as
reference (`documentation_chat_messages.context_page_ids`, resolved by
`App\Services\Documentation\ContextPageResolver`). It exists beside the
caderno's uploaded context documents rather than inside them because the two
answer different questions: a document is material somebody brought from
outside and belongs to the caderno; a page is documentation this app already
holds, is always text, and is regularly in ANOTHER caderno — the page most worth
showing while documenting an integration is the one describing the system on the
other end of it. That is why the picker (`notebooks.context-pages`) spans every
caderno while the LINK picker does not: reading a page once is not the same
promise as addressing it forever.

**Both live behind ONE [+] in the composer** — the same box
`x-flowspec.composer` and `x-submissions.composer` are: a rounded frame holding
this turn's context pills, the textarea and a toolbar. All three carry the same
`[+]`, a round ghost button rather than a paper-clip, since it is one gesture and
should not be three buttons. The menu has exactly two items because there are
exactly two doors; a long paste is the third and needs no item, since it becomes
a document on its own.

Four things there are load-bearing:

- **The pills sit inside the BOX but outside `#docs-chat-message-form`.** Each
  document pill carries its own `<form>` for the remove button, and a nested form
  is dropped by the parser.
- **TONE in that row means which KIND of context a pill is** — neutral for
  material somebody brought, accent for documentation already in the inventory —
  never whether it is checked. Withheld is said by the checkbox plus a dim.
- **An item of the attach menu has to close the menu itself** (`closeAttachMenu()`).
  `toggle.js` closes a popover on an OUTSIDE click and a menu item's click is not
  one, so attaching a link left the menu standing over the chip it had just
  created — the only feedback the attach had worked. The file item only appeared
  to work: it clicks a hidden input that sits in the form rather than the menu, so
  that synthetic click bubbled out and tripped the outside-click listener. CATI's
  link item closes it only on SUCCESS, since a refused url has to keep the field
  on screen to be corrected.
- **Two pieces of copy PICTURE the `[+]` in prose** (`x-submissions.sources`'
  empty state and the flowSpec index hero) and have to move with it. What the box
  does NOT unify is the send button's label: flowSpec's new-chat screen says
  "Gerar flowSpec", so that button stays label-only while the two chat ones carry
  a paper-airplane.

Five rules, and four of them are the module's existing rules seen from a new
screen:

- **Masked.** `SecretText::mask()` — this is the FIFTH surface that hands a
  page's text to somebody, and the only one where the page being read is not the
  page being edited, so a value an editor may not see in caderno A must not
  become quotable into caderno B.
- **Media blocks are STRIPPED, not frozen** (`BlockVault::strip()`, which
  reuses the same PATTERNS). A `[[BLOCK-n]]` marker is an instruction to KEEP a
  block, and these blocks belong to a different page; handing over the raw
  markup is worse still, since the model can copy a `/files/{id}` it is shown —
  and that would half-work, rendering inside the app and breaking on the magic
  link (`PublicDocumentationController::file()` scopes media to the caderno's
  own pages). What it sees is `[imagem]`, deliberately not a `[[…]]` marker, so
  no restore anywhere can resolve it.
- **The page being written is never its own reference.** Its text is already in
  the prompt as "CONTEÚDO ATUAL DA PÁGINA", and a second copy under another
  heading is how a model loses track of which of the two the draft replaces.
  The section heading says out loud what these pages are NOT, for the same
  reason.
- **Capped and budgeted separately** (`max_context_pages`,
  `page_budget_chars`), because an uploaded document and a page compete for the
  same prompt and one runaway page must not push out the PDF somebody attached.
  What does not fit is FLAGGED (`meta.omitted_pages`), never dropped in silence:
  somebody picked it by hand.
- **The system prompt now names the `page:` construct** in the allowed inline
  syntax, and that is not a courtesy — a reply rewrites the WHOLE page, so a
  model told only about `[texto](https://url)` would "fix" an internal link into
  a URL and quietly break it. It is also told never to INVENT a slug or an
  anchor, which is the honest half of the rule: a slug the caderno lacks renders
  as text with no link at all.

### The assistant is never asked to obey a rule it cannot keep

Two vaults sit in front of the Documentation Assistant, for two different
reasons, and the second one exists because of a rule that read as sensible and
deleted people's work.

`App\Support\Documentation\BlockVault` freezes the blocks the model may
neither write nor lose — `<figure>`/`<img>`, `{% file %}`, `{% embed %}`,
`{% diagram %}` — as `[[BLOCK-n]]` markers. The system prompt used to ban that
syntax outright ("não use imagens, `<figure>`, `<img>`, `{% file %}` nem
`{% embed %}`") while also demanding the COMPLETE page back in the draft. On any
page with an image those two rules contradict each other, and the model resolved
it the only way it could: by deleting the figure that was already there, while
answering a request about something else. Reported from the app 2026-08-31.

The ban's intent was right and its wording was not. The model cannot author one
of these blocks — a `/files/{id}` needs a media id only the upload knows, a
`{% diagram %}` a slug from the catalog — but that is an argument for never
showing it the syntax, not for telling it to leave the syntax out of a document
that already contains it.

Four things to keep:

- **PATTERNS order is load-bearing.** A `<figure>` contains an `<img>`, so the
  figure is captured first, and `capture()` walks a copy in which every
  captured block is already a marker — that is what stops the image inside a
  figure being frozen on its own.
- **A dropped block is COUNTED, never re-inserted.** A marker the model deleted
  has no position left to restore it to, and guessing one would rewrite
  somebody's page. `droppedNotice()` appends a PT-BR warning to the
  conversational half instead, and `meta.blocks` audits the turn — removing an
  image is legitimate when it was asked for, so the notice states what is
  missing and leaves the judgement to whoever presses "Aplicar".
- **The audit runs on the RAW DRAFT, before any restore.** A marker is only a
  marker until then; and it must be the draft rather than the whole reply,
  because a model that explains itself ("removi o [[BLOCK-2]]") would otherwise
  be counted as having kept it — which is precisely the turn this guard is for.
- **Three vocabularies, deliberately separate**: `[[LIT-n]]` (LiteralVault,
  opaque literals), `[[SECRET-n]]` (SecretText, protected values) and
  `[[BLOCK-n]]` (here). A shared prefix would let one restore resolve another's
  markers.

### Six ways the Documentation Assistant's prompt could lie about its own contract

The vaults above cover what the model must not WRITE. These are the other half —
places where the prompt asked for something the pipeline then failed to honour,
or forbade something it should have enabled. All six were found by reading the
prompt against the code that consumes it (2026-09-02).

- **The draft block has to be the LAST thing in the reply, and nothing said so.**
  `DocumentationChatService::DRAFT_FENCE_PATTERN` was anchored at the end, so a
  model that signed off with "quer que eu ajuste alguma parte?" after the
  closing fence produced NO match: the whole draft collapsed into the
  conversational half, the person saw raw Markdown in a chat bubble with no
  "Aplicar" button, and nothing anywhere reported a failure. Both halves are
  fixed — the prompt says the block comes last and there is at most one, and the
  pattern now captures trailing prose and joins it to the conversational text,
  so this never again depends on the model obeying.
- **Nothing told it to keep the prose it was not asked to change.** This is the
  BlockVault incident one level up: "devolva a página completa" plus a targeted
  request is how a model rewrites a page it was asked to amend. Images,
  literals and protected values all have a vault; ordinary sentences somebody
  wrote have none, so the guard has to be a rule — MUDANÇA MÍNIMA, stated as
  "'devolva a página completa' é uma exigência de formato, nunca um convite
  para reescrever o que já estava lá."
- **The requirements checklist is keyword matching, and the model was told it
  was fact.** `DocumentationRequirements::contentItems()` is `str_contains` over
  a handful of stems, and its own docblock calls it "best-effort... honest, not
  a quality judgment" — but a page describing contingency without the word
  "contingência" arrived as `[FALTA] Tratamento de erros`, and the model
  dutifully told the author to write what was already there. The items are
  labelled `[checagem por palavra-chave: …]` now, and the prompt says to confirm
  against the content before reporting a gap.
- **The two attribute markers must not share a prefix.** They both began "já no
  cadastro da Solução", and the rule "NUNCA pergunte sobre um item marcado como
  'já no cadastro da Solução'" matched both — so a rule written for a fact the
  model HAD been handed also silenced the case where nothing had been handed at
  all, which is the one worth a sentence ("a Solução não tem Criticidade
  preenchida"). They are `[fato do cadastro da Solução]` and
  `[em branco no cadastro da Solução]` now, and the prompt answers each
  separately.
- **`{% secret %}` was invisible unless the page already had one.** It was
  mentioned only when the current content contained a `[[SECRET-n]]` marker, so
  "documenta esse header: `Authorization: Bearer …`" was written into the draft
  IN THE CLEAR — LiteralVault masks it on the way in and restores the real bytes
  on the way out, and the page saved a live credential with no lock. It is in
  the allowed-syntax list unconditionally now, and named as the ONE construct
  there the model can author from scratch: unlike `{% file %}` or
  `{% diagram %}` it needs no id or slug only the app knows.
- **"Não invente um `page:slug`" forbade without enabling.** With no list of
  slugs, a model that wanted to cross-link could only guess, and a guess renders
  as text with no link at all. The caderno's pages are named in the prompt now
  (`pageCatalog()`), with two limits that matter: **this caderno only**, because
  that is all the construct can resolve, and **all of them or none** — a
  truncated list reads as complete, so the pages past the cut look nonexistent
  and the model invents a slug for one it can see in a context page. Past 200
  pages the section is omitted entirely, which is exactly where an imported
  vendor manual lands. It is a plain `slug`/`title` query and deliberately NOT
  `DocumentationSearchService::linkTargets()`, which would add anchors at the
  price of building the whole search index (~6 s cold) inside a chat turn.

### The Documentation Assistant never sees an opaque literal

A reply from the Especialista em Documentação rewrites the WHOLE page (the
4-backtick draft block), so every literal on it has to survive being copied
character by character by a language model — and long high-entropy strings are
exactly what that copying gets wrong, silently. A 212-character SAP CPI
`Authorization` header came back with its tail rewritten (`…VU2s9` → `…VU2n=`);
asked to fix it, the assistant produced a third variant and stated it had
restored the original. Nothing in the pipeline could tell: to every layer
below, one base64 blob looks exactly like another.

So the model is never given the chance. `App\Support\Documentation\LiteralVault`
replaces each opaque literal with a marker (`[[LIT-1]]`) in everything the
prompt shows — current content, this turn's message, history, inlined context
documents — and puts the real bytes back in the reply, in the conversational
half as well as in the draft. The model can still MOVE a value (that is
copying a nine-character marker) and can still be told which is which (the
legend names each marker's kind, length and first 8 characters — the same
disclosure `SensitiveTextScanner` already makes), but it cannot retype one.

Four things that are easy to undo:

- **The thresholds are measured, not guessed.** A literal is a run of
  `[A-Za-z0-9+/=_.~-]` that is a JWT, 32+ hex characters, or 40+ characters
  with Shannon entropy ≥ 4.5 bits/char AND a longest single-class run ≤ 8.
  Measured 2026-08-30 over the whole dev corpus (207 pages): those rules flag 9
  strings, all genuinely opaque, and none of the ~570 long identifiers the same
  corpus contains — `additionalData_payload_transaction_authorizationCode`
  (H=3.8), `S4hana/depara_fornecedor_QAS500/…` (H=4.1), a table's `-----` rule
  (H=0). The run cap is what does most of the work: a word IS a run of one
  class, so identifiers sit at 8–13 and random tokens at 3–6. Loosen either and
  field names start disappearing behind markers.
- **Mask before the prompt, restore before `extractDraft()`.** One restore over
  the raw reply text covers both halves; the markers contain no backticks, so
  the fence regex is unaffected.
- **The repair pass only fires on a UNIQUE prefix owner.** The model can still
  retype a value it read from a native attachment (a PDF is handed over as-is
  and never passes through `mask()`), so a candidate sharing a vaulted
  literal's first 24 characters at ≥ 90% similarity is replaced by the vaulted
  one. Two tokens for the same service in different environments are base64 of
  nearly the same plaintext and share a long prefix — "closest match" would
  swap PRD for QAS, which is why an ambiguous prefix is left alone rather than
  guessed.
- **Test fixtures are synthetic.** Same shape as the header that exposed this
  (`sb-<uuid>!b<n>|<subaccount>:<uuid>$<secret>`, base64), never a real
  credential — a test suite is not a place to keep one.

`meta.literals` (`frozen`/`repaired`/`unresolved`) audits each turn;
`unresolved` counts markers the model invented, which is the shape of a
prompt regression.
