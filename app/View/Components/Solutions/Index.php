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

    /**
     * Table columns the catalog is sortable by (`filter[sort]`, e.g.
     * `category` or `-category` for descending — see `sortable-th.blade.php`
     * for the header toggle). Maps each column key to the fully-qualified
     * column it actually orders by; `vendor` lives on `companies`, so it only
     * resolves once `query()` below joins it in under that alias.
     */
    private const SORTS = [
        'name'        => 'solutions.name',
        'category'    => 'solutions.category',
        'status'      => 'solutions.status',
        'environment' => 'solutions.environment',
        'vendor'      => 'vendor.name',
    ];

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
        [$column, $direction] = $this->parseSort($f['sort'] ?? null);

        $query = Solution::query()
            ->filter($f)
            ->with('vendor:id,name,slug,logo_path');

        // `SELECT solutions.*` guards against the join's `companies` columns
        // (id, name, created_at, ...) silently winning a naming collision
        // over the Solution model's own columns.
        if ($column === 'vendor') {
            $query->select('solutions.*')
                ->leftJoin('companies as vendor', 'vendor.id', '=', 'solutions.vendor_company_id');
        }

        return $query
            ->orderBy(self::SORTS[$column], $direction)
            ->orderBy('solutions.name');
    }

    /**
     * Splits `filter[sort]` (e.g. `-vendor`) into a whitelisted column key
     * and direction — an unknown, absent, or malformed (e.g. submitted as an
     * array via `filter[sort][]=`) value always falls back to `name` asc, the
     * same default the table's header icons render as inactive.
     *
     * @return array{0: string, 1: 'asc'|'desc'}
     */
    private function parseSort(mixed $sort): array
    {
        if (! is_string($sort) || $sort === '') {
            $sort = 'name';
        }
        $direction = 'asc';

        if (str_starts_with($sort, '-')) {
            $direction = 'desc';
            $sort = substr($sort, 1);
        }

        if (! array_key_exists($sort, self::SORTS)) {
            return ['name', 'asc'];
        }

        return [$sort, $direction];
    }
}
