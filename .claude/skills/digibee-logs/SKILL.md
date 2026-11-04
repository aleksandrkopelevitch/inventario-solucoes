---
name: digibee-logs
description: Search Digibee production (or test) execution logs for a keyword — an order id, a SKU, a CNPJ, an error text — optionally narrowed to a pipeline and a time window, collect EVERY matching execution with its whole log, and organise it into a report. Use when the user asks what happened to something in Digibee, why an integration failed, to find something in the pipeline logs, or for a report over a pipeline's executions.
argument-hint: "<termo> [pipeline] [janela: 30m|6h|2d] [test]"
allowed-tools: Bash(php artisan digibee:logs:*), Bash(jq:*)
---

# Collect Digibee logs and report on them

Arguments: `$ARGUMENTS`

Everything here goes through `php artisan digibee:logs`, which replays the
user's own Monitor session from this workstation and writes **already
redacted** output (see `.claude/rules/digibee-knowledge-base.md`, section
"Reading production logs"). It is read-only. Never reach Digibee any other way:
no curl, no reading `storage/app/private/digibee-monitor/session.json`, never
print or reuse a token — not even one the user pasted.

The goal is **everything that matches**, not a sample: the user builds reports
from it and looks for things in it. Do not pick "the interesting ones"; collect
all of them and let the analysis decide what matters.

## 1. Read the arguments

The arguments are free text. Pull out:

- **term** — what to look for. Quoted text is one term.
- **pipeline** — a token shaped like a pipeline name (`evt-…`, `zfl-…`, `api-…`,
  any lowercase-hyphenated name). Exact name; the platform does not match
  partially.
- **window** — either how long ago (`15m`, `6h`, `2d`, ending now) or a range
  on the local clock: `03/11 14:00 até 03/11 16:00`, `de 03/11 a 04/11`,
  `ontem das 14h às 16h`, `hoje 9:00-11:00`. Translate words like
  `ontem`/`hoje` into dates yourself (today is in the session context) and
  pass the two ends as `--since` and `--until`, in one of the forms the
  command reads: `2026-11-03 14:00`, `03/11 14:00`, `03/11`, `14:00`, `6h`. A
  bare date as the end means its midnight, so "de 03/11 a 04/11" whole days is
  `--since=03/11 --until=05/11`. Default `1d`. Logs live **10 days in prod,
  3 in test**; cap there and say so if more was asked.
- **environment** — `prod` unless the user says `test`/`teste`/`qa`.

A pipeline with no term is a valid request: a report over that pipeline's
executions in the window. Neither a term nor a pipeline → ask what to search
for.

## 2. Collect

A value can show up in a **log line** (a Log connector's message) or only in an
**execution payload** (the request/response the platform kept), so with a term
collect both. Each run opens every matched execution (5 at a time) and writes
one JSON file; name the files after the request:

```
php artisan digibee:logs --environment=<env> --since="<start>" [--until="<end>"] [--pipeline=<p>] --message="<term>" --limit=100 --pages=20 --collect=<slug>-linhas.json
php artisan digibee:logs --environment=<env> --since="<start>" [--until="<end>"] [--pipeline=<p>] --search="<term>"  --limit=100 --pages=20 --collect=<slug>-payloads.json
```

Before a broad collect (a pipeline with no term, or a window of days), size
it first with `--limit=1` — the footer gives the platform's count (`10000+`
means "at least"). A busy pipeline runs thousands of executions an hour, in
bursts; if the count is past ~1000, tell the user the size and the time it
would take (~2 minutes per 500) before collecting, and suggest a term or a
shorter range.

