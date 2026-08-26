<?php

namespace App\View\Components\Documentation;

use App\Services\DocumentationCoverageService;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * List of standalone Groups ("Aninhamentos") in the Documentation hub —
 * outside the search/status filter for solutions and diagrams (they have
 * no % coverage, just a simple listing). Own slot to reflect create/rename/
 * delete without needing to reload the whole page.
 */
class GroupsList extends Component
{
    use Renderable;

    public const DOM_ID = 'documentation-groups-slot';

    public static function slot(): array
    {
        return (new static)->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.documentation.groups-list', [
            'domId'  => self::DOM_ID,
            'groups' => app(DocumentationCoverageService::class)->groupsList(),
        ]);
    }
}
