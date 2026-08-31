<?php

namespace App\Http\Controllers;

use App\Actions\Documentation\RevealPageSecret;
use App\Contracts\Documentable;
use App\Http\Requests\RevealPageSecretRequest;
use App\Http\Requests\SearchPublicDocumentationRequest;
use App\Models\Diagram;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Services\DocumentationPageService;
use App\Services\DocumentationSearchService;
use App\Support\Documentation\SecretText;
use App\Support\GitbookRenderer;
use App\View\Components\Documentation\SearchResults;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PUBLIC documentation for a caderno ("magic link"), no auth. Access is via an
 * opaque token in the URL (`Notebook::public_token`); from it, shows that
 * notebook's page tree (1..N, GitBook-style) in a dedicated `public-docs`
 * layout (top bar + side index).
 *
 * What is shared is ONE notebook, never a solution's whole documentation. The
 * token used to hang off `Solution`, and moving it here is what keeps the
 * shared surface a thing somebody chose to share: a caderno linked to three
 * solutions is still one link to one body of text, and linking a notebook to a
 * solution never publishes anything.
 *
 * Tokens generated before the swap were carried across verbatim by the
 * migration, so links already in the wild keep resolving.
 *
 * Embedded media (`/files/{id}` in the Markdown) is rewritten to a dedicated
 * public route, validated against this notebook's own pages — the
 * authenticated `files.show` route doesn't serve visitors.
 */
class PublicDocumentationController extends Controller
{
    /** First page of the tree (or none, if the caderno has no page yet). */
    public function notebook(string $token): View
    {
        $notebook = $this->resolve($token);

        return $this->render($notebook, app(DocumentationPageService::class)->firstPage($notebook));
    }

    /**
     * `$slug` is NOT resolved via route-model-binding — a DocumentationPage's
     * slug is only unique WITHIN its notebook (see the composite unique on
     * `documentation_pages`), never globally. Two cadernos can each have a page
     * called "test"; a global `{page:slug}` binding would grab the lowest id
     * (belonging to another caderno) and 404 for the wrong owner. Scoping the
     * query by the Notebook resolved from the token avoids the ambiguity
     * entirely.
     */
    public function page(string $token, string $slug): View
    {
        $notebook = $this->resolve($token);
        $page = $notebook->pages()->where('slug', $slug)->firstOrFail();

        return $this->render($notebook, $page);
    }

    /**
     * The command palette's backing endpoint (`docs-search.js`).
     *
     * Scoped to the token's own caderno and nothing else: the service is handed
     * THIS notebook, so a visitor can never reach another caderno's pages
     * through it, however the query is shaped.
     *
     * The service deals in `slug` + `anchor` and knows no route — turning
     * those into public URLs is this controller's job, which is what keeps the
     * same index usable from an authenticated surface later.
     */
    public function search(SearchPublicDocumentationRequest $request, string $token, DocumentationSearchService $search): JsonResponse
    {
        $notebook = $this->resolve($token);

        $payload = $search->search(
            $notebook,
            (string) ($request->validated('q') ?? ''),
            (array) ($request->validated('filter') ?? []),
        );

        $payload['results'] = array_map(function (array $result) use ($token): array {
            $result['url'] = route('public.docs.page', [$token, $result['slug']])
                . ($result['anchor'] !== null ? '#' . $result['anchor'] : '');

            return $result;
        }, $payload['results']);

        // One slot, not a JSON list the browser turns into HTML: the hits are
        // page text somebody authored, and rendering them through Blade is
        // what escapes it (see the SearchResults docblock). `total` rides
        // along for tests and for anything that only wants the count.
        return response()->json([
            'total'          => $payload['total'],
            'updatableSlots' => [SearchResults::slot($payload)],
        ]);
    }

    /**
     * Reveals ONE protected value of a page in the shared caderno.
     *
     * The token is not the authorization here, and that is the whole point of
     * the feature: it resolves WHICH caderno may be asked about, and the
     * caderno's secret code is what unlocks a value inside it. A visitor is
     * counted by IP (there is no identity on this surface) and gets the same
     * five attempts per twelve hours as a signed-in reader.
     *
     * `$slug` is scoped to this notebook for the same reason `page()` does it:
     * a page slug is unique per caderno, never globally.
     */
    public function revealSecret(
        RevealPageSecretRequest $request,
        string $token,
        string $slug,
        int $index,
        RevealPageSecret $reveal,
    ): JsonResponse {
        $notebook = $this->resolve($token);
        $page = $notebook->pages()->where('slug', $slug)->firstOrFail();

        return response()->json([
            'value' => $reveal->handle(
                $notebook,
                $page,
                $index,
                $request->validated()['code'] ?? null,
                // No user, ever — a signed-in admin who happens to be reading a
                // public link is a visitor here. Recognising them would make
                // the shared page's behaviour depend on who is looking at it,
                // which is exactly what `linkDiagrams: false` avoids upstream.
                null,
                'ip' . $request->ip(),
            ),
        ]);
    }