Without a term, one run with neither `--message` nor `--search` (it lists the
pipeline's executions).

Files land in `storage/app/private/digibee-monitor/reports/` (git-ignored,
owner-only). Use a Bash timeout of 10 minutes: 500 executions take ~2 minutes,
and one run opens at most 1000.

- **Whenever a `digibee:logs` call cannot reach Digibee** — a missing or
  expired session, a 401 or 403, a connection error, a timeout; the command
  then ends with "sign in again first" — **stop** and ask the user to sign in:
  `! php artisan digibee:monitor:login`. It opens a browser for their IdP
  sign-in, which only a person may do. Do not retry in a loop, do not try to
  diagnose the network first, and do not run the login yourself; once they
  say it is done, repeat the call that failed.
- The summary warns when the window held more than was collected (`more
  matches in this window`), when executions passed the 1000 cap (`not_opened`)
  or could not be exported (`unreadable`). Report each to the user with the
  numbers; to get the rest, split the window into consecutive
  `--since`/`--until` ranges (e.g. `03/11 00:00`→`03/11 12:00`, then
  `03/11 12:00`→`04/11 00:00`), one file each, rather than dropping matches.
  Analyse the files together (`jq -s`).

## 3. Analyse the files with jq

The file shape:

```
{ query: {environment, searched, pipeline, message|search, since, from, to, timezone},
  matches, platform_total, more_in_window, executions_found, not_opened[], unreadable[],
  executions: [ { key, pipeline, version, started_at, ended_at, levels: {INFO: n, ERROR: n…},
                  unreadable, lines: [ {at, level, message} ],
                  // executions search only:
                  status, elapsed_ms, payload_request, payload_response,
                  // log-line search only:
                  matched_lines[] } ] }
```

Executions are chronological; times are local (`query.timezone`). Do not `Read`
a big file whole — query it. Useful starting points:

```
jq '[.executions[] | {pipeline, version}] | group_by(.pipeline) | map({pipeline: .[0].pipeline, n: length})' F
jq '[.executions[] | select((.levels.ERROR // 0) > 0)] | length' F
jq -r '.executions[] | .lines[] | select(.level == "ERROR") | .message' F | sort | uniq -c | sort -rn | head -30
jq -r '.executions[] | [.started_at, .key, (.status // ""), (.elapsed_ms // "")] | @tsv' F
jq '.executions[] | select(.key == "<key>")' F
```

Aggregate first (counts, distinct error messages, statuses, duration
percentiles, first/last occurrence, gaps), then drill into the executions that
explain the aggregates. Group repeated messages by their shape (strip ids and
numbers) before counting.

## 4. Report — in Portuguese, to the user

Write it in the chat unless the user asks for a file. Shape it to what was
asked; by default:

1. **Resumo** — what was searched, how much was found, what happened, and the
   one thing to look at.
2. **Números** — per pipeline/version: executions, with error, statuses,
   duration (min/median/max), first and last occurrence.
3. **Erros e avisos** — each distinct message, with its count, first/last time
   and an example execution key.
4. **Linha do tempo** — for a specific term: every execution in order, with the
   few lines that tell its story. For a broad report: the notable moments
   (first error, bursts, gaps).
5. **Leitura** — what the data suggests, marked as inference, and the next
   check worth doing.

Always state coverage: matches collected vs. the platform's total, and what
was capped or unreadable.

When the user asks for a file (CSV, Markdown), write it next to the JSON in
`storage/app/private/digibee-monitor/reports/` and give the path — it is
redacted production data, so not in the repository, not in `/tmp`.

## Rules for the content

- Masked values (`[redacted]`, `[email]`, `[cpf]`, `[cnpj]`, `[phone]`,
  `[redacted-jwt]`) stay masked. Never try to recover them, and never repeat a
  credential or personal datum if one slipped through — point it out instead,
  it is a redaction gap worth fixing in `LogRedactor`.
- The term the user searched for may itself be personal data (a CPF, a
  phone); use it in the commands, but don't spread it around the report more
  than needed. The platform searches the UNREDACTED text, so a line can match
  and still not show the term: it sat in a masked field. Say so ("o valor está
  num campo mascarado — CpfCnpj/Telefone"), naming the masked keys in that
  line, instead of reporting the match as unexplained.
- Never run anything that writes to Digibee. This skill only reads.
