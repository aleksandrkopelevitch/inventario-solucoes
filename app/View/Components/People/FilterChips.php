<?php

namespace App\View\Components\People;

use App\Enums\PersonSolutionRole;
use App\Models\Company;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Chips for the active filters in the people catalog, each individually
 * removable. Own slot (not part of the list) to update alongside each
 * filter/search.
 */
class FilterChips extends Component
{
    use Renderable;

    public const DOM_ID = 'people-chips-slot';

    /** @param  array<string, mixed>  $filters */
    public function __construct(public array $filters = []) {}

    /** @param  array<string, mixed>  $filters */
    public static function slot(array $filters = []): array
    {
        return (new static($filters))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.people.filter-chips', [
            'domId' => self::DOM_ID,
            'chips' => $this->chips(),
        ]);
    }

    /** @return array<int, array{field: string, label: ?string, value: string}> */
    private function chips(): array
    {
        $f = $this->filters;
        $chips = [];

        if (filled($f['search'] ?? null)) {
            $chips[] = ['field' => 'filter[search]', 'label' => 'Busca', 'value' => $f['search']];
        }

        if (filled($f['company'] ?? null)) {
            $chips[] = ['field' => 'filter[company]', 'label' => 'Empresa', 'value' => Company::find($f['company'])?->name ?? $f['company']];
        }

        if (filled($f['role'] ?? null)) {
            $chips[] = ['field' => 'filter[role]', 'label' => 'Papel', 'value' => PersonSolutionRole::from($f['role'])->label()];
        }

        return $chips;
    }
}
