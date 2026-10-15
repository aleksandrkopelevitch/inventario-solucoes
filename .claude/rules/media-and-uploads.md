---
paths:
  - "app/Http/Controllers/MediaController.php"
  - "app/Http/Controllers/NotebookContextDocumentController.php"
  - "app/Http/Controllers/SubmissionSourceController.php"
  - "app/Rules/PublicUrl.php"
  - "app/Support/Context/**"
  - "app/Contracts/Documentable.php"
  - "app/Models/DocumentationPage.php"
  - "app/Support/Documentation/DocumentationMediaResponse.php"
  - "app/Console/Commands/QueueDocumentationWebImagesCommand.php"
  - "resources/js/modules/docs-lightbox.js"
  - "resources/js/modules/avatar-upload.js"
---

## Media (Spatie MediaLibrary)

Only 7 models use `HasMedia`/`InteractsWithMedia`, each collection with its own purpose — there is no generic shared collection/conversion pair to reuse:

- `User` — `avatar` (single-file), with one registered conversion, `thumb` (120×120, `nonQueued()` since the source is tiny). `User::avatarUrl()` falls back to `ui-avatars.com` (an external, third-party image, requested client-side from the `<img src>`) when no avatar was uploaded — a deliberate, low-risk default, not an oversight.
- `Notebook` — `context_documents` (`Notebook::CONTEXT_COLLECTION`), the "Assiste IA" context documents (PDF/image/text), served by `NotebookContextDocumentController` — never through `MediaController`/`files.show`. It lived on `Solution` until cadernos became the container: the chat is about a page, a page always has a notebook, and may have no solution at all.
- `DocumentationPage` — the `docs` collection (`Documentable::DOCS_COLLECTION = 'docs'`): images/files embedded in Markdown documentation, referenced as `/files/{id}` and served by `MediaController`/`files.show` (authenticated), `KnowledgeBaseController::file()` (`/docs`) or `PublicDocumentationController::file()` (magic-link, token-scoped, checked against the notebook's own pages). One queued conversion, `web` (`Documentable::WEB_CONVERSION`) — see "Documentation images have a lighter rendition" below. It is the only `Documentable`; `Diagram` and `SubmissionDiagram` also register a collection named `docs`, but for a different reason — an image PASTED onto the canvas has to be servable at `/files/{id}`, and `MediaController::show()` authorizes by collection name alone, so nothing outside that name can be served at all.
- `Diagram` — `docs` (pasted image nodes, above) plus `diagram` (`Diagram::DIAGRAM_COLLECTION`, `singleFile()`): the canvas rendered to a PNG by the client on every layout save, so the CATI deck can show an architecture without a browser in the loop. Derived, never an input.
- `SubmissionDiagram` — three: `docs` (pasted image nodes, for the same `/files/{id}` reason as `Diagram`), `submission_diagram` (`UPLOAD_COLLECTION`, `singleFile()` — a C4 picture somebody uploaded INSTEAD of drawing) and `diagram` (`DIAGRAM_COLLECTION`, `singleFile()` — the PNG of a canvas they did draw). `picture()` prefers the drawn one and falls back to the upload.
- `Submission` — `submission_sources` (`Submission::SOURCES_COLLECTION`), the gathered material behind a CATI submission, served by `SubmissionSourceController::show()`.
- `FlowspecChat` — `flowspec_attachments` (`FlowspecChat::ATTACHMENTS_COLLECTION`), files a person attached as context to an Especialista em Integrações conversation. Never served back to a browser at all: these are read for text or handed to the model as native attachments, and are deleted with the attachment row.

No model has more than one conversion, and nothing uses a `->image()` accessor. **`Solution` holds no media at all any more, and `Solution`/`Company` logos are NOT MediaLibrary** — `logo_path` is a plain string column, uploaded via `$request->file('logo')->store('{solution,company}-logos', 'public')` directly in `SolutionController`/`CompanyController`, a deliberately simpler mechanism since a logo needs no conversions/metadata.

Avatar/logo uploads (the six Person/Solution/Company Store+Update requests) all share `['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']`, and `avatar-upload.js` mirrors that list client-side so a doomed file never gets an encouraging preview — **keep the two in step**. `accept="image/*"` on the input is only a picker hint and enforces nothing. SVG is intentionally absent: Laravel 13's bare `image` rule rejects it unless written `image:allow_svg` (so it never actually worked, even while `mimes:` still listed it), and an SVG served from the public disk executes its own scripts when opened directly by URL. Documentation media is a different rule (`file`, not `image`) and **does** accept SVG. It is also the largest upload in the app — `max:102400` (100 MB) since 2026-10-15 — which makes three other limits load-bearing: `media-library.max_file_size` must stay at or above it (a test guards that, since a fake upload never trips Spatie's own check), and the droplet's PHP `upload_max_filesize`/`post_max_size` and nginx `client_max_body_size` must allow it too. Those last two live on the server, not in this repo; a request they cut off never reaches the FormRequest, so it fails as a 413 or an empty request instead of the PT-BR message.

