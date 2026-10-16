---
paths:
  - "app/Support/Documentation/SecretText.php"
  - "app/Actions/Documentation/**"
  - "app/Http/Requests/RevealPageSecretRequest.php"
  - "resources/js/modules/docs-secret.js"
  - "resources/js/modules/docs-tools/secret.js"
  - "resources/js/modules/docs-editor.js"
  - "resources/js/modules/docs-markdown.js"
  - "database/migrations/2026_10_16_175559_assign_ids_to_documentation_secrets.php"
  - "app/Console/Commands/AuditDocumentationSecretsCommand.php"
---

### A protected value leaves the server once, one value per request

`{% secret %}…{% endsecret %}` hides a value inside a documentation page — an
`Authorization` header, an env var, a service password. The reader sees a
**lock**; the plaintext is handed out by
`App\Actions\Documentation\RevealPageSecret` and by nothing else, to an admin
or to whoever types the caderno's secret code (`notebooks.secret_code`, six
characters, shown only on the admin's share panel and rotatable there).

**It is the sixth construct of the dialect and the only INLINE one** — a
protected value is a token in a sentence, a table cell or a line of a code
sample, never a block. So it is handled in `App\Support\Documentation\SecretText`
plus `inlineToMd()`/`inlineToHtml()` in `docs-markdown.js`, not in
`serializeBlock()`/`parseLines()`, and the editor writes it with the app's one
INLINE Editor.js tool (`docs-tools/secret.js`, a `<span class="ak-secret-mark">`).

**A value is addressed by its ID, stored on the construct**:
`{% secret id="k3j9x2ab" %}…{% endsecret %}` (eight of `[a-z0-9]`). Three
surfaces use it: the renderer puts it on each lock (`data-ak-secret`), the reveal
endpoint looks the value up by it, and the editor is shown it inside the marker,
`[[SECRET-k3j9x2ab]]` (the same marker vocabulary as `LiteralVault`, one step
further: that keeps a literal from the MODEL, this keeps it from the PERSON). The
id never reaches the editor as an attribute; `mask()` moves it into the marker and
`restore()` puts it back.

**It used to be the value's ORDINAL, and that scrambled pages in the field**
(fixed 2026-10-16). The editor is handed its markers once, when the page opens,
and autosave saves ~1.2s after every pause. Adding, deleting or moving a value
renumbered the page on the first save while the editor kept the old numbers, and
every later save put values back in the wrong places: a value added above two
others was the page's only value three saves later; a deleted one left a lock
opening onto the literal text `[[SECRET-3]]`; two swapped values swapped back on
every save. Nothing errored. `it('keeps every value in place across the saves of
one editing session')` replays all three through the real endpoint, and fails on
save 2 against the ordinal code. Pages already damaged that way stay damaged: the
value was overwritten, and only a backup has it.

`php artisan documentation:secrets-audit` (read-only; `--all`, `--notebook=`)
lists what the bug left a trace of: a marker stored as text is BROKEN, two
identical values on a page are SUSPECT (a repeat can be deliberate). Swapped
values leave no trace, so `--all` adds every page with values and its last-save
date, for comparing against a backup. It names values by ordinal and never prints
one. Run it as `www-data` in production, like any artisan command there.

Five things that keep the id honest:

- **The id is RANDOM, never derived from the value.** An HMAC of the value was the
  first design and it is an oracle: an editor who may not read a value types a
  guess as a new protected value, saves, reloads and compares the two markers,
  once per save and unthrottled. A random id says nothing.
- **`restore()` never trusts a posted id.** A construct whose body is not exactly
  one marker is a typed value, and gets a fresh id whatever its `id="…"` says.
  Constructs and bare markers are read in ONE pass, so a marker inside a value
  just put back (the `[[SECRET-3]]` dead lock above) is never read twice.
- **Every stored construct has an id.** `DocumentationPage::booted()` runs
  `SecretText::withIds()` on every write, and a migration gave the older rows
  theirs (through the query builder, so nobody becomes the last updater).
  `mask()` relies on it: a construct with no id and no marker can only be text
  that was never saved, and it gets an id no save resolves, i.e. a visible dead
  lock, never a number that could resolve to somebody else's value. That
  migration's `down()` strips EVERY id, including those of pages saved since:
  the code a rollback returns to does not recognise `{% secret id="…" %}`, and
  would render the value as plain text.
- **Numbered markers still resolve, by ordinal.** An editor tab open across the
  deploy, or an assistant draft written before it, holds `[[SECRET-2]]`; without
  the fallback its next autosave would turn every value on the page into a dead
  lock. Ordinals are 1–7 digits and ids exactly eight characters, so the two
  cannot be confused. The reveal endpoint accepts both (`[a-z0-9]+`) for the same
  reason.
- **After a save the editor holds markers only.** The save answers `secretIds`,
  one id per `{% secret %}` posted, in order, and `docs-editor.js`
  (`adoptSecretIds`) swaps each value typed since the last save for its marker,
  pairing the two lists through `secretBodies()`. That regex matches exactly
  what `SecretText::CONSTRUCT` matches (ASCII whitespace only, `[^\n]` for PHP's
  `.`); a list of a different length is not paired at all. This matters for the
  assistant, not only for hygiene: it is handed the editor's content, and a
  typed value it received would come back as a marker no save resolves. So
  `docs-chat.js` calls `flushSave()` before capturing that content. The chip
  holding the caret is skipped (right after "Valor protegido" wraps the text,
  that is the chip), which is why `flushSave()` also saves while any typed value
  is still on screen, not only when the page is dirty.

