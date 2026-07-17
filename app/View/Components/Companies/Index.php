<?php

namespace App\View\Components\Companies;

use App\Models\Company;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\Component;

/**
 * Company list (F5), updatable slot `companies-index-slot`. Search by name
 * and filter by type (internal/supplier/partner).
 */
class Index extends Component
{
    use Renderable;

    public const DOM_ID = 'companies-index-slot';

    /** @param  array<string, mixed>  $filters */
    public function __construct(public array $filters = []) {}

    /** @param  array<string, mixed>  $filters */
    public static function slot(array $filters = []): array
    {
        return (new static($filters))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.companies.index', [
            'domId'     => self::DOM_ID,
            'companies' => $this->query()->get(),
            'filters'   => $this->filters,
        ]);
    }

    private function query(): Builder
    {
        return Company::query()
            ->filter($this->filters)
            ->withCount(['people', 'providedSolutions'])
            ->orderBy('name');
    }
}
