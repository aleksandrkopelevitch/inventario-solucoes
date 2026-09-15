---
paths:
  - "app/Enums/FlowspecTarget.php"
  - "app/Actions/Flowspec/**"
  - "app/Services/Flowspec/**"
  - "app/Support/Flowspec/**"
  - "app/Support/Digibee/DigibeeDesignClient.php"
  - "app/Support/Digibee/DigibeeCredentials.php"
  - "app/Support/Digibee/Deploy*.php"
  - "app/Actions/Digibee/DeployPipeline.php"
  - "app/Actions/Digibee/IngestFlowspec.php"
  - "app/Actions/Digibee/RunPipelineTestSuite.php"
---

### Writing a flowSpec INTO a pipeline — one document, two shapes

The generator's output is written to be PASTED, and a pipeline stored on the
platform is a different shape of the same document. `App\Enums\FlowspecTarget`
is that mode key, and it is measured rather than stylistic: all 201 pipelines
exported from the tenant root at `start`, none carries a top-level `meta` and
no step carries a `position`, while a clipboard document is required to emit
exactly one `disconnected-root:<uuid>` plus a `meta` entry per canvas step. So
`meta.position` is a clipboard construct, and `DigibeeFlowspecNormalizer` /
`DigibeeFlowspecValidator` both take the target — run with the wrong one, the
validator is exactly inverted: it rejects every pipeline the tenant runs and
passes documents that can never be ingested.

**The mode key deliberately stops before the system prompt.** The model keeps
one generation contract (rule 1, the clipboard root) and
`App\Actions\Digibee\IngestFlowspec` converts, because the difference is
mechanical — rename one branch key, drop `meta` — and a second contract would
double what every prompt regression has to be tested against. Renaming the
entry branch is safe for a specific reason: it is the one branch no step can
reference (a `choice` targets branch names, a for-each track is named after its
own step id), so it is a key rename and not a graph rewrite.

Five rules the write path holds, each paid for by something Fase 1 observed:

- **Validate before writing, refuse on any error.** Whether
  `POST /pipelines` validates a flowSpec at all is still an open question, and
  the evidence points at "no" — the upsert accepted a one-step document without
  complaint. An invalid document is then stored happily and breaks when
  somebody opens the canvas.
- **Resolve by NAME, filter on the server, match exactly on the client.**
  `?name=` is honoured; `?projectId=` is ignored in silence. Nothing published
  says whether the name filter is exact or a prefix, and a prefix would hand
  back `zfl-cadastro-cliente-v2` for `zfl-cadastro-cliente` — writing the
  flowSpec into the wrong pipeline. Listing unfiltered is a multi-megabyte
  download: 1801 items, each embedding its whole flowSpec.
- **`POST` on the collection is both verbs.** With an `id` it upserts, without
  one it CREATES — so `DigibeeDesignClient::upsert()` refuses an id-less
  document rather than silently making a second pipeline with the same name.
  Nothing in the platform deletes a pipeline, which is what makes every
  mistaken create permanent, and why there is no delete method on that client
  at all.
- **200 is not evidence of a write**, on a route that answers 200 for a create,
  an upsert and a discarded field alike. The flowSpec is read back and compared
  byte-for-byte, which is the property A″ established by hand.
- **`metadata.canvas` and `metadata.integrityHash` are dropped; derived
  counters travel stale and are REPORTED.** `canvas` embeds the old node graph,
  trigger node included, so carrying it forward draws the previous pipeline
  over the new one — and dropping both is safe by measurement, since 161 of the
  201 stored pipelines have neither key. Recomputing `counters` would mean
  guessing what the platform counts as a step, a capsule and a subFlow.

A `triggerSpec` that already exists is PRESERVED unless replacing it is asked
for explicitly: it carries the endpoint's authentication mode and methods,
which a person configured. `App\Actions\Flowspec\SynthesizeTriggerSpec` builds
one for a pipeline that has none, and `DigibeeTriggerAuth::None` is never its
default — the tenant's convention is authenticated (`basicAuth` in 55 of 56
`http` specs, `keyAuth` in 21 of 28 `rest`), so an open endpoint has to be
asked for. It refuses to invent a cron expression or an event name, because
both fail by RUNNING: a guessed schedule runs at the wrong hour, and an
invented event name subscribes to something nobody publishes.