    public function file(string $token, Media $media): BinaryFileResponse
    {
        $notebook = $this->resolve($token);
        $owner = $media->model;

        $allowed = $media->collection_name === Documentable::DOCS_COLLECTION
            && $owner instanceof DocumentationPage
            && (int) $owner->notebook_id === $notebook->id;

        abort_unless($allowed, 404);

        return response()->file($media->getPath(), [
            'Content-Type'        => $media->mime_type,
            'Content-Disposition' => 'inline; filename="' . addslashes($media->file_name) . '"',
        ]);
    }

    /**
     * The picture of a diagram cited by this caderno.
     *
     * A citation renders the drawing's current PNG, and that image used to be
     * requested from `diagrams.picture.show` — which is behind auth, so every
     * cited diagram on a magic link rendered as a BROKEN image. Withholding the
     * "Abrir diagrama" link was right; letting the picture 302 to the login
     * screen was not the same thing.
     *
     * Authorised by CITATION, not by the diagram: the token grants this caderno,
     * and what this caderno shows is what its pages cite. A diagram nobody cited
     * here is a 404 even with a valid token, so the route can't be walked to
     * enumerate the drawing catalog.
     */
    public function diagramPicture(string $token, Diagram $diagram): StreamedResponse
    {
        $notebook = $this->resolve($token);

        // `\` and `%`/`_` escaped: a slug never contains them today (Str::slug
        // emits neither), but a LIKE pattern built from data is not the place to
        // rely on that staying true.
        //
        // Deliberately NOT `whereFolded()`, which every search in the app uses:
        // this is not a search. It asks whether this caderno cites this exact
        // slug, and it is the whole authorisation for serving the picture — a
        // comparison that ignores case and accents would let `SLUG-A` stand in
        // for `slug-a` and widen what one token reaches.
        $needle = addcslashes('diagram slug="' . $diagram->slug . '"', '\\%_');

        abort_unless(
            $notebook->pages()->where('documentation', 'like', '%' . $needle . '%')->exists(),
            404,
        );

        $media = $diagram->picture();

        abort_if($media === null, 404);

        return response()->stream(function () use ($media) {
            readfile($media->getPath());
        }, 200, [
            'Content-Type'        => $media->mime_type,
            'Content-Disposition' => 'inline; filename="' . addslashes($media->file_name) . '"',
        ]);
    }

    private function resolve(string $token): Notebook
    {
        return Notebook::where('public_token', $token)->firstOrFail();
    }

    private function render(Notebook $notebook, ?DocumentationPage $current): View
    {
        $token = $notebook->public_token;
        $markdown = $current?->documentation;

        return view('public.docs', [
            'notebook' => $notebook,
            'title'    => $current?->documentationTitle() ?? $notebook->name,
            // Whether the shell should print the title itself. See
            // `titleIsInContent()`: nearly every imported page opens with an H1
            // repeating its own title, and printing both says it twice.
            'showTitle' => ! $this->titleIsInContent($current),
            // Where a lock posts its code, `__INDEX__` standing in for the
            // ordinal (see NotebookPageController::edit()). Null when the
            // caderno has no page to ask about.
            'secretRevealUrl' => $current
                ? route('public.docs.secrets', [$token, $current->slug, 'index' => '__INDEX__'])
                : null,
            'secretScope'  => $notebook->slug,
            'renderedHtml' => $this->renderMarkdown($markdown, $token),
            // Raw Markdown for the "Copiar Markdown" button, with media already
            // rewritten to the public routes (the internal /files/{id} does
            // not resolve for visitors accessing only via the public link).
            //
            // MASKED, and this is the one place the protection is easiest to
            // lose: the rendered HTML painted its locks, and this textarea
            // would have handed the same visitor every plaintext value beside
            // it, in the page source, for a button labelled "copy". A visitor
            // copies `{% secret %}[[SECRET-1]]{% endsecret %}` instead.
            'markdown' => $this->rewriteFileUrls(SecretText::mask((string) $markdown), $token),
            'nav'      => $this->nav($notebook, $current, $token),
            // Endpoint behind the search panel above the documentation.
            'searchUrl' => route('public.docs.search', $token),
            // Its chips, rendered with the page — but ONLY when the corpus is
            // already indexed. A cold index would put the whole build (six
            // seconds, on the largest corpus measured) inside time-to-first-
            // paint of a page nobody has searched yet; the panel ships a
            // placeholder instead and docs-search.js fills it in.
            'searchResults' => $this->warmSearchPanel($notebook),
            // Sub-page navigation. A GitBook parent page is usually empty —
            // its own UI lists the children — so without this an imported
            // section is a dead end for a visitor, who has only the side index.
            'childPages' => $current ? $this->childPages($current, $token) : [],
        ]);
    }

