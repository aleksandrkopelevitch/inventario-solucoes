<?php

namespace App\View\Components\Documentation;

use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Vertical tree (GitBook-style, up to `DocumentationPage::MAX_DEPTH` levels
 * deep) for a Solution: its own pages
 * (titled with the SOLUTION's name, manageable — create/rename/move/nest/delete)
 * and, right below, the doc for each Integration it participates in
 * ("Integrações", link-only — consolidating both into a single screen, see
 * NavigatesSolutionDocs). A
 * standalone DocumentationGroup has no integrations, so `$integrations`
 * arrives empty in that case and the section disappears. Purely
 * presentational — the URLs already come ready-made from the controller
 * (`SolutionDocumentationController`/`IntegrationDocumentationController`/
 * `DocumentationGroupPageController`), which are the ones that know the
 * route names for each context.
 *
 * Updatable slot: used after REORDERING or RENESTING a page (the only actions
 * that don't navigate to another screen — creating, renaming or re-filing a
 * page under another container all answer with a url instead).
 */
class PagesNav extends Component
{
    use Renderable;

    public const DOM_ID = 'documentation-pages-nav-slot';

    /**
     * `$pages` arrives FLAT, in reading order, each row carrying its own
     * `depth` (0..MAX_DEPTH-1) — see the view for why the tree isn't rendered
     * recursively.
     *
     * @param  array<int, array{id: int, title: string, depth: int, hasChildren: bool, canNest: bool, canPromote: bool, canAddChild: bool, editUrl: string, renameUrl: string, destroyUrl: string, moveUrl: string, containerUrl: string, destinations: array<string, array<int, array{value: string, label: string}>>, active: bool, hasContent: bool}>  $pages
     * @param  array<int, array{title: string, editUrl: string, active: bool, hasContent: bool}>  $integrations
     */
    public function __construct(
        public array $pages,
        public array $integrations,
        public string $createPageUrl,
        /** What these pages document — the Solution's (or group's) name. */
        public string $title = 'Páginas',
        /** That record's own page, opened by the ↗ beside the title. */
        public ?string $titleUrl = null,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @param  array<int, array<string, mixed>>  $integrations
     */
    public static function slot(
        array $pages,
        array $integrations,
        string $createPageUrl,
        string $title = 'Páginas',
        ?string $titleUrl = null,
    ): array {
        return (new static($pages, $integrations, $createPageUrl, $title, $titleUrl))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.documentation.pages-nav', [
            'domId'         => self::DOM_ID,
            'pages'         => $this->pages,
            'integrations'  => $this->integrations,
            'createPageUrl' => $this->createPageUrl,
            'title'         => $this->title,
            'titleUrl'      => $this->titleUrl,
        ]);
    }
}