**Validating the tenant's own 201 pipelines is what found two rules that
described nothing real** (clean: 88 → 188). `params.onException` is absent in
296 of 384 track references and never dangling, so an exception track is
OPTIONAL — demanding one rejected 101 live pipelines; `onProcess` stays strict,
being a real branch in 404 of 404. And `iterators`/`replica` are documented
Double Braces scopes that `VALID_SCOPES` and prompt rule 6 both omitted, so a
for-each body written the documented way came back as "unknown scope" until the
attempts ran out. Both are the same failure as the `simple` choice condition and
the scrubber's date keys: a rule written against generated documents, never
checked against the estate it describes.

**What a pipeline says about its own OUTPUT, and the trap in reading it.** A
`json-generator` or `jslt` at the end of a branch names its keys literally,
which is the only honest source for asserting more than "a body came back" —
`ShapeTemplate` reads them and `BuildPipelineTestMatrix::responseContract()`
decides what may be claimed. The trap: the commonest terminal shape in the
estate is not a response at all. 105 of the 178 declaring terminals emit
`{code, body, Content-Type}`, which Digibee's HTTP trigger reference defines as
the endpoint's own ENVELOPE — `code` becomes the status, `body` the payload — so
asserting `$.code` would fail against most of the tenant. Three rules keep the
claim true: one terminal that declares nothing voids the whole contract, only
the INTERSECTION across terminals is asserted (nobody knows which branch the
happy path takes), and the status is narrowed only when every terminal returns
the same literal code. Yield over the 201: 18 pipelines gain 30 real assertions
and one an exact status — far short of the 70 with a derivable shape, and that
gap IS the honesty.

**The response's media type is the one claim made about a HEADER, and the source
is the TRIGGER rather than the flow.** `Content-Type` is literal in all 105
envelopes and that settles nothing: per pipeline the terminals agree only 3
times in 201, because a success branch answering JSON beside an error branch
answering XML is the norm. `triggerSpec.responseContentTypes` constrains every
response the endpoint can give, so a trigger declaring exactly one type claims
it for all branches — rare in the legacy estate, universal in what this app
generates. Three details the comparison needs:
`PipelineTestCase::mediaTypeOf()` compares the MEDIA TYPE, never the raw header
(the platform sends `application/json;charset=UTF-8` against a declared
`application/json`, so equality fails every correct answer); a missing header
with a claim standing is a FAILURE, not "nothing to check"; and the claim rides
on the happy path alone, since the error and contract cases accept a status
range on purpose and some of those answers come from the gateway.

**The battery is visible in the app** (`x-flowspec.test-matrix`, a `<details>`
on the message that produced the flowSpec). It had no reader at all for a
while, which made the blocked cases — the honest half of the whole matrix —
readable only from PHP. Only a VALIDATED document gets one: a document with
pending errors still builds a battery, but its cases describe a flow the
validator already refused. No endpoint URL is rendered, because nothing has
been ingested and the pipeline name is a slug of the conversation's title.

**Running that matrix is `RunPipelineTestSuite`, and it is hostile traffic by
design.** It refuses any environment outside
`services.digibee.design.deployable_environments` — "may deploy here" and "may
fire malformed payloads at it" are the same question, so they share one list;
it never sends a BLOCKED case, whose placeholders name a field
(`"<cpf>"`) rather than carrying a value; it does not retry, because a 500 is
the signal and re-firing a POST that half-ran duplicates what it wrote; and it
distinguishes "refused at the door" from "failed" — a wall of 401s with no
credential given is the single most misleading thing this feature can hand a
model, since it looks exactly like a pipeline that rejects everything. The
endpoint credential (`EndpointCredential`) is NOT the design credential and
never comes from configuration: sending a realm-wide token to the runtime host
would hand one service another service's keys.

