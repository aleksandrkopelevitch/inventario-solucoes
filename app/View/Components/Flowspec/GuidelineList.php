<?php

namespace App\View\Components\Flowspec;

use App\Models\FlowspecGuideline;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * List + creation form for the guideline documents (F8), inside the admin
 * "Gerenciar diretrizes" modal (FlowspecGuidelineController). Renderable as a
 * single updatable slot, re-rendered after every create/update/delete so the
 * create form also resets (same pattern as Flowspec\ExampleList).
 */
class GuidelineList extends Component
{
    use Renderable;

    public const DOM_ID = 'flowspec-guideline-list-slot';

    public static function slot(): array
    {
        return (new static)->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        $guidelines = FlowspecGuideline::query()->orderBy('title')->get();

        return view('components.flowspec.guideline-list', [
            'domId'            => self::DOM_ID,
            'guidelines'       => $guidelines,
            'totalActiveChars' => $guidelines->where('is_active', true)->sum(fn (FlowspecGuideline $g) => mb_strlen($g->content)),
        ]);
    }
}
