# MCP — write tools (catalog and cadernos)

Status: **implemented (phases 0–3; phase 0 run on Claude.ai only)** · revision 3 · 2026-11-17 · history in §15, implementation notes in §16

Today `POST /mcp` only reads. This spec adds tools that create and edit:

- **catalog:** solutions, people and companies, plus the links between them
- **documentation:** cadernos, their solution links, and their pages, including
  the page tree ("seções" are pages that have subpages)

Every write goes through the same policies and validation as the app, is
attributed to the person who made it, and leaves a record an admin can read
and revert.

---

## 1. Where we start

| piece | today |
|---|---|
| `App\Mcp\Tool` | the docblock declares every tool READ-ONLY by design |
| `ToolRegistry` | `describe()` hard-codes `readOnlyHint: true`. A tool hidden from a caller is UNKNOWN when called, "so the model never chooses a tool it would be refused" |
| `App\Mcp\Actor` | either a minted `McpToken` (a program, full read) or an OAuth `User` (a person, `canRead()` per module) |
| `AuthenticateMcpRequest` | already calls `Auth::setUser()` for the OAuth half |
| `App\Mcp\OAuth` | ONE scope, `mcp`, described on the consent screen as "Ler o inventário e a documentação publicada" |
| `Mcp\Support\Arguments` | documents that models send `null` meaning "not set", and coerces accordingly |
| `PublishedNotebooks` | the only door to documentation: published cadernos only |
| write logic | inside the controllers (`SolutionController::payload()`/`uniqueSlug()`, `PersonController`, `CompanyController`, `NotebookController`); pages already have `DocumentationPageService` |
| validation | Form Requests that read `$this->route(...)`, so the MCP cannot reuse them as they are |
| page editor | `docs-editor.js` autosaves the WHOLE body (`documentation` only, no version) about 1.2 s after typing stops |
| `SecretText::mask()` | prints each protected value as a marker carrying its STORED id |
| servers | one (`@servers(['web' => 'akop'])`). There is no staging |

The reason given for read-only is still true, but it only applies to tokens:
**a token is not a person**. A write made with one would have no author and
would never pass through a policy. An OAuth connection is a real `User`, so
neither objection applies to it.

## 2. Decisions

1. **Only an OAuth connection the person authorized to write may write.** A
   minted `McpToken` stays read-only. Writing requires the person to be an
   `Editor` of the module, the access token to carry the `mcp:write` scope
   (§3.2), and the policy to allow the record.
2. **A write tool exists only for a connection that can use it.** Without the
   scope, a person sees no write tool at all, which is the registry's existing
   rule. There is no step-up and no new kind of failure (§3.3).
3. **The safety net is a HUMAN, then the write log.** Every write tool is
   annotated as not read-only, so the client asks the person before each call
   unless they chose "always allow". Each guard (§6.3) is designed so that the
   call asking the person shows WHAT will be lost. Past that, the write log
   (§4) is what reverts. Neither the flags nor the rate limit is a safety
   mechanism on its own, and this spec does not pretend otherwise.
4. **A page body is written as WHOLE Markdown, under optimistic concurrency in
   BOTH directions.** An MCP write must carry the `version` it read, and so
   must the browser editor's save (§7). Whichever arrives second against a
   changed page is refused instead of overwriting.
5. **Secrets go back in on save.** The `[[SECRET-…]]` markers the model was
   shown are swapped back by `SecretText::restoreWithIds()`, the same call
   `EditsDocumentation::persistDocumentation()` makes. A plaintext value never
   appears in a response.
6. **`null` means "not sent". Clearing is explicit.** In `update_*`, an absent
   or `null` key keeps the current value, and a field is emptied only when it
   is listed in `clear: [...]`.
7. **A create retried after a timeout returns what the first call made**
   (§6.1). A create whose name matches somebody else's record is refused and
   names it.
8. **A Documentation Editor whose connection may write sees every caderno**,
   published or not. Everybody else keeps seeing only the published ones.
9. **Nothing is deleted.** Unlinking a person from a solution, or replacing a
   caderno's set of solutions, removes a link, not a record, and is in scope.