**The `Authorization` scheme depends on WHICH credential is resolved, and
getting it wrong answers 401 on a perfectly valid token.** An interactive
`digibeectl` session goes in the header RAW — prefixing it with `Bearer ` fails,
which is why nothing here uses `withToken()`. A scoped digibeectl TOKEN
(Administration → Digibeectl) is the exact opposite: raw answers 401, `Bearer `
answers 200 (measured one GET per variant against the real realm, 2026-09-11).
`DigibeeCredentials::headers()` decides by reading the JWT's own payload
(`useTokenACL: true` marks a token ACL) rather than by a config flag, whose
failure mode is a silent 401 that reads as "the credential is wrong". Two more
things that credential taught: a token's permissions live IN the JWT and are
environment-scoped (`DEPLOYMENT:CREATE{ENV=TEST}`), which the role-permission
table cannot express — so `digibee:design:probe --diagnose` prints the ACL,
making "may this credential do what I am about to ask" answerable offline
instead of by a 403; and there is no `PIPELINE:UPDATE` for tokens at all, so the
upsert authorizes under `PIPELINE:CREATE`.

**Deploying (`DeployPipeline`) is the verb that reaches real traffic**, so the
guardrails sit there rather than on DELETE, which is where the spec expected
them. The environment must be in
`services.digibee.design.deployable_environments` — configuration, not an
argument — and the TOKEN is asked before the platform is: a scoped token
carries its ACL in the JWT with environment scoping, so "this credential may
not deploy there" is answered before the request instead of as a 403 after it
(an interactive session declares no roles and is left to the platform, since
guessing would refuse a deploy that would have worked). Waiting has a ceiling
and "did not settle in N seconds" is a THIRD outcome beside refused and broken
— collapsing it into either is how a correction loop starts rewriting a
pipeline that was merely slow. And `availableReplicas: "0/0"` is not a failure:
it is the ordinary parked state for 81 of the 111 deployments in `test`.

**The platform reports the URL it assigned**, inside `deploymentStatus.trigger`
as a JSON string holding a key/value list, and `Deployment::endpoint()` reads
it. `RunPipelineTestSuite` prefers that over composing one from
`runtime_hosts`, which removes the failure that map exists to prevent: an
address assembled wrong calls another environment while the report says this
one. Composition stays as the fallback for a pipeline nothing has deployed yet.

**On the deploy route, a 403 and a 404 can both be information about the
PAYLOAD** — the opposite of what those statuses usually mean, and it cost three
identical denials to learn. The environment is a QUERY parameter: put it in the
body, as `digibeectl --environment` suggests, and the permission check (which
runs before the handler and is environment-scoped) sees no environment,
evaluates against nothing and answers 403. The tell was that the denial did not
change when the environment did. The pipeline is named by `pipelineId`, not
`id`: with `pipelineId` the handler gets as far as validating the pipeline's
trigger, while `id` answers 404 "No such entity" whatever it carries. And a v0
pipeline DOES deploy — the canvas deploys v0 rows.

**The deploy body is `{pipelineId, runtimeConfigurationId}`, and the second key
came out of the `digibeectl` BINARY** after fifteen guesses failed on it. Go
embeds string literals and the CLI builds that body by concatenation, so
`strings` on it prints the template in pieces, along with every model's `json:`
tags and the route shapes. **A published Go binary is API documentation nobody
wrote**, and reaching for it beats both guessing and asking somebody to capture
a request in devtools.

**A pipeline has six runtime configurations — three sizes × two environments —
and that is the second place production can be reached.** Each carries
`environment.name`, and only `GET /design/realms/{realm}/pipelines/{id}/configurations`
names them: the design document's own `configurations` array has ids and
versions with no name and no environment. `DeployPipeline` matches on size AND
environment and refuses when it cannot find exactly one, because
`deployable_environments` does not catch this — a request aimed at `test` with
prod's configuration id still says `test` in the query string.

`digibeectl` is still not involved and the boundary in
`App\Support\Digibee\DigibeectlClient` is untouched — this is HTTP with the
credential `DigibeeAuthResolver` resolves. The ingestion is driven by hand
(`digibee:flowspec:ingest`, with `--dry-run` and a confirmation), and like the
probe it stays out of `routes/console.php`.
