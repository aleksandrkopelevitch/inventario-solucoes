---
name: digibee-logs
description: Search Digibee production (or test) execution logs for a keyword — an order id, a SKU, a CNPJ, an error text — optionally narrowed to a pipeline and a time window, then open the matching executions and organise what happened into a report. Use when the user asks what happened to something in Digibee, why an integration failed, or to find something in the pipeline logs.
argument-hint: "<termo> [pipeline] [janela: 30m|6h|2d] [test]"
allowed-tools: Bash(php artisan digibee:logs:*)
---

# Search Digibee logs and organise the result

Arguments: `$ARGUMENTS`

Everything here goes through `php artisan digibee:logs`, which replays the
user's own Monitor session from this workstation and prints **already
redacted** output (see `.claude/rules/digibee-knowledge-base.md`, section
"Reading production logs"). It is read-only. Never reach Digibee any other way:
no curl, no reading `storage/app/private/digibee-monitor/session.json`, never
print or reuse a token — not even one the user pasted.

## 1. Read the arguments

The arguments are free text. Pull out:

- **term** — what to look for. Quoted text is one term. If nothing else is
  recognisable, the whole argument is the term.
- **pipeline** — a token shaped like a pipeline name (`evt-…`, `zfl-…`, `api-…`,
  any lowercase-hyphenated name). Exact name; the platform does not match
  partially.
- **window** — `15m`, `6h`, `2d`… Default `1d`. Logs live **10 days in prod,
  3 in test**; cap there and say so if more was asked.
- **environment** — `prod` unless the user says `test`/`teste`/`qa`.

No term and no pipeline → ask what to search for; do not list a whole day of
executions.

## 2. Search both places a value can be

A value can show up in a **log line** (a Log connector's message) or only in an
**execution payload** (the request/response the platform kept). Run both, with
the same window and pipeline:

```
php artisan digibee:logs --environment=<env> --since=<window> [--pipeline=<p>] --message="<term>" --limit=100 --pages=3
php artisan digibee:logs --environment=<env> --since=<window> [--pipeline=<p>] --search="<term>" --limit=100 --pages=3
```

- If the session is missing or expired, the command says so. **Stop** and ask
  the user to run `! php artisan digibee:monitor:login` themselves: it opens a
  browser for their IdP sign-in, which only a person may do.
- If a search says "more in this window", say how many there are; narrow
  (pipeline, shorter window) before raising `--pages` past 5.
- Nothing found → say which searches ran (term, window, pipeline, env) and
  suggest the likely miss: a wider window, the other environment, or a
  different spelling of the value (with/without punctuation, leading zeros).

## 3. Open the executions that matter

Collect the distinct execution keys from both searches (the log lines print
the key after the pipeline version; the table has an `execution key` column).
Open them with:

```
php artisan digibee:logs --environment=<env> --key=<execution key>
```

Open at most **10**. Pick first those with an ERROR/WARN line or a status
≥ 400, then the oldest and the newest. Say how many were left unopened, with
their keys, so the user can ask for them.

Some lines are huge (SOAP/JSON payloads). Read them, but quote only the part
that matters.

## 4. Report — in Portuguese, to the user

Write it in the chat, not to a file (unless asked). Times are already in the
local timezone. Shape:

1. **Resumo** — two or three sentences: what was searched, what happened to it
   (processed? failed where? retried?), and the one thing to look at.
2. **Onde apareceu** — a table per pipeline: executions found, how many with
   error, first and last occurrence.
3. **Linha do tempo** — per opened execution, in chronological order: time,
   pipeline + version, execution key, duration/status, and the few log lines
   that tell the story (the call made, the answer, the error). Errors verbatim,
   short.
4. **Leitura** — what the lines suggest (an upstream HTTP 4xx/5xx, a timeout, a
   validation message, a retry loop), clearly marked as inference, and the next
   check worth doing.

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
