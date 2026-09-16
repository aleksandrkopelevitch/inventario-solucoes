<?php

namespace App\View\Components\Documentation;

use App\Enums\ArtifactDiagramType;
use App\Models\DocumentationPage;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The rendered diagrams generated from this page, in the reading screen's right
 * rail.
 *
 * The rail is where FACTS about the page go and the top bar is where you ACT on
 * it (see `.claude/rules/css-and-docs-rendering.md`), and these are both: the
 * menu that generates one lives in the bar, the list of what exists lives here.
 * An artifact is a document of its own — it opens in a new tab rather than
 * rendering inline, because it is a full interactive page with its own scripts
 * and its own sandbox.
 *
 * Updatable slot: returned by `NotebookPageArtifactController::store()` and
 * `destroy()`.
 */
class PageArtifacts extends Component
{
    use Renderable;

    public const DOM_ID = 'page-artifacts-slot';

    public function __construct(public DocumentationPage $page, public bool $canEdit = false) {}

    public static function slot(DocumentationPage $page, bool $canEdit = false): array
    {
        return (new static($page, $canEdit))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.documentation.page-artifacts', [
            'artifacts' => $this->artifacts(),
        ]);
    }

    /**
     * @return Collection<int, array{id: int, title: string, type: ?ArtifactDiagramType, url: string, deleteUrl: string}>
     */
    private function artifacts(): Collection
    {
        return $this->page
            ->getMedia(DocumentationPage::ARTIFACTS_COLLECTION)
            ->sortBy('id')
            ->map(fn (Media $media) => [
                'id'    => $media->id,
                'title' => (string) ($media->getCustomProperty('title') ?: $media->name),
                // `tryFrom`, not `from`: a type removed from the enum later
                // must leave the artifact readable rather than 500 the page it
                // is listed on.
                'type'      => ArtifactDiagramType::tryFrom((string) $media->getCustomProperty('type')),
                'url'       => route('notebooks.pages.artifacts.show', [$this->page->notebook, $this->page, $media]),
                'deleteUrl' => route('notebooks.pages.artifacts.destroy', [$this->page->notebook, $this->page, $media]),
            ])
            ->values();
    }
}