10. **Slugs are not renamed.** `create_*` accepts an optional slug, and
    `update_*` never changes one.

## 3. Access model

### 3.1 `Actor`

```php
/** Whether this caller may CHANGE `$module`. A token never may. */
public function canWrite(AccessModule $module): bool
{
    return $this->user !== null
        && $this->writeGranted          // the access token carries mcp:write
        && $this->user->canEdit($module);
}
```

`AuthenticateMcpRequest` reads `writeGranted` once, from the access token
(`$user->tokenCan(OAuth::WRITE_SCOPE)`), and passes it to `Actor::fromUser()`.
The Editor level is checked live on every call, so a person demoted after
connecting stops writing on their next request.

### 3.2 The `mcp:write` scope

The consent people gave so far says "Ler". Turning on writes for every
existing connection on deploy day would change what they authorized without
asking, so writing is a scope of its own:

- **The scope:** `OAuth::WRITE_SCOPE = 'mcp:write'`, described on the consent
  screen as "Criar e editar registros do inventário e cadernos em seu nome".
  It is registered in `Passport::tokensCan()`.
- **Advertised up front:** it is listed in both discovery documents'
  `scopes_supported`, and the 401's `WWW-Authenticate` carries
  `scope="mcp mcp:write"`. A client therefore asks for both on its first
  connection, and the consent screen names both.
