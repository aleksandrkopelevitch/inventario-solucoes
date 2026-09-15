<?php

namespace App\Services\Documentation;

use App\Models\DocumentationPage;
use App\Models\Notebook;
use App\Services\DocumentationPageService;
use App\Services\DocumentationSearchService;
use App\Support\Documentation\ReaderUrls;
use App\Support\Documentation\SecretText;
use App\Support\GitbookRenderer;
use App\View\Components\Documentation\SearchResults;
use Illuminate\Support\Collection;

/**
 * Everything a READ-ONLY documentation screen needs, for either of the two
 * surfaces that have one.
 *
 * This is the whole of what the magic link and the internal knowledge base
 * (`/docs`) share, and they share it because they are the same screen: the same
 * shell, the same rail, the same palette, the same locks. The two differ in
 * exactly two things — the URLs their links carry (`ReaderUrls`) and who is
 * allowed to arrive — and both of those are the caller's business, not this
 * class's.
 *
 * It exists because the alternative was a second copy of
 * `PublicDocumentationController`'s render path, and a copy is how the rule the
 * user actually asked for ("o MESMO layout") stops being true: the first bug
 * fixed on one surface and not the other is the day they become two screens
 * that merely resemble each other. Nothing here knows about tokens, sessions or
 * roles.
 */
final class DocumentationReader
{
    public function __construct(
        private readonly DocumentationPageService $pages,
        private readonly DocumentationSearchService $search,
        private readonly GitbookRenderer $renderer,
    ) {}

    /**
     * The payload for `documentation.reader` — the view both surfaces render.
     *
     * @return array<string, mixed>
     */
    public function payload(Notebook $notebook, ?DocumentationPage $current, ReaderUrls $urls): array
    {
        $markdown = $current?->documentation;

        return [
            'notebook' => $notebook,
            'title'    => $current?->documentationTitle() ?? $notebook->name,
            // Whether the shell should print the title itself. See
            // `titleIsInContent()`: nearly every imported page opens with an H1
            // repeating its own title, and printing both says it twice.
            'showTitle' => ! $this->titleIsInContent($current),
            // Where a lock posts its code, `__INDEX__` standing in for the
            // ordinal (see NotebookPageController::edit()). Null when the
            // caderno has no page to ask about.
            'secretRevealUrl' => $current ? $urls->secret($current->slug) : null,
            'secretScope'     => $notebook->slug,
            'renderedHtml'    => $this->renderMarkdown($markdown, $notebook, $urls),
            // Raw Markdown for the "Copiar Markdown" button, with media already
            // rewritten for this surface.
            //
            // MASKED, and this is the one place the protection is easiest to
            // lose: the rendered HTML painted its locks, and this textarea
            // would have handed the same reader every plaintext value beside
            // it, in the page source, for a button labelled "copy". They copy
            // `{% secret %}[[SECRET-1]]{% endsecret %}` instead.
            'markdown'  => $this->rewriteAssetUrls(SecretText::mask((string) $markdown), $urls),
            'nav'       => $this->nav($notebook, $current, $urls),
            'searchUrl' => $urls->search(),
            // Its chips, rendered with the page — but ONLY when the corpus is
            // already indexed. A cold index would put the whole build (six
            // seconds, on the largest corpus measured) inside time-to-first-
            // paint of a page nobody has searched yet; the panel ships a
            // placeholder instead and docs-search.js fills it in.
            'searchResults' => $this->warmSearchPanel($notebook),
            // Sub-page navigation. A GitBook parent page is usually empty —
            // its own UI lists the children — so without this an imported
            // section is a dead end for a reader, who has only the side index.
            'childPages' => $current ? $this->childPages($current, $urls) : [],
        ];
    }

