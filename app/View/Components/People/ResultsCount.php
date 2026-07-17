<?php

namespace App\View\Components\People;

use App\Models\Person;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Live counter ("N people") next to the catalog title — own slot so it
 * can be updated alongside the list on each filter/search without
 * reloading the page, even though it's outside the list's container in the DOM.
 */
class ResultsCount extends Component
{
    use Renderable;

    public const DOM_ID = 'people-count-slot';

    /** @param  array<string, mixed>  $filters */
    public function __construct(public array $filters = []) {}

    /** @param  array<string, mixed>  $filters */
    public static function slot(array $filters = []): array
    {
        return (new static($filters))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.people.results-count', [
            'domId' => self::DOM_ID,
            'count' => Person::query()->filter($this->filters)->count(),
        ]);
    }
}