    /**
     * The current page's direct sub-pages, as navigation cards.
     *
     * @return array<int, array{title: string, url: string, hasChildren: bool, hasContent: bool}>
     */
    private function childPages(DocumentationPage $page, string $token): array
    {
        return $page->children()->get()->map(fn (DocumentationPage $child) => [
            'title'       => $child->title,
            'url'         => route('public.docs.page', [$token, $child->slug]),
            'hasChildren' => $child->children()->exists(),
            'hasContent'  => trim((string) $child->documentation) !== '',
        ])->all();
    }

    /**
     * Whether the page's own text already opens with its title as an H1.
     *
     * It nearly always does: GitBook writes the title into the body, so all 133
     * pages of the imported "Dados • BigQuery • GCP" start with `# <título>`.
     * The shell printed its own title above that, and every page said its name
     * twice.
     *
     * The H1 stays in the CONTENT rather than being stripped from it, because
     * three things downstream read the rendered HTML and would quietly change
     * if it went: the heading anchors (`heading-permalink`), the "Nesta página"
     * navigator built from them, and the search index — which already treats an
     * H1 repeating the page title as being the page itself
     * (DocumentationSearchService). Dropping the shell's copy touches none of
     * them.
     */
    private function titleIsInContent(?DocumentationPage $page): bool
    {
        if (! $page) {
            return false;
        }

        // Front matter first — GitBook emits a `---description---` block ahead
        // of the heading on plenty of pages.
        $markdown = preg_replace('/\A---\R.*?\R---\R/s', '', (string) $page->documentation);

        return str_starts_with(ltrim((string) $markdown), '# ' . trim($page->title));
    }

    /** Renders the Markdown and rewrites `/files/{id}` to the public route. */
    private function renderMarkdown(?string $markdown, string $token): string
    {
        // `linkDiagrams: false` — see GitbookRenderer::render(). A visitor on a
        // magic link has no account: the canvas is behind auth, so the link
        // would land them on the login screen while telling them the drawing's
        // slug on the way.
        return $this->rewriteFileUrls(
            app(GitbookRenderer::class)->render($markdown, linkDiagrams: false),
            $token,
        );
    }

    /**
     * Points every in-app asset URL at its token-scoped public twin.
     *
     * Two shapes, both emitted by the renderer as the AUTHENTICATED url it
     * would use inside the app, and both rewritten here rather than made
     * token-aware upstream — that is what keeps `GitbookRenderer` ignorant of
     * magic links:
     *
     * - `/files/{id}` — media embedded in the Markdown.
     * - `/diagrams/{slug}/picture` — the rendered picture of a cited drawing.
     *   Missing this one left every citation on a shared link showing a broken
     *   image, since that route redirects a guest to the login screen.
     */
    private function rewriteFileUrls(string $content, string $token): string
    {
        $content = preg_replace_callback(
            '#(src|href)="/files/(\d+)"#',
            fn (array $m) => $m[1] . '="' . route('public.docs.file', [$token, $m[2]]) . '"',
            $content,
        );

        // The host is optional in the pattern: `/files/{id}` reaches the
        // renderer as a root-relative path written into the Markdown, but the
        // picture URL is built with `route()`, which emits an ABSOLUTE url. A
        // pattern anchored at `/` silently matched neither.
        return preg_replace_callback(
            '#(src|href)="(?:https?://[^/"]+)?/diagrams/([^/"]+)/picture"#',
            fn (array $m) => $m[1] . '="' . route('public.docs.diagram', [$token, $m[2]]) . '"',
            $content,
        );
    }

    /**
     * The search panel's slot HTML for an idle (unfiltered) panel, or null when
     * building it would mean indexing the corpus during a page render.
     */
    private function warmSearchPanel(Notebook $notebook): ?string
    {
        $search = app(DocumentationSearchService::class);

        if (! $search->isWarm($notebook)) {
            return null;
        }

        return SearchResults::slot($search->search($notebook, ''))['content'];
    }

    /**
     * Side index: the caderno's tree, collapsed to the path being read.
     *
     * `navRows()` rather than `tree()`: it carries the open/closed state, which
     * the rail needs server-side. A 133-page caderno listed flat is not an index
     * — it is the reason this changed.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function nav(Notebook $notebook, ?DocumentationPage $current, string $token): Collection
    {
        return app(DocumentationPageService::class)->navRows($notebook, $current)->map(fn (array $row) => [
            'label' => $row['page']->title,
            // Depth so the index indents a subpage instead of listing it as a
            // peer of the page it belongs to (see the layout).
            'depth'       => $row['depth'],
            'url'         => route('public.docs.page', [$token, $row['page']]),
            'active'      => $current?->is($row['page']) ?? false,
            'hasDocs'     => trim((string) $row['page']->documentation) !== '',
            'id'          => $row['id'],
            'parentId'    => $row['parentId'],
            'hasChildren' => $row['hasChildren'],
            'expanded'    => $row['expanded'],
            'visible'     => $row['visible'],
        ]);
    }
}