    /**
     * Turns the slugs and anchors the search service deals in into URLs for
     * this surface.
     *
     * The service knows no route on purpose — that is what let the same index
     * back both surfaces without a second copy of it.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function resolveSearchUrls(array $payload, ReaderUrls $urls): array
    {
        $payload['results'] = array_map(function (array $result) use ($urls): array {
            $result['url'] = $urls->page($result['slug'])
                . ($result['anchor'] !== null ? '#' . $result['anchor'] : '');

            return $result;
        }, $payload['results']);

        return $payload;
    }

    /**
     * The current page's direct sub-pages, as navigation cards.
     *
     * @return array<int, array{title: string, url: string, hasChildren: bool, hasContent: bool}>
     */
    private function childPages(DocumentationPage $page, ReaderUrls $urls): array
    {
        return $page->children()->get()->map(fn (DocumentationPage $child) => [
            'title'       => $child->title,
            'url'         => $urls->page($child->slug),
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

    /** Renders the Markdown and points its assets at this surface's routes. */
    private function renderMarkdown(?string $markdown, Notebook $notebook, ReaderUrls $urls): string
    {
        // `linkDiagrams: false` on BOTH read-only surfaces, for two different
        // reasons that land in the same place.
        //
        // On the magic link the reader has no account: the canvas is behind
        // auth, so the link would put them on the login screen while telling
        // them the drawing's slug on the way.
        //
        // On `/docs` they do have one, and it is usually a `Reader` — the tier
        // Entra provisions, which may not reach `/diagrams/{slug}` either. The
        // link could be rendered conditionally, and deliberately is not: the
        // knowledge base would then be a screen whose content changes with the
        // reader's tier, which is the property the search index gave up
        // `linkDiagrams` to avoid. Reading is reading. The PICTURE and the name
        // are the documentation; the link is an editing affordance, and anybody
        // who has one already knows where the canvas lives.
        //
        // `pageLinks()` is the same idea for a link between two pages of this
        // caderno, and the reason that link is stored as `page:{slug}` rather
        // than as a URL: the same page has a different address on every
        // surface, so an address written into the Markdown is correct for
        // exactly one audience.
        return $this->rewriteAssetUrls(
            $this->renderer->render(
                $markdown,
                linkDiagrams: false,
                pageLinks: $urls->pageLinks(),
            ),
            $urls,
        );
    }

    /**
     * Points every in-app asset URL at this surface's twin.
     *
     * Two shapes, both emitted by the renderer as the AUTHENTICATED url it
     * would use inside the app, and both rewritten here rather than made
     * surface-aware upstream — that is what keeps `GitbookRenderer` ignorant of
     * magic links and knowledge bases alike:
     *
     * - `/files/{id}` — media embedded in the Markdown.
     * - `/diagrams/{slug}/picture` — the rendered picture of a cited drawing.
     *   Missing this one left every citation on a shared link showing a broken
     *   image, since that route redirects a guest to the login screen.
     *
     * `/docs` needs the rewrite for a reason the magic link did not have: a
     * `Reader` IS signed in, so `files.show` would answer them — with any
     * media in the app, including a caderno nobody published. Scoping the URL
     * to the caderno being read is what makes the served bytes match the page
     * that asked for them.
     */
    private function rewriteAssetUrls(string $content, ReaderUrls $urls): string
    {
        $content = preg_replace_callback(
            '#(src|href)="/files/(\d+)"#',
            fn (array $m) => $m[1] . '="' . $urls->file($m[2]) . '"',
            $content,
        );

        // The host is optional in the pattern: `/files/{id}` reaches the
        // renderer as a root-relative path written into the Markdown, but the
        // picture URL is built with `route()`, which emits an ABSOLUTE url. A
        // pattern anchored at `/` silently matched neither.
        return preg_replace_callback(
            '#(src|href)="(?:https?://[^/"]+)?/diagrams/([^/"]+)/picture"#',
            fn (array $m) => $m[1] . '="' . $urls->diagramPicture($m[2]) . '"',
            $content,
        );
    }

    /**
     * The search panel's slot HTML for an idle (unfiltered) panel, or null when
     * building it would mean indexing the corpus during a page render.
     */
    private function warmSearchPanel(Notebook $notebook): ?string
    {
        if (! $this->search->isWarm($notebook)) {
            return null;
        }

        return SearchResults::slot($this->search->search($notebook, ''))['content'];
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
    private function nav(Notebook $notebook, ?DocumentationPage $current, ReaderUrls $urls): Collection
    {
        return $this->pages->navRows($notebook, $current)->map(fn (array $row) => [
            'label' => $row['page']->title,
            // Depth so the index indents a subpage instead of listing it as a
            // peer of the page it belongs to (see the layout).
            'depth'       => $row['depth'],
            'url'         => $urls->page($row['page']->slug),
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