- **Granted only to an Editor:** `App\Mcp\McpScopeRepository` (bound over
  Passport's `Bridge\ScopeRepository`) strips `mcp:write` in
  `finalizeScopes()` when the account is not an Editor of any module. That
  hook runs when the code is issued AND on every refresh, so a demoted
  Editor's next refresh drops the scope too. The consent view
  (`mcp.authorize`) names the write line only when the request carries the
  scope and the account may hold it, so the screen never offers what the
  account cannot do. A person promoted to Editor later
  reconnects to get it, and `/mcp/connect` says so.
- **Existing connections keep `mcp` only.** Refreshing a token keeps its
  original scopes, so they stay read-only until the person reconnects.
- **The server instructions say it out loud.** A read-only connection held by
  an Editor is told: "Esta conexão é somente leitura. Para editar, reconecte o
  conector e autorize a edição."

> **Open risk, closed by phase 0:** whether Claude.ai, ChatGPT and Gemini
> request every scope in `scopes_supported` or in the 401's `scope`. If one of
> them asks for `mcp` alone, the fallback is a per-person switch, "Permitir
> edição pelo MCP", on `/mcp/connect`. Stored on the user and read into
> `writeGranted`, it is the same decision, made on a screen of ours.

### 3.3 `Writes` — a write is declared, not inferred

A new marker interface, `App\Mcp\Writes`, sits beside `ReachesOutside`:

```php
interface Writes
{
    /** True when calling it twice with the same arguments leaves the same state. */
    public function idempotent(): bool;
}
```

- **Visibility:** `ToolRegistry::all()` keeps a `Writes` tool only when
  `$actor->canWrite($tool->module())`. Hidden means unknown when called,
  exactly as for the read tools. A write tool's `module()` cannot be null, and
  a test asserts that.
- **Annotations:**
  - `readOnlyHint: false` on every write tool. This is what makes a client ask
    the person before calling (§2.3);
  - `destructiveHint: true` on every `update_*`, on `unlink_*`, on
    `place_page` and on `update_notebook` (its `solutions` replaces the set);
  - `idempotentHint` comes from the interface.
- **Policies are checked inside each tool**, through
  `$actor->user->can('update', $model)` / `can('create', Model::class)`. The
  registry decides what the model is shown, and the policy is the wall.
- **`McpServer::instructions()`** stops saying "Todo o acesso é somente
  leitura" unconditionally. For a writing connection it adds: "Antes de
  criar, alterar ou desvincular, confirme com a pessoa. Quando uma ferramenta
  recusar por perda de conteúdo, mostre à pessoa o que seria perdido e só
  repita com a confirmação dela."

### 3.4 Rate limit

`mcp-write` allows 20 writes per minute per person. It is checked inside the
tool like `DigibeeLogTool::PER_MINUTE`, because the HTTP limiter cannot tell a
write from a read. The answer is a tool result the model can read ("Muitas
alterações em pouco tempo; espere um minuto."). **This is etiquette, not
safety:** it keeps a runaway loop from saturating the shared droplet. It does
not keep a bad bulk edit from happening; §4's batch revert is what undoes one.

## 4. The write log

### 4.1 Table `mcp_writes`

| column | |
|---|---|
| `user_id` | FK, constrained |
| `oauth_client_id` | which connector made it (Claude.ai, ChatGPT…), used for grouping |
| `tool` | `update_solution`, … |
| `subject_type`, `subject_id` | morph to the record written |
| `before` / `after` | encrypted JSON: the fields this write changed, as they were and as written (`before` null on create) |
| `reverted_at`, `reverted_by_id` | null until reverted |
| `created_at` | indexed with `user_id` |

- **Only changed fields are stored.** A page's `documentation` counts as one
  field and is stored whole, because that is the only way to revert a
  whole-body write.
- **A relation counts as one field, holding its whole set** (§6). Linking a
  person to a solution logs the SOLUTION, with
  `before/after = {"people": [{"person": "joao-silva", "role": "technical"}, …]}`.
  `update_notebook` with `solutions` logs the NOTEBOOK with
  `{"solutions": ["sap-ecc", …]}`. Reverting re-syncs the set, which is one
  rule for both pivots.
- **Encrypted at rest**, because a page body holds its secrets in clear. Laravel's
  `APP_PREVIOUS_KEYS` keeps old rows readable across a key rotation. The deploy
  procedure for rotating the key has to set it, and §13 adds that line.
- **Kept 180 days** (`Prunable`, in the schedule). A wrong edit is noticed in
  days or weeks. Half a year covers a quarter's review cycle with margin, and
  the table does not grow without bound.

### 4.2 Revert

`App\Actions\Mcp\RevertMcpWrite` handles one row, and `RevertMcpWrites`
handles a SELECTION:

- **A row reverts only if nothing changed since.** The record's current
  values must equal the row's `after`. Otherwise it is skipped and reported
  ("alterado depois por …"), because blindly restoring `before` would undo
  somebody else's later edit.
- **A selection reverts newest first**, so a field written twice ends at its
  oldest `before`. It answers with how many rows were reverted and which were
  skipped and why.
- **A create is reverted by deleting the record**, which is the one delete
  this feature can cause. It is admin-only, like every delete, and it is
  refused once the record has ANY link or page. Telling apart what the create
  made from what came later is not worth the code. An admin who still wants
  the record gone deletes it on its own screen.

### 4.3 Screen (phase 1, not later)

"Alterações via MCP" is admin-only and appears under the "Tokens MCP" rail
entry:

- a list filtered by person, connector and period;
- the diff of each row, with page bodies shown through `SecretText::mask()`;
- **"Reverter"** on a row, and **"Reverter seleção"** on a filtered list. The
  latter is how a bad 200-row edit is undone in one action.

It ships with the first write tool. A log nobody can read is not an audit.

## 5. Refactor: one implementation per write

The write logic moves out of the controllers so the screen and the MCP cannot
answer differently. Only what has logic moves: slug generation, defaults,
secret restore, the version check and the tree rules. A plain
`$model->update($data)` stays where it is.

| extracted | used by | carries |
|---|---|---|
| `Catalog\SolutionRules::for(?Solution)`, `PersonRules`, `CompanyRules` | the Store/Update Form Requests, the tools | the rule arrays, unchanged |
| `Catalog\SaveSolution`, `SavePerson`, `SaveCompany` | the controllers' `store/update`, the tools | `payload()`, the defaults, `uniqueSlug()` |
| `Documentation\SavePageContent` | `EditsDocumentation::persistDocumentation`, the page tools | version check (§7), `SecretText::restoreWithIds()`, `update()` |
| `DocumentationPageService::place()` (new) | `place_page` | §6.3 |

The Form Requests keep `authorize()` and delegate `rules()`:

```php
public function rules(): array
{
    return SolutionRules::for($this->route('solution'));
}
```

The refactor goes in its own commit, before any MCP code. **The existing
feature tests passing unchanged** are the proof that it moved code and not
behaviour.

A validation failure is a tool result (`isError`) listing every field's
message in the app's own Portuguese, because the model can fix it.
`InvalidToolArguments` stays reserved for a call that ignores the schema.

## 6. The tools

### 6.1 Arguments

- **References are slugs, never ids**: `vendor: "totvs"`, `company:
  "leo-madeiras"`, `solutions: ["sap-ecc"]`. A slug that matches nothing is
  refused, never dropped.
- **Attribute and enum values go through `Vocabulary`.** The schema lists them
  as an `enum` with their labels, and both the value and the label are
  accepted.
- **`update_*` is a PATCH:**
  - absent or `null`: keep the current value;
  - `clear: ["cloud"]`: empty those fields. A required field in `clear` is
    refused by validation;
  - the patch is merged onto the current record and the WHOLE result is
    validated.
- **Creates and duplicates.** The name is compared with `whereFolded`:
  - **Same name, created by THIS person through the MCP in the last 15
    minutes** (looked up in `mcp_writes`): the call answers SUCCESS with that
    record and a note: "Já criado por esta conexão às 14:32; nada novo foi
    criado." This is the retry after a timeout. The first call worked, and
    the second must neither duplicate it nor teach the model to force one.
    When the retry carries fields that differ from what the first call saved,
    the note lists them ("não aplicados: description, criticality") and
    points at `update_*`. A model that read "sucesso" must not believe its
    correction landed.
  - **Same name otherwise:** refused, naming the existing record's slug. The
    refusal says to open that one, or to ask the person before passing
    `allow_duplicate: true`.
  - **Two simultaneous creates** with the same folded name are serialized by a
    `Cache::lock` keyed on the model and the folded name, so the second one
    sees the first.
- **The answer is the record**, in exactly the shape of the matching `get_*`
  tool.

### 6.2 Catalog (`AccessModule::Catalog`)

| tool | arguments | notes |
|---|---|---|
| `create_solution` | `name`*, `category`*, `status`*, `slug`, `description`, `vendor`, `directorate`, `support_type`, `environment`, `cloud`, `contract_status`, `criticality`, `support_operation_note`, `allow_duplicate` | |
| `update_solution` | `slug`*, any field above except `slug`, `clear` | patch |
| `link_person_to_solution` | `solution`*, `person`*, `role`* | creates the link or changes its role. Idempotent |
| `unlink_person_from_solution` | `solution`*, `person`* | removes the link only |
| `create_person` | `name`*, `slug`, `company`, `job_title`, `email`, `phone`, `notes`, `allow_duplicate` | |
| `update_person` | `slug`*, any field above except `slug`, `clear` | patch |
| `create_company` | `name`*, `kind`*, `slug`, `website`, `notes`, `allow_duplicate` | |
| `update_company` | `slug`*, any field above except `slug`, `clear` | patch |

### 6.3 Documentation (`AccessModule::Documentation`)

| tool | arguments | notes |
|---|---|---|
| `create_notebook` | `name`*, `solutions`, `allow_duplicate` | creates no page |
| `update_notebook` | `slug`*, `name`, `solutions` | `solutions` REPLACES the set, and the description says "envie a lista completa". Absent or null leaves it, `[]` empties it |
| `create_page` | `notebook`*, `title`*, `parent`, `position`, `content`, `allow_duplicate` | duplicate means the same title under the same parent |
| `update_page` | `notebook`*, `page`*, `version`*, `title`, `content`, `remove_secrets`, `confirm_removals` | see below |
| `place_page` | `notebook`*, `page`*, `parent`, `position` | one call for any rearrangement |

**`version`** is `textHash()`, the md5 of the text already computed for the
search index. The title is NOT part of it (see §16). `get_documentation_page` returns the
version, and so does every page write and every save of the browser editor.
A mismatch is refused with: "A página mudou desde a sua leitura. Leia de novo
com get_documentation_page e reaplique a alteração." It is required even for a
title-only change, because one rule is easier for a model to follow than two,
even though a title change does not move it.

**Content guards.** They run after the secrets are restored and before
anything is saved. Each one refuses with the exact list of what would be lost,
and the confirmation argument has to REPEAT that list, never a boolean. That
way the call the client shows the person, before approving, spells out the
loss (§2.3):

| guard | refuses when | to confirm, the call passes |
|---|---|---|
| secrets | a `{% secret %}` id stored on the page has no marker in the new body | `remove_secrets: ["[[SECRET-k3j9x2ab]]"]`, each marker removed |
| references | a `{% diagram %}`, `{% file %}` or `/files/{id}` present before is gone | `confirm_removals: ["diagram:fluxo-pedidos", "file:812"]` |
| shrink | the new body is under 60% of the old one's length, and the old one is over 2,000 characters | `confirm_removals: ["shrink:30%"]`, the share KEPT, rounded down to a multiple of 10 |

A confirmation that does not match the refusal (a missing item) is refused
again, so the model cannot pre-confirm a loss it has not been told about. The
shrink item is a rounded BAND rather than the exact lengths: the model
rewrites the body on the second call, a few characters move, and exact
lengths would turn the confirmation into a loop. A confirmation for the actual
band, or the one just below it, passes — never a lower one, so `shrink:0%` sent
up front cannot pre-confirm every loss.

The shrink thresholds are a starting point. Phase 2 measures them against
real editor saves in the corpus, and if legitimate saves cross the line often,
the threshold is wrong and changes before the tool ships.

**`place_page`** puts a page under `parent` (a page slug of the same caderno,
or absent for the root) at `position` (0-based, clamped to the end).
`DocumentationPageService::place()` refuses a page going under itself or its
own subtree, and refuses when the deepest descendant would exceed
`DocumentationPage::MAX_DEPTH` (5). Both refusals use the app's own sentences.

### 6.4 Descriptions

A write tool's description says what it changes, that the person's name goes
on it, and which reading tool to call first. It is kept under ~600
characters: write tools are listed only on a writing connection, but there
they cost context in every conversation, including those that only read.

## 7. The browser editor gets the version too

Without this, the main use case undoes itself. A person reads a page, asks
the chat to improve a section, the MCP writes, and the person's next
keystroke autosaves the old body over it.

- **Where the version comes from:** the edit view renders the page's
  `version` on the editor's holder (`data-ak-docs-version`).
- **What the save sends and gets back:** `docs-editor.js` sends it with every
  save as `base_version`, and `SavePageContent` compares it with the stored
  page. A matching save answers with the new version, and the editor adopts it
  (the server restores secrets, so the client cannot compute it itself).
- **A mismatch answers 409** with "Esta página foi alterada em outro lugar
  (outra aba, outra pessoa ou o MCP) desde que você a abriu."
  - The editor stops autosaving, shows "Conflito" as its status, and keeps the
    person's text on screen so nothing they typed is lost.
  - It offers "Recarregar página", which discards their unsaved changes after
    a confirm.
  - Merging is out of scope.
- **The Assiste IA's "Aplicar"** goes through the editor's own save, so it
  needs nothing.
- **Behaviour change for people:** two tabs on the same page now conflict
  instead of the last save silently winning. That is a fix, but it is visible,
  so it ships in phase 2 with a line in the changelog.
- **`SaveDocumentationRequest`** gains `base_version`. It is nullable only
  because a tab opened before the deploy has none, and such a tab is gone
  within a day. **It becomes required on 2026-12-15**; a test pinned to that
  date fails after it as the reminder.

## 8. Reading for an Editor

`PublishedNotebooks` becomes `ReadableNotebooks`, one door taking the `Actor`:

```php
public function query(Actor $actor): Builder
{
    return Notebook::query()
        ->unless($actor->canWrite(AccessModule::Documentation), fn ($q) => $q->published());
}
```

- **Every documentation read goes through it:** `list_notebooks`,
  `get_notebook`, `get_documentation_page`, `search_documentation`, and
  `get_solution`'s caderno list.
- **An unpublished caderno is marked** `"published": false`, and the
  instructions tell the model never to cite such a page as "da base
  publicada".
- **The not-found message stays one sentence for every caller.** For a
  read-only caller "absent" and "unpublished" must stay indistinguishable,
  and a second sentence for Editors is one more branch to get wrong.
- **Search:** `searchAll()` already takes a `Collection` of cadernos. The
  Editor's wider set is a different, colder global index than the one
  `documentation:search-warm` keeps hot. `SearchDocumentation`'s time budget
  already answers partially and names what it did not reach.

## 9. Side effects (verified in the code)

- **Search index:** nothing to do. Both of its cache layers are keyed by
  content hashes, so a saved page produces new keys by itself.
- **Render cache:** the same holds. `GitbookRenderer`'s `Cache::remember` is
  keyed off the lines it renders.
- **Authorship:** `DocumentationPage::booted()` fills
  `created_by_id`/`updated_by_id` from `auth()`, which holds the OAuth user.
- **Server logs:** each write logs one line
  (`Log::info('MCP write', [tool, user_id, subject, mcp_write_id])`), so an
  incident traces from the server logs to the `mcp_writes` row.

## 10. Out of scope (v1)

- deleting anything (except the revert of a create, §4.2), and moving a page
  to another caderno
- publishing or unpublishing, the secret code, the magic link
- attachments and any other upload besides a record's picture (logos and a
  person's photo shipped on 2026-11-17 — see `App\Mcp\Support\ImageArgument`)
- a person's additional contacts and linking a person to an account
- diagrams, scenes and the context documents of the Assiste IA
- partial edits of a page's body (`old_text`/`new_text`). Reconsider after
  phase 2 if the guards trip often on legitimate edits
- merging an editor conflict (§7)
- writes through a minted token

## 11. Tests

- **Scope and visibility:**
  - a token never sees a `Writes` tool, and neither does a Reader;
  - an Editor WITHOUT `mcp:write` does not see them either, and calling one
    answers "não existe";
  - an Editor with the scope writes;
  - authorization strips `mcp:write` from a non-Editor;
  - the instructions tell a read-only Editor how to enable writing;
  - derive counts from `ToolRegistry::TOOLS`.
- **Annotations:** as in §3.3.
- **Patch:** an absent key and a `null` key both keep the value. `clear`
  empties a field. `clear` on a required field is refused.
- **Creates:**
  - the same create repeated by the same person within 15 minutes returns the
    first record and makes nothing;
  - a folded-equal name from someone else is refused, naming the slug;
  - `allow_duplicate` passes.
- **Rules parity:** one dataset sent through the controller and through the
  tool is refused by both, with the same messages.
- **Policies:** a policy refusing `update` makes the tool refuse.
- **Pages:**
  - a stale `version` is refused;
  - a masked round trip keeps every secret byte for byte;
  - each guard refuses with its list;
  - a confirmation that repeats the list passes, and one that does not match
    it is refused;
  - authorship holds the OAuth user.
- **Editor (§7):**
  - a save with a stale `base_version` answers 409 and changes nothing;
  - a save answers the new version;
  - a save without `base_version` still works during the transition.
- **`place_page`:** moving under its own subtree and exceeding the depth cap
  are both refused, and moving to position 0 of another parent works.
- **Write log:**
  - every write leaves one row with exactly the changed fields;
  - a link logs the whole set on the solution;
  - revert restores `before`, and a revert after a later edit is skipped and
    reported;
  - a selection reverts newest first;
  - reverting a create deletes the record, and is refused once something else
    links to it;
  - bodies are encrypted;
  - pruning drops rows past 180 days.
- **Rate limit:** the 21st write in a minute is a readable tool failure.
- **Reading:** an Editor with the scope sees an unpublished caderno with
  `published: false`. Everybody else gets the single not-found sentence.
- **Refactor:** the existing feature tests pass unchanged.

## 12. Delivery

0. **Verify the clients.** There is no staging, so run the app locally behind
   a tunnel (`cloudflared tunnel --url http://localhost:8000`) with
   `mcp:write` advertised. Connect Claude.ai, ChatGPT and Gemini, and record:
   - which scopes each one requests;
   - whether each one asks the person before a non-read-only tool.

   The results decide between the scope and the `/mcp/connect` switch
   (§3.2). They also decide whether §2.3's human check holds for each client;
   for a client where it does not, the instructions alone are the check, and
   §14 records it. No production code depends on this step.
1. **Infrastructure + catalog:**
   - the refactor commit;
   - `Writes`, `Actor::canWrite()`, the scope and the authorization filter;
   - the registry, annotations and instructions;
   - `mcp-write`;
   - `mcp_writes`, the revert actions and the "Alterações via MCP" screen;
   - the 8 catalog tools and their tests.
2. **Documentation:**
   - calibrate the shrink guard on the corpus;
   - `version` on `get_documentation_page` and in the browser editor (§7);
   - `ReadableNotebooks`, `SavePageContent`, `place()`;
   - the 5 documentation tools and their tests.
3. **Docs (§13)**, written alongside each phase rather than after.

## 13. Docs to update with the code

- `.claude/rules/mcp-server.md`: "read-only" becomes "read for everybody,
  write for an Editor who authorized `mcp:write`", with §2's reasons. Add the
  new classes to its `paths:`.
- `.claude/rules/cadernos-notebooks.md`: the editor's `base_version` and the
  409 (§7).
- `app/Mcp/Tool.php` and `app/Mcp/OAuth.php` docblocks (one scope is no
  longer "a label").
- The deploy notes: rotating `APP_KEY` must keep the old key in
  `APP_PREVIOUS_KEYS` (§4.1).
- AGENTS.md's opening paragraph ("lets a chat client read all of it") and
  `README.md`.
- `/mcp/connect`: what an Editor's connection can do, and how to enable it.

## 14. Open questions

- **Phase 0 results.** Answered for Claude.ai (§16). ChatGPT and Gemini are
  still untested.
- **The shrink threshold has NOT been calibrated yet.** §12 asked for it
  before phase 2 shipped; it shipped with the starting values (60%, 2,000
  characters) because the development database holds no corpus. The tool to
  do it shipped after (§17): `mcp:calibrate-shrink-guard` replays the guard
  (`App\Mcp\Support\ShrinkGuard`, the one place its numbers live) over the
  Assiste IA's history, where every assistant draft is a model's whole-page
  rewrite and `applied_at` says whether a person accepted it. An applied draft
  the guard would refuse is a false positive. Run it in production and set the
  numbers from its output.
- **Whether `old_text`/`new_text` returns**, if the guards trip often on
  legitimate edits.
- **Making `base_version` required**, once no tab opened before 2026-11-17 can
  still be saving.

## 15. Revision history

- **r1** (2026-11-17): first draft.
- **r2**, after the first review:
  - `null` stops clearing, and `clear` is added;
  - `version` on MCP page writes, plus the content guards;
  - the `mcp:write` scope and the write log;
  - `place_page` replaces `move_page`;
  - side effects verified in the code.
- **r3**, after the second review:
  - **confirmations repeat what is lost** instead of being booleans, and the
    human check through `readOnlyHint` is named as the real mechanism;
  - **a retried create returns the first record**;
  - **the browser editor carries the version too** (§7);
  - **no step-up:** write tools are hidden without the scope, which is the
    registry's existing rule;
  - **the rate limit is called etiquette**, and batch revert is added;
  - **the log's screen ships in phase 1**;
  - **pivots are logged as a set on the owning record**;
  - **assumptions checked:** `mask()` keeps the stored ids, and there is no
    staging server, so phase 0 uses a tunnel;
  - **key rotation and the 180-day retention are justified.**

## 16. Implementation notes (2026-11-17)

What the code does where it differs from, or adds to, the sections above.

- **Phase 0, Claude.ai (2026-11-17): passed.** After the connector was removed
  and added again, Claude.ai requested both scopes on its own (the consent
  screen showed the write block; the access token carries
  `["mcp","mcp:write"]`), listed all 29 tools, and every write went through
  the client's approval prompt ("Requer aprovação": Negar / Sempre permitir /
  Permitir uma vez). The model also asked in the conversation first, as the
  server instructions tell it to. The `/mcp/connect` switch fallback is
  therefore not needed for Claude.ai and was not built. ChatGPT and Gemini are
  untested. One caveat to tell users: "Sempre permitir" takes the person out
  of the loop for that tool, leaving the content guards and the write log as
  the protection.
- **`version` is the text's md5 only (§6.3 first said "with the title").** The
  editor renames its own page from the rail; a version that moved with the
  title made that tab's next autosave a conflict with itself. A title race is
  last-writer-wins, which costs a word, not a paragraph.
- **The editor's 409** shows "Conflito — recarregue a página" as its status and
  a Toast, stops autosaving, and leaves the text on screen. There is no
  "Recarregar" button in it (§7 offered one): the browser's own reload is the
  action, and a button that discards typed text deserves its own design pass.
- **`base_version` stays nullable** (§7 said "required on 2026-12-15"). A rule
  that flips itself on a date would also fail every existing test that saves a
  page without it on that date; making it required is a follow-up, by hand.
- **The retry of a create is found in the person's own write log**, not as "the
  first record with that name", which may be somebody else's (phase-1 review).
  A confirmed duplicate retried after a timeout is still one duplicate.
- **The create lock is taken BEFORE the transaction** and released after it
  (§6.1 said release on commit). On the `database` cache store production
  uses, a lock row written inside the transaction poisons it on Postgres when a
  competing call collides.
- **Reverts are stricter than §4.2.** A revert also re-validates the old value
  with the app's rules (a deleted vendor, a retired attribute value) and skips
  instead of failing. A create is deleted only if `updated_at` did not move
  since and nothing points at it (including committee submissions, approved
  topologies, logos, attached files). A bulk revert catches and reports a row
  that fails unexpectedly instead of aborting halfway.
- **A link keeps `is_primary`** in the logged set, so reverting an unlink
  restores the primary owner as primary.
- **`clear` refuses `support_type` and `contract_status`**: NOT NULL columns the
  save refills with a default, so "cleared" would silently mean "unchanged".
- **`mcp_writes.user_id` is `nullOnDelete`**: removing an account keeps the
  record of what it changed.
- **`search_documentation` without a caderno**, for a writing connection,
  appends the unpublished cadernos to `/docs`'s own list (§7), so the published
  half keeps the order the deploy warms.
- **`get_solution`** gains `unpublished_notebooks` for a writing connection,
  beside `published_notebooks`, rather than mixing both in one list. A
  diagram's `caderno` still names only a published one.
- **Phase-2 review fixes**: a placement revert re-runs the cycle and depth
  checks and skips when the tree changed shape; a diagram citation counts only
  alone on its line (as the renderer reads it), and `{% file src="…" %}` with
  any source is tracked; a numbered legacy marker keeps its value; search
  results of an unpublished caderno link to the editor; a created caderno that
  gained Assiste IA documents or conversations is not deleted by a revert.

## 17. Delivery log

| date | merge | what shipped |
|---|---|---|
| 2026-11-17 | `7f54b3f` (`feat/mcp-write-tools`) | phases 1–3: the 13 write tools, `mcp:write` and its consent, the write log and "Alterações via MCP", `ReadableNotebooks`, the page `version` in both the MCP and the browser editor, the shared catalog rules and actions, the docs in §13. 58 new tests; the suite's 11 failures are the ones that failed before the change (an order-dependent test helper, Digibee tests reading the local `.env`) |
| 2026-11-17 | `1861403` (`fix/mcp-writes-filters`) | the write log's filters laid out in a row; phase 0's Claude.ai result recorded here |
| 2026-11-17 | `feat/shrink-guard-calibration` | `mcp:calibrate-shrink-guard` (read-only) and `ShrinkGuard`, the shrink rule extracted so the tool and the calibration run the same arithmetic. The thresholds are unchanged until its production output is read |

The first deploy of `7f54b3f` shipped the previous commit: the merge had not
been pushed, and Envoy deploys `origin/main`. Pushing `main` is part of the
process now; the deploy itself stays with the person who runs it.

Two independent reviews ran, one after phase 1 and one after phase 2; every
finding they confirmed was fixed before the merge and is covered by a test
(listed in §16).

**Still open** (§14): ChatGPT and Gemini, the shrink calibration,
`base_version` required. **Out of scope** (§10): deleting, moving a page to
another caderno, publishing, uploads (except a record's picture), a person's contacts, diagrams and
scenes, partial body edits.

