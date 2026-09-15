---
paths:
  - "app/Support/Documentation/SecretText.php"
  - "app/Actions/Documentation/**"
  - "app/Http/Requests/RevealPageSecretRequest.php"
  - "resources/js/modules/docs-secret.js"
  - "resources/js/modules/docs-tools/secret.js"
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

**A value is addressed by its ORDINAL** — 1 for the first `{% secret %}` in the
page's text, in document order. It lives inline in the Markdown, so there is no
row to give it an id, and three surfaces have to agree on the number: the
renderer that paints the locks, the reveal endpoint that re-parses the page, and
the editor, which is shown `[[SECRET-n]]` markers (the same marker vocabulary as
`LiteralVault`, one step further: that keeps a literal from the MODEL, this keeps
it from the PERSON). Numbering by position costs one thing, accepted: a reader
holding a page while somebody adds a value above it reveals the wrong one of the
two.

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
  `` `[[SECRET-n]]` `` — construct gone, marker bare — so a plain restore wrote
  the real value into the page in the clear. Both nestings serialize to ONE
  shape now, backticks outside and the construct inside (the only order the
  renderer paints as a lock inside `<code>`), and `codeToHtml()` turns it back
  into a chip on the way in.
- **`restore()` RE-WRAPS a marker that lost its construct** rather than
  resolving it into naked plaintext. That is what makes the protection the
  server's and not the client's: no serializer bug of the shape above can
  unprotect a value again, whatever the client posts. Nobody types
  `[[SECRET-2]]` by hand, so a bare marker can only mean "the protected value
  that was here"; deleting the marker is still how a value is unprotected, and
  that stays unambiguous.

Sharing and the code are `NotebookPolicy::administer` (admin), NOT `update`:
both reach beyond the page an editor is writing, and an editor who could read
the code off the share panel would be able to unlock exactly what this exists to
keep from them.
