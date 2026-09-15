---
paths:
  - "app/Support/Digibee/**"
  - "app/Services/Digibee/**"
  - "app/Actions/Digibee/**"
  - "app/Console/Commands/*Digibee*.php"
  - "app/Console/Commands/*Pipeline*.php"
  - "database/data/digibee_*.json"
---

### The Digibee knowledge base — two corpora, and neither one substitutes for the other

The flowSpec generator answers with a document that has to paste into the
Digibee canvas, and for a long time the only thing it knew about a connector was
its NAME: `digibee_component_catalog.json` is 34 names and 15 step types, 1.3 KB
in total. Rule 9 of the system prompt said "use only these" while nothing in the
prompt described a single parameter of any of them, and
`DigibeeFlowspecValidator` checks `params` in exactly two places — a track
branch's `onProcess`/`onException`, and the Object Store upsert triple. **Every
other connector's params are unchecked**, so an invented shape validates clean,
is persisted, and fails when a person pastes it into the canvas: the one failure
the correction loop cannot see.

Two sources close that, and they answer different questions:

- **The platform docs** (`docs.digibee.com`) say what a parameter MEANS — type,
  default, allowed values, the condition that makes it appear.
- **Our own pipelines** (`digibeectl`) say what it is CALLED. Measured over the
  real export: REST V2's documented **Verb** is written `operation` in all 129
  real steps, and **Send A File** is `sendBinaryFile`. No published page prints
  a JSON key, because the docs describe the SCREEN.

So a connector card and its tenant-usage block are injected as a PAIR
(`FlowspecPromptBuilder::connectorReferenceSection()` +
`tenantVocabularySection()`), for the connectors the turn is actually about
(`FlowspecContextResolver::connectorsInPlay()` — what a pasted pipeline uses,
what the request names, then what the chosen examples use). Neither half alone
is enough, and merging them into one artifact would lose which half is a vendor
statement and which is an observation about us.