**A chip holding a marker is ATOMIC** (`contenteditable="false"`,
`docs-markdown.js` `secretChip()`; `adoptSecretIds` sets it on the chips it
swaps). Found driving the real editor: End at the end of a paragraph that closes
on a chip leaves the caret INSIDE the span, so the next words typed went into
the protected value instead of the prose. With ids that was worse than a stray
suffix: the save stored `BRAVO x1` under a NEW id while the editor still named
the old one, and the save after that lost BRAVO. Nobody can read the value, so
there is nothing in it to edit; atomic, it is typed around and deleted whole. A
value still being typed stays editable until a save swaps it for its marker.

Atomic inline nodes have a cost in Editor.js, paid in `docs-editor.js`'s
capture-phase keydown handler. Its caret helper assumes the node before a caret
at the end of a block is TEXT, so with a chip as the block's last child it asks
for offset `<chip text length>` inside the chip ELEMENT and throws an
IndexSizeError from Backspace, Delete, the arrows and Enter (Enter then fails to
create a block). And Chrome, on End, parks the caret inside the chip's own text,
where Backspace deletes nothing. The handler moves the caret into a text node
beside the chip (an empty one if there is none) before either of them acts.
Verified in Chromium 2026-10-16: Enter, → / ↓ to the next block, Backspace
removing the chip, typing after it, with zero page errors.

Six things that are easy to undo, and all of them are the same rule seen from a
different screen:

- **Every audience gets masked content, admins included.**
  `EditsDocumentation::documentationView()` masks unconditionally and
  `persistDocumentation()` restores unconditionally. The strict version is what
  makes the invariant auditable — the plaintext has exactly one door — and it is
  what stops an admin's editor putting a page's worth of credentials on screen,
  into the assistant chat's stored `existing_content`, and into "Copiar
  Markdown". The restore has to stay unconditional for a second reason: the
  assistant is ALWAYS shown markers, so an admin who applies a draft is saving
  markers too.
- **The renderer has no "show them anyway" flag.** It had one for about an hour;
  the only screen that could have passed it true does not exist, because
  everyone who may read the values can also edit the page and therefore gets the
  editor, never the read-only render.
- **The four other places a page's text is handed to somebody are masked too**,
  and each was a live leak while it wasn't: the read-only "Copiar Markdown"
  textarea, the public one (`PublicDocumentationController::render()`), the
  documentation assistant's prompt (`DocumentationChatService`) and the
  **flowSpec** assistant's (`FlowspecPromptBuilder::documentationSection()`).
  The search index needs nothing — it indexes the RENDERED html, so it indexes
  locks.
- **The throttle is the protection, not the code's length.** Six alphanumerics
  is ~36 bits; five attempts per reader per caderno per twelve hours is what
  makes an online search of that space pointless. It lives in the action rather
  than as `throttle:5,720` on the route because a middleware would count an
  admin's successful reveals and could not CLEAR the counter — a correct code
  buys back the failures before it. `docs-secret.js` mirrors the same window in
  `localStorage`, which is a courtesy (an immediate, legible lockout), never the
  limit.
- **A code fence holding a lock is left UNPAINTED.** `docs-highlight.js` rebuilds
  a block from its `textContent`, so highlighting would turn the lock's
  `<button>` back into the words "valor protegido" — no click target and no
  explanation. `docs-code.js` skips those blocks; monochrome code is the cheaper
  half to lose. (Getting a lock into a fence at all is why the renderer
  substitutes a `\x1F`-delimited TOKEN before parsing and paints markup only
  afterwards: `renderLines()` consumes a fence verbatim and commonmark escapes
  everything in it.)
- **The reveal popover is appended to `document.body`.** In the editor the lock
  is a marker inside Editor.js, which decides a block changed by watching its
  DOM — a field injected beside it reads as an edit and autosave writes the page
  seconds later (the trap `docs-tools/diagram.js` needs `data-mutation-free`
  for).
- **A protected value lives inside `<code>` more often than not, and that is
  where the round trip broke.** `nodeToMd`'s `CODE` branch serialized with
  `textContent`, flattening `<code><span class="ak-secret-mark">` into
  `` `[[SECRET-…]]` `` — construct gone, marker bare — so a plain restore wrote
  the real value into the page in the clear. Both nestings serialize to ONE
  shape now, backticks outside and the construct inside (the only order the
  renderer paints as a lock inside `<code>`), and `codeToHtml()` turns it back
  into a chip on the way in.
- **`restore()` RE-WRAPS a marker that lost its construct** rather than
  resolving it into naked plaintext. That is what makes the protection the
  server's and not the client's: no serializer bug of the shape above can
  unprotect a value again, whatever the client posts. Nobody types
  a marker by hand, so a bare marker can only mean "the protected value
  that was here"; deleting the marker is still how a value is unprotected, and
  that stays unambiguous.

Sharing and the code are `NotebookPolicy::administer` (admin), NOT `update`:
both reach beyond the page an editor is writing, and an editor who could read
the code off the share panel would be able to unlock exactly what this exists to
keep from them.
