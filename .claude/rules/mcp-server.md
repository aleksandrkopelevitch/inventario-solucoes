---
paths:
  - "app/Mcp/**"
  - "app/Models/McpToken.php"
  - "app/Policies/McpTokenPolicy.php"
  - "app/Http/Controllers/McpController.php"
  - "app/Http/Controllers/McpTokenController.php"
  - "app/Http/Middleware/AuthenticateMcpToken.php"
  - "app/Http/Requests/StoreMcpTokenRequest.php"
  - "app/Http/Requests/DestroyMcpTokenRequest.php"
  - "app/View/Components/Mcp/**"
  - "routes/mcp.php"
  - "resources/views/mcp/**"
  - "resources/views/components/mcp/**"
  - "database/factories/McpTokenFactory.php"
---

### The MCP server — the catalog as a tool, and a token is not an account

`POST /mcp` lets Claude, ChatGPT and Gemini read this inventory: soluções,
diagramas, pessoas, empresas and the published documentation, as twelve
read-only tools (`App\Mcp\ToolRegistry`). It is the app's one non-browser
surface — `routes/mcp.php`, the `mcp` middleware group, **no session, no CSRF,
no `auth`** — and it is deliberately small: `App\Mcp\McpServer` speaks the four
methods every client actually sends (`initialize`, `ping`, `tools/list`,
`tools/call`), answers plain JSON rather than an SSE stream, and holds no state
between requests.

**The authentication is one bearer token and that was the requirement**, not a
shortcut taken on the way to OAuth. MCP's own authorization spec is OAuth 2.1
with dynamic client registration; what it buys — a consent screen, per-user
identity, revocable grants — is bought more plainly here by a named row an admin
creates and deletes on `/mcp-tokens`. Two consequences follow and both are easy
to undo by accident:

- **The 401 carries a BARE `WWW-Authenticate: Bearer`.** Adding the spec's
  `resource_metadata` parameter is how a client discovers an authorization
  server and starts a flow this app does not implement — it turns a legible
  "seu token está errado" into a client hanging on a discovery document that
  404s.
- **`McpToken` is not a `User`, and must not become one.** An account is a
  person: it has a role, a password, a session, and `people.user_id` may point
  at it. A token authenticates a PROGRAM. Modelling it as a fifth `UserRole`
  would mean a tier nobody can log in as and `auth()->user()` returning
  something that is not human in the half of the app that assumes it is.

Five rules the credential itself holds:

- **`sha256`, not bcrypt, and that is the right call here.** A password is
  hashed slowly because it is short and guessable; this is 40 characters of
  CSPRNG, so there is nothing to guess — and a slow hash cannot be an index,
  which would make every MCP request a full scan comparing row by row.
- **The plaintext exists for ONE response.** `McpToken::mint()` returns it,
  `Mcp\TokenList::slot($plain)` prints it once, and nothing stores it — not a
  flash message, not a session key, because both would put a live credential in
  the session store to save one screen. A lost token is replaced, never
  recovered.
- **Deleting IS revoking.** No `revoked_at`, no soft delete: the row is the
  credential, so removing it cuts access on the next request with nothing to
  restore.
- **`last_used_at` is throttled to once a minute.** A client sends `tools/list`
  and then a call per turn, so an unthrottled touch makes every read of the
  catalog a write to this table — and "posso apagar este token?" is answered
  exactly as well by a minute-old timestamp.
- **The rate limit keys on the TOKEN, falling back to the IP.** Every request
  from a given chat product arrives from that product's egress range, so an
  IP-keyed bucket is shared by unrelated tokens and split for one token used
  from two devices. The IP fallback covers requests that never reached the
  middleware — a wrong token — which is the traffic worth limiting by origin,
  since without it guessing tokens is unthrottled.

**Documentation means the PUBLISHED cadernos and nothing else**
(`App\Mcp\Support\PublishedNotebooks`, which is `Notebook::scopePublished()`
behind one door rather than four call sites). It is `/docs`'s rule, including
the part that looks like a mistake: an unpublished caderno is invisible even
though an admin minted the token and can read it in the app. `/docs` exists so
that "o que foi publicado" is answerable by looking at it, and this server
answers on behalf of a program in somebody else's chat window — the audience
furthest from that decision. Two things follow:

- **A slug that cannot be served answers with ONE message** for "não existe" and
  for "existe mas não foi publicado", like the dead magic link. Telling them
  apart lets whoever holds the token enumerate what this company runs by
  guessing names.
- **An empty corpus is a SENTENCE, not an empty list.** Until somebody publishes,
  `list_notebooks` legitimately returns nothing — and a model handed `[]` reports
  "não há documentação", which is false about an app holding 621 pages. The note
  says what is actually true and who can change it.

**`SecretText::mask()` is the load-bearing line of `GetDocumentationPage`.** That
tool is the SEVENTH surface handing a page's text to somebody and by far the most
exposed — the text leaves the building. A protected value exists so an
`Authorization` header can live in documentation without every editor reading it;
a bearer token in somebody's chat client is not the audience that rule was
relaxed for. What the model sees is `{% secret %}[[SECRET-1]]{% endsecret %}`:
enough to say a value is there, nothing to quote. **There is deliberately no
reveal tool** — the plaintext has exactly one door (`RevealPageSecret`, throttled
per reader), and a second one reached by a static token would undo all of it.
The search index needs nothing, for the reason it always did: it indexes the
RENDERED html, so it indexes locks.

Five rules about what the tools return, each of which has a reason:

- **Every reference between things is a SLUG; no payload carries a numeric id.**
  An id the model quotes back is an id it cannot call anything with, and one it
  quotes to a person is a number that means nothing on any screen. A summary is
  a strict SUBSET of the full record for the same reason — `search_solutions`
  and `get_solution` have to be chainable without re-learning the shape.
- **Blank fields are DROPPED** (`Presenter::compact()`). Most optional columns
  are empty on most rows, and `"cloud": null` reads to a model as "this solution
  HAS no cloud" when it means "ninguém preencheu". `false` and `0` survive,
  being answers.
- **A truncated list says so.** `total`/`returned`/`truncated` on every search,
  because a model shown 25 results and no count reports "existem 25 soluções".
- **A filter the vocabulary does not know is REFUSED, never dropped**
  (`Mcp\Support\Vocabulary`). A silently ignored filter answers with the whole
  catalog and the model reports it as the filtered result — the one failure here
  that is confidently wrong rather than merely empty. The same class puts the
  catalog's values INTO the JSON Schema as an `enum` with their labels, since a
  model has never seen `iam` and would otherwise guess; `resolve()` then accepts
  the label back, because every payload prints labels and passing one back is the
  obvious thing to do.
- **The search reuses the app's own scopes**, `Solution::scopeFilter()` and
  friends, so the MCP cannot answer a different question from the screen
  somebody checks it against — and inherits `whereFolded()`, which matters more
  here than anywhere since a model types the accents it feels like typing.

Three smaller things paid for already:

- **Searching every caderno is bounded by TIME, not by a count.** A cold index
  costs ~6 s to build, so "todos os cadernos publicados" has a cost nobody can
  state in advance, and the failure it produces is the worst kind — a request
  that hangs until the gateway gives up. `SearchDocumentation` stops when its
  budget is spent and REPORTS which cadernos it did not reach, so a partial
  answer says it is partial. Never on the first caderno, though: a budget that
  can answer nothing turns a slow search into a silent empty one, which reads
  exactly like a corpus that does not mention the term.
- **A tool failure is a RESULT (`isError`), a protocol failure is a JSON-RPC
  error.** The line is who can fix it: a slug that matches nothing is the
  model's question and has to arrive where the model can read it, while a
  missing required argument is the client ignoring the schema and would fail
  identically on retry.
- **`GET /mcp` answers 405, not 404.** A 404 on the connector URL reads to
  whoever is configuring it as a wrong address; 405 says "right place, this
  server sends no stream".

**`/mcp-tokens` is what found a leak in the sidebar's `can` gate**, and the fix
is generic enough that it lives in AGENTS.md § Global layout rather than here.
The short version: the gate was written twice, once per nav loop, each
hard-coding `Notebook` as the model to ask about — so this admin-only entry was
correctly hidden in the desktop rail and offered to everybody in the mobile
drawer. It is computed once now, and an item names its own model with
`canModel`.