**Files and a name lookup, deliberately not RAG.** The retrieval question here
is a SYMBOL — "what does `object-store-connector` take" — and the symbol is
known exactly on both sides. An approximate index would be strictly worse at the
only question being asked, and it would blur exactly the pairs that matter
(`jslt`/`jolt`, `soap-v2`/`soap-v3`). It would also cost the property the
generator is built on: `digibee_component_catalog.json` is a static file "de
propósito: é o que faz a geração ser reprodutível e testável", and "was the
right document retrieved?" is not a testable question about a vector index.
`pgvector` is also not installed on the dev Postgres, and production is a
droplet shared with two other apps. What genuinely IS fuzzy ("how do I do X in
Digibee") already has a hosted answer: GitBook serves
`GET <page>.md?ask=<question>&goal=<goal>` over this same corpus, always
current — there is nothing there worth re-implementing with embeddings.

#### What is committed, and what is not

| | lives in | in git |
|---|---|---|
| the docs mirror | `storage/app/private/digibee-docs/` | no — re-syncable |
| the pipeline export | `storage/app/private/digibee-pipelines/` | no — internal hostnames and IPs |
| `digibee_connector_docs.json` (connector → page) | `database/data/` | **yes** — curated |
| `digibee_connector_cards.json` (distilled params) | `database/data/` | **yes** — derived |
| `digibee_tenant_vocabulary.json` (real keys, globals, accounts) | `database/data/` | **yes** — derived and scrubbed |

The derived artifacts are committed for the same reason the catalog is: a
generated flowSpec has to be reproducible from a checkout, and a change to what
the model is taught has to show up in a diff somebody reads. **Their paths are
config-driven** (`services.digibee.cards_path` / `vocabulary_path`) purely so a
test that rebuilds them from a two-page fixture does not leave the repo holding
one card instead of thirty-four — which is exactly what happened the first time.

#### `digibeectl` never runs on the server

The credential is the interactive one a developer logs in with, and its scope
reaches creating and DELETING deployments in production. Digibee publishes no
read-only alternative: the "Digibee APIs" product is in beta and its key covers
the Pipeline Metrics API only (checked against their platform-administration
docs, 2026-09-02). So `digibee:pipelines:pull` is deliberately absent from
`routes/console.php` while `digibee:docs:sync` and `digibee:docs:import` are
scheduled there — the docs half is public HTTP with no credential at all. The
periodic pipeline pull belongs on a workstation or ops box that publishes the
derived JSON: **the artifact travels, the credential does not.**

**That rule is about the LOGIN credential, and since 2026-09-11 there is a
second kind.** A digibeectl TOKEN (Administration → Digibeectl) carries an
explicit permission list in its own JWT, environment scoping included, so the
one the lifecycle agent uses holds `PIPELINE:READ`, `PIPELINE:CREATE`,
`CONFIGURATION:READ`, `DEPLOYMENT:READ` and `DEPLOYMENT:CREATE{ENV=TEST}` — it
cannot delete anything and cannot reach production. That is a different risk
object from the interactive login, and it is what makes the APLA design and
deploy calls defensible from the server (`config/services.php` § Platform API
documents the reversal). Two halves of the old rule survive unchanged: the
BINARY still never runs on the server — everything here is plain HTTP — and
`digibee:pipelines:pull` still needs the broad interactive credential, so it
stays off the server too. A scoped token in `.env` must be an ENCRYPTED
environment variable: it is valid for ten years, so nothing expires to bound a
leak.

#### The redaction line: names and expressions are vocabulary, addresses are not

`App\Support\Digibee\ParamRedactor`. Double Braces expressions survive verbatim,
including `{{ global.url-base-viasoft }}` and every `accountLabel` — those are
NAMES, they are the most instructive thing in the corpus, and an invented
`global.*` passes validation and dies at runtime. Booleans, numbers and short
bare words (`POST`, `UPDATE`, `UTF-8`) survive as the allowed-value sets the
cards describe in prose. Anything that addresses a machine becomes `<endpoint>`
— our export contains literals like `10.158.1.37`, and a prompt is the wrong
place for the internal network's shape.

A pipeline `CredentialScrubber` flags is **skipped whole**, never redacted
around: a pipeline that would fail the generator's own validation is not a
pipeline to teach from. Eight of 201 are skipped today, which is itself worth
knowing.

**Auditing that list is what found the scrubber's false positives**, and they
mattered well beyond this corpus — the same class runs inside
`DigibeeFlowspecValidator`, so a GENERATED pipeline tripping one failed every
correction attempt and had its document discarded by
`FlowspecGenerationService`. Two rules were too loose:

- **A key was sensitive if a sensitive word appeared anywhere in it**, so
  `lastPasswordUpdate` (a date), `authorizationDate` (a date) and `tokenUrl` (an
  endpoint) all read as credentials. `DESCRIPTIVE_KEY_SUFFIXES` excuses a key
  that DESCRIBES a credential — one ending in `date`, `at`, `url`, `update`, `id`
  and friends — while `password` and `apiKey` still land, since neither ends in
  one.
- **Any non-`{{` string counted as a value**, so a JOLT spec's mappings did too:
  the values in a `transformSpec` are the NAMES of the fields being mapped
  (`payment.authorizationToken`, `=split('T',@(1,orderDate))`). `isLiteral()`
  now also excuses a JOLT operation, a date, and a bare field path — a path only
  when it carries a `.` or `[`, so a 32-character API key with no punctuation is
  still a literal.

What it does NOT try to settle is a long `eyJ…` blob sitting in mock data: a
VTEX order `handle` is base64 JSON and indistinguishable from a JWT. Flagging it
costs that pipeline its place in the teaching corpus, which is the cheaper half
to lose.

**An expression does not launder a literal address sitting beside it.** "Contains
`{{`" was checked first and answered for the whole string, so
`https://leomadeiras.freshservice.com/api/v2/attachments/{{ message.id }}`
survived intact on the first real pull. The scheme and host are stripped now and
the path and the expression stay, which keeps the composition legible.

One accepted residual remains: a URL composed from a GLOBAL keeps its path
(`{{ global.url-base-promob }}/Promob.Integration/api/...`). There the host is
already abstracted behind the global, the path teaches the composition pattern,
and treating names as vocabulary was the explicit call.

#### Traps already paid for

- **`\R` is a BYTE class outside UTF mode, and `0x85` is the third byte of `✅`.**
  Digibee prints ✅ in the "Supports DB" column of every parameter table, so
  `preg_split('/\R/')` tore each table apart at its first checkmark and only the
  fragment still holding `<table` was ever parsed: REST V2 produced ONE
  parameter instead of twenty, silently. Anything walking this corpus line by
  line spells the break out (`ConnectorCardBuilder::LINE_BREAK`).
- **`llms-full.txt` is not the shortcut it looks like.** 702 KB against the
  corpus's ~4.9 MB, about 100 pages, and it does NOT contain the connector
  parameter tables — the one thing this whole feature is for. The per-page `.md`
  fetch driven by `llms.txt` (581 pages, ~2 min) is the real source.
- **A PARTIAL sync must MERGE the manifest.** `--section`/`--limit` used to
  write only their own subset, which left a 581-page corpus indexed as one page
  and every connector card reporting its page as missing. A FULL run still
  replaces it, because a page Digibee retired has to stop being listed.
- **A connector page writes its parameters three different ways** — an HTML
  `<table>`, a Markdown pipe table, and a bullet list of `* **Name:** …`. A
  builder that understood only the first produced no card at all for For Each,
  JSON Generator, File Writer and JWT, four of the most used components in our
  own pipelines. The bullet fallback is last-resort and needs three bullets, so
  a two-item list of subpipelines is not mistaken for a reference.
- **A card with no parameters is kept AND reported.** Block Execution genuinely
  takes none ("doesn't have any specific configuration to function"), and a
  parsing regression looks identical from inside the builder — so it goes in the
  report every time rather than being decided silently either way. With no
  groups, `ConnectorCard::toPrompt()` prints the summary and claims nothing.
- **The pipeline export PRUNES; the docs mirror does not.** A deleted or
  renamed pipeline is simply never overwritten again, and
  `IndexPipelineVocabulary` walks the directory with no manifest to filter by —
  so it keeps teaching from a pipeline that stopped existing (two files from a
  June snapshot were still being indexed in September). The docs sync can afford
  to leave a retired page on disk because the manifest every reader iterates
  stops listing it; there is no manifest here, so the file has to go. Pruning
  runs **only after a clean pull**: a pipeline this run could not reach is
  indistinguishable from one that was deleted.
- **Which connectors get a card is ranked, and the ranking is the feature.**
  Three signals, most certain first: a pipeline the user pasted, the request
  naming a connector (by its JSON name OR by its CARD TITLE — nobody types
  `object-store-connector`, they write "guarda no Object Store"), then the
  connectors of the chosen corpus examples. Those last ones are sorted **rarest
  first**, by how often our own pipelines use them: an example is a whole
  pipeline, so it drags in the plumbing every pipeline has, and in step order
  `log-connector` and `throw-error-connector` took the card slots that "ler um
  arquivo do SFTP e gravar no BigQuery" needed for SFTP and BigQuery. How often
  we use a connector is exactly how little it distinguishes one request from
  another.
- **The context meter has to count this.** `FlowspecContextBudget` gained a
  `Referência Digibee` line assuming the WORST case (~13.5k tokens), exactly
  like `worstCaseCorpusTokens()` does for the tag-selected examples. This is not
  the "nothing enters a prompt that nobody attached" rule being bent — no
  documentation of ours enters here — but a meter that does not cover what the
  prompt carries is the failure that rule exists to prevent.

#### The caderno is a second consumer, never the storage

`digibee:docs:import` publishes the corpus as a caderno linked to the "Digibee
(iPaaS)" Solution, so the manual is searchable and attachable like every other
body of documentation. It reads the same files the cards do and neither reads
the other, so a broken import cannot take the flowSpec reference down with it.

- **The tree comes from the URL path**, the only hierarchy Digibee publishes. 49
  of those levels have no page of their own and become empty section pages; 33
  pages sit deeper than `DocumentationPage::MAX_DEPTH` and are collapsed onto
  the deepest ancestor that fits, carrying the skipped ancestry in their title —
  the same rule as `ImportGitbookSpace`.
- **A page is matched by title WITHIN ITS PARENT.** 17 titles repeat across the
  corpus (every Release Notes month is "August") and none collide under the same
  parent, so the pair is unique and a re-import updates 630 pages in place.
- **Figures are dropped, not rehosted.** The images are `/files/{id}` references
  that GitBook does not serve at that path (verified: 404). The real bytes come
  from a CDN URL only the rendered HTML carries, so rehosting would mean
  fetching all 581 pages a second time as HTML — for a copy whose job is reading
  and searching. A broken image on every other page is the worse of the two, and
  every page opens with a hint linking the original.
