<?php

namespace App\Http\Controllers;

use App\Contracts\Documentable;
use App\Http\Requests\SearchPublicDocumentationRequest;
use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Services\DocumentationPageService;
use App\Services\DocumentationSearchService;
use App\Support\GitbookRenderer;
use App\View\Components\Documentation\SearchResults;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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

    private function resolve(string $token): Notebook
    {
        return Notebook::where('public_token', $token)->firstOrFail();
    }

    private function render(Notebook $notebook, ?DocumentationPage $current): View
    {
        $token = $notebook->public_token;
        $markdown = $current?->documentation;

        return view('public.docs', [
            'notebook'     => $notebook,
            'title'        => $current?->documentationTitle() ?? $notebook->name,
            'eyebrow'      => 'Caderno',
            'renderedHtml' => $this->renderMarkdown($markdown, $token),
            // Raw Markdown for the "Copiar Markdown" button, with media already
            // rewritten to the public routes (the internal /files/{id} does
            // not resolve for visitors accessing only via the public link).
            'markdown' => $this->rewriteFileUrls((string) $markdown, $token),
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

    /** Renders the Markdown and rewrites `/files/{id}` to the public route. */
    private function renderMarkdown(?string $markdown, string $token): string
    {
        return $this->rewriteFileUrls(app(GitbookRenderer::class)->render($markdown), $token);
    }

    /** Rewrites every `src|href="/files/{id}"` to the public `public.docs.file` route. */
    private function rewriteFileUrls(string $content, string $token): string
    {
        return preg_replace_callback(
            '#(src|href)="/files/(\d+)"#',
            fn (array $m) => $m[1] . '="' . route('public.docs.file', [$token, $m[2]]) . '"',
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
     * Side index: every page in the caderno's tree.
     *
     * @return Collection<int, array{label: string, depth: int, url: string, active: bool, hasDocs: bool}>
     */
    private function nav(Notebook $notebook, ?DocumentationPage $current, string $token): Collection
    {
        return app(DocumentationPageService::class)->tree($notebook)->map(fn (array $row) => [
            'label' => $row['page']->title,
            // Depth so the index indents a subpage instead of listing it as a
            // peer of the page it belongs to (see the layout).
            'depth'   => $row['depth'],
            'url'     => route('public.docs.page', [$token, $row['page']]),
            'active'  => $current?->is($row['page']) ?? false,
            'hasDocs' => trim((string) $row['page']->documentation) !== '',
        ]);
    }
}
