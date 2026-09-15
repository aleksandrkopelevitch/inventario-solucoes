---
paths:
  - "app/Http/Controllers/MediaController.php"
  - "app/Http/Controllers/NotebookContextDocumentController.php"
  - "app/Http/Controllers/SubmissionSourceController.php"
  - "app/Rules/PublicUrl.php"
  - "app/Support/Context/**"
  - "app/Contracts/Documentable.php"
  - "resources/js/modules/avatar-upload.js"
---

## Media (Spatie MediaLibrary)

Only 7 models use `HasMedia`/`InteractsWithMedia`, each collection with its own purpose — there is no generic shared collection/conversion pair to reuse:

- `User` — `avatar` (single-file), with one registered conversion, `thumb` (120×120, `nonQueued()` since the source is tiny). `User::avatarUrl()` falls back to `ui-avatars.com` (an external, third-party image, requested client-side from the `<img src>`) when no avatar was uploaded — a deliberate, low-risk default, not an oversight.
- `Notebook` — `context_documents` (`Notebook::CONTEXT_COLLECTION`), the "Assiste IA" context documents (PDF/image/text), served by `NotebookContextDocumentController` — never through `MediaController`/`files.show`. It lived on `Solution` until cadernos became the container: the chat is about a page, a page always has a notebook, and may have no solution at all.
- `DocumentationPage` — the `docs` collection (`Documentable::DOCS_COLLECTION = 'docs'`): images/files embedded in Markdown documentation, referenced as `/files/{id}` and served by `MediaController`/`files.show` (authenticated) or `PublicDocumentationController::file()` (magic-link, token-scoped, checked against the notebook's own pages). It is the only `Documentable`; `Diagram` and `SubmissionDiagram` also register a collection named `docs`, but for a different reason — an image PASTED onto the canvas has to be servable at `/files/{id}`, and `MediaController::show()` authorizes by collection name alone, so nothing outside that name can be served at all.
- `Diagram` — `docs` (pasted image nodes, above) plus `diagram` (`Diagram::DIAGRAM_COLLECTION`, `singleFile()`): the canvas rendered to a PNG by the client on every layout save, so the CATI deck can show an architecture without a browser in the loop. Derived, never an input.
- `SubmissionDiagram` — three: `docs` (pasted image nodes, for the same `/files/{id}` reason as `Diagram`), `submission_diagram` (`UPLOAD_COLLECTION`, `singleFile()` — a C4 picture somebody uploaded INSTEAD of drawing) and `diagram` (`DIAGRAM_COLLECTION`, `singleFile()` — the PNG of a canvas they did draw). `picture()` prefers the drawn one and falls back to the upload.
- `Submission` — `submission_sources` (`Submission::SOURCES_COLLECTION`), the gathered material behind a CATI submission, served by `SubmissionSourceController::show()`.
- `FlowspecChat` — `flowspec_attachments` (`FlowspecChat::ATTACHMENTS_COLLECTION`), files a person attached as context to an Especialista em Integrações conversation. Never served back to a browser at all: these are read for text or handed to the model as native attachments, and are deleted with the attachment row.

No model has more than one conversion, and nothing uses a `->image()` accessor. **`Solution` holds no media at all any more, and `Solution`/`Company` logos are NOT MediaLibrary** — `logo_path` is a plain string column, uploaded via `$request->file('logo')->store('{solution,company}-logos', 'public')` directly in `SolutionController`/`CompanyController`, a deliberately simpler mechanism since a logo needs no conversions/metadata.

Avatar/logo uploads (the six Person/Solution/Company Store+Update requests) all share `['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']`, and `avatar-upload.js` mirrors that list client-side so a doomed file never gets an encouraging preview — **keep the two in step**. `accept="image/*"` on the input is only a picker hint and enforces nothing. SVG is intentionally absent: Laravel 13's bare `image` rule rejects it unless written `image:allow_svg` (so it never actually worked, even while `mimes:` still listed it), and an SVG served from the public disk executes its own scripts when opened directly by URL. Documentation media is a different rule (`file`, not `image`) and **does** accept SVG.

The last two are read by the shared `App\Support\Context` extractors
(`SourceTextExtractor` + `SensitiveTextScanner`), which partition an upload into
text to inline vs. a PDF/image the model reads natively — the one piece of this
that two feature areas genuinely share. `App\Support\Context\NativeAttachmentType`
is the single place that decides which of the two a given file is.

Never register a new collection/conversion without checking the 7 above first. `MediaController::show()` authorizes by COLLECTION NAME alone, against `Documentable::DOCS_COLLECTION` — keep it comparing against the constant, never a `'docs'` literal.

### SSRF surface — documentation editor's "paste image URL"
`EditsDocumentation::storeDocumentationMedia()` (used by `NotebookPageController`) has two upload paths: a multipart `file`, or a `url` the SERVER downloads via Spatie's `addMediaFromUrl()` (Editor.js's Image plugin "paste a URL" flow). `starts_with:http://,https://` — Spatie's own internal check — is no guard at all: it accepts a cloud metadata endpoint or an internal admin panel just as happily. `App\Rules\PublicUrl` is what closes that, validating the RESOLVED IP via `FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE`, and `UploadDocumentationMediaRequest` carries it. Keep it there: the gate reaching this is `update` on a page's caderno, which since the Writer role landed means an **editor**, not only an admin. It resolves DNS at validation time, so it does **not** close a DNS-rebinding race (attacker's DNS answers public at validation, private moments later at fetch time) — an accepted, documented residual, not something this rule claims to solve.
`EditsDocumentation::storeDocumentationMedia()` (used by `NotebookPageController`) has two upload paths: a multipart `file`, or a `url` the SERVER downloads via Spatie's `addMediaFromUrl()` (Editor.js's Image plugin "paste a URL" flow). `UploadDocumentationMediaRequest` only validates `starts_with:http://,https://` — same as Spatie's own internal check — with **no private/loopback/link-local guard**, so without `App\Rules\PublicUrl` (validates the resolved IP via `FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE`) an admin could make the server fetch an internal-only URL (cloud metadata endpoint, internal admin panel, etc.) — exploitability is WRITE-scoped (`update` on a page's caderno reaches this), which since the Writer role landed means an **editor** and not only an admin: the same gate widened, and the guard is what did not move with it. Still real, and now reachable by more accounts than the note originally claimed. The guard resolves DNS at validation time, so it does **not** close a DNS-rebinding race (attacker's DNS answers public at validation, private moments later at fetch time) — accepted as a documented residual risk, not something this rule claims to solve.
