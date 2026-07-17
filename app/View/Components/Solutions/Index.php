<?php

namespace App\View\Components\Solutions;

use App\Models\Solution;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\Component;

/**
 * Solution catalog list (F1), renderable as an updatable slot
 * (`solutions-index-slot`). Search by name/vendor/owner and filters by
 * category, directorate, journey, hosting, contract, status and "no documentation".
 */
class Index extends Component
{
    use Renderable;

    public const DOM_ID = 'solutions-index-slot';

    /** @param  array<string, mixed>  $filters */
    public function __construct(public array $filters = []) {}

    /** @param  array<string, mixed>  $filters */
    public static function slot(array $filters = []): array
    {
        return (new static($filters))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.solutions.index', [
            'domId'     => self::DOM_ID,
            'solutions' => $this->query()->get(),
            'filters'   => $this->filters,
        ]);
    }

    private function query(): Builder
    {
        $f = $this->filters;

        return Solution::query()
            ->filter($f)
            ->with('vendor:id,name,slug,logo_path')
            ->withIntegrationCounts()
            ->withExists('documentedPages as has_docs')
            ->when(
                ($f['sort'] ?? null) === 'status',
                fn (Builder $q) => $q->orderBy('status')->orderBy('name'),
                fn (Builder $q) => $q->orderBy('name'),
            );
    }
}
