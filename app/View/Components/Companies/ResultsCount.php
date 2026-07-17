<?php

namespace App\View\Components\Companies;

use App\Models\Company;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Live counter ("N empresas") next to the catalog title — own slot so it can
 * be updated together with the list on every filter/search without reloading
 * the page, even though it's outside the list container in the DOM.
 */
class ResultsCount extends Component
{
    use Renderable;

    public const DOM_ID = 'companies-count-slot';

    /** @param  array<string, mixed>  $filters */
    public function __construct(public array $filters = []) {}

    /** @param  array<string, mixed>  $filters */
    public static function slot(array $filters = []): array
    {
        return (new static($filters))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.companies.results-count', [
            'domId' => self::DOM_ID,
            'count' => Company::query()->filter($this->filters)->count(),
        ]);
    }
}