The last two are read by the shared `App\Support\Context` extractors
(`SourceTextExtractor` + `SensitiveTextScanner`), which partition an upload into
text to inline vs. a PDF/image the model reads natively — the one piece of this
that two feature areas genuinely share. `App\Support\Context\NativeAttachmentType`
is the single place that decides which of the two a given file is.

Never register a new collection/conversion without checking the 7 above first. `MediaController::show()` authorizes by COLLECTION NAME alone, against `Documentable::DOCS_COLLECTION` — keep it comparing against the constant, never a `'docs'` literal.

### Documentation images have a lighter rendition (since 2026-10-15)

Every PNG/JPEG/WebP uploaded into a page (`DocumentationPage::WEB_IMAGE_TYPES`) gets a WebP copy at most 1600px wide — twice the 768px reading column — made on the queue. GIF is excluded (GD would flatten the animation) and so is SVG. Measured on the GitBook archives before shipping: the 40 heaviest images went from 57.9 MB to 3.4 MB.

Six things about it that are easy to get wrong:

- **The URL never changes.** All three file routes go through `DocumentationMediaResponse`, which picks the file by `Sec-Fetch-Dest`: `image` (an `<img>`: the body, a card, the editor) gets the rendition, and anything else gets the upload. That covers a link to the file, a new tab, a download or a request with no header at all. So the Markdown still says `/files/{id}`, nothing was rewritten, and a PNG attached as a FILE is never handed over re-encoded. Responses carry `Vary: Sec-Fetch-Dest`.
- **`?original=1` forces the upload.** The lightbox (`docs-lightbox.js`) needs it, because its preload is an image request too. It opens on the light copy and swaps the original in when it arrives, showing "Carregando imagem completa…" meanwhile.
- **The rendition is served only once generated, and only while it is SMALLER than the upload.** A small palette PNG can grow in the WebP encoder. Until the queued job runs, the upload is what goes out.
- **`nonOptimized()` is deliberate.** Otherwise the package hands the WebP to `cwebp` whenever that binary is installed, so the server's toolbox would decide the output. Height is capped at 16000 because GD cannot write a WebP past 16383px.
- **The backlog is filled by `documentation:web-images`, on every deploy.** It is not `media-library:regenerate --only-missing`: that dispatches a job for every convertible media and lets each discover there is nothing to do. This command asks the database first, so it queues nothing once everything has a rendition. It also counts, rather than queues, rows whose file is missing on disk. It only DISPATCHES: the workers write the files as www-data. Envoy runs it with `sudo -u www-data` anyway, so a root run can't create the day's log file as root.
- **These routes now answer `If-Modified-Since` with a 304.** Nothing did before, so every reload re-downloaded every image on the page.

Body images also carry `loading="lazy"` now (`GitbookRenderer::deferImages()`, appended after the parse because a body image is raw `<figure><img>` HTML with no node to decorate).

QA trap: the dev queue is `database`. A page seeded through tinker gets its renditions inline only with `config(['media-library.queue_connection_name' => 'sync'])`. Running a worker to drain one conversion would also run whatever else is waiting in the dev `jobs` table.

### SSRF surface — documentation editor's "paste image URL"
`EditsDocumentation::storeDocumentationMedia()` (used by `NotebookPageController`) has two upload paths: a multipart `file`, or a `url` the SERVER downloads via Spatie's `addMediaFromUrl()` (Editor.js's Image plugin "paste a URL" flow). `starts_with:http://,https://` — Spatie's own internal check — is no guard at all: it accepts a cloud metadata endpoint or an internal admin panel just as happily. `App\Rules\PublicUrl` is what closes that, validating the RESOLVED IP via `FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE`, and `UploadDocumentationMediaRequest` carries it. Keep it there: the gate reaching this is `update` on a page's caderno, which since the Writer role landed means an **editor**, not only an admin. It resolves DNS at validation time, so it does **not** close a DNS-rebinding race (attacker's DNS answers public at validation, private moments later at fetch time) — an accepted, documented residual, not something this rule claims to solve.
`EditsDocumentation::storeDocumentationMedia()` (used by `NotebookPageController`) has two upload paths: a multipart `file`, or a `url` the SERVER downloads via Spatie's `addMediaFromUrl()` (Editor.js's Image plugin "paste a URL" flow). `UploadDocumentationMediaRequest` only validates `starts_with:http://,https://` — same as Spatie's own internal check — with **no private/loopback/link-local guard**, so without `App\Rules\PublicUrl` (validates the resolved IP via `FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE`) an admin could make the server fetch an internal-only URL (cloud metadata endpoint, internal admin panel, etc.) — exploitability is WRITE-scoped (`update` on a page's caderno reaches this), which since the Writer role landed means an **editor** and not only an admin: the same gate widened, and the guard is what did not move with it. Still real, and now reachable by more accounts than the note originally claimed. The guard resolves DNS at validation time, so it does **not** close a DNS-rebinding race (attacker's DNS answers public at validation, private moments later at fetch time) — accepted as a documented residual risk, not something this rule claims to solve.
