<?php

namespace App\View\Components\Solutions;

use App\Models\Solution;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Live counter ("N solutions") next to the catalog title (F1) —
 * own slot so it can be updated alongside the grid on each filter/search
 * without reloading the page, even though it's outside the grid's container in the DOM.
 */
class ResultsCount extends Component
{
    use Renderable;

    public const DOM_ID = 'solutions-count-slot';

    /** @param  array<string, mixed>  $filters */
    public function __construct(public array $filters = []) {}

    /** @param  array<string, mixed>  $filters */
    public static function slot(array $filters = []): array
    {
        return (new static($filters))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.solutions.results-count', [
            'domId' => self::DOM_ID,
            'count' => Solution::query()->filter($this->filters)->count(),
        ]);
    }
}
