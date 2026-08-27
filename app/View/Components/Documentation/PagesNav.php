<?php

namespace App\View\Components\Documentation;

use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Vertical tree (GitBook-style, up to `DocumentationPage::MAX_DEPTH` levels
 * deep) of a container's pages — titled with the Solution's (or standalone
 * group's) own name, manageable: create/rename/move/nest/delete.
 *
 * It used to carry a second list below that one, "Integrações", holding each
 * participating integration's own single-page documentation. That whole second
 * kind of documentation is gone; a drawing is a `Diagram` now and a page points
 * at one, so what remains of it here is a marker on the rows that do.
 *
 * Purely presentational — the URLs already come ready-made from the controller
 * (`NotebookPageController`), which
 * are the ones that know the route names for each context.
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
     * @param  array<int, array{id: int, title: string, depth: int, hasChildren: bool, canNest: bool, canPromote: bool, canAddChild: bool, editUrl: string, renameUrl: string, destroyUrl: string, moveUrl: string, notebookUrl: string, destinations: array<string, array<int, array{value: string, label: string}>>, active: bool, hasContent: bool, hasDiagram: bool}>  $pages
     */
    public function __construct(
        public array $pages,
        public string $createPageUrl,
        /** The caderno's name — what these pages document. */
        public string $title = 'Páginas',
        /**
         * Where the header renames the caderno.
         *
         * It used to be the caderno's own URL, opened by an ↗ beside the title.
         * That link went nowhere useful: `notebooks.show` resolves to the first
         * page of this very caderno, so from inside it the icon pointed at the
         * screen already on display. The name is now the thing you edit here
         * instead.
         */
        public ?string $renameUrl = null,
        public bool $canEdit = false,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $pages
     */
    public static function slot(
        array $pages,
        string $createPageUrl,
        string $title = 'Páginas',
        ?string $renameUrl = null,
        bool $canEdit = false,
    ): array {
        return (new static($pages, $createPageUrl, $title, $renameUrl, $canEdit))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.documentation.pages-nav', [
            'domId'         => self::DOM_ID,
            'pages'         => $this->pages,
            'createPageUrl' => $this->createPageUrl,
            'title'         => $this->title,
            'renameUrl'     => $this->renameUrl,
            'canEdit'       => $this->canEdit,
        ]);
    }
}
