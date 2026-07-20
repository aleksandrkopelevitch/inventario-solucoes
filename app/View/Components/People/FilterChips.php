<?php

namespace App\View\Components\People;

use App\Enums\PersonSolutionRole;
use App\Models\Company;
use App\Support\CategoryPalette;
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

    /**
     * Each chip wears its dimension's color family + icon (same treatment as the
     * solutions catalog) so the active-filters row is a colorful summary, not
     * identical pale-green pills.
     *
     * @return array<int, array{field: string, label: ?string, value: string, chip: string, icon: string}>
     */
    private function chips(): array
    {
        $f = $this->filters;
        $chips = [];

        if (filled($f['search'] ?? null)) {
            $chips[] = $this->chip('filter[search]', 'Busca', $f['search'], 'slate', 'magnifying-glass');
        }

        if (filled($f['company'] ?? null)) {
            $chips[] = $this->chip('filter[company]', 'Empresa', Company::find($f['company'])?->name ?? $f['company'], 'blue', 'building-office-2');
        }

        if (filled($f['role'] ?? null)) {
            $chips[] = $this->chip('filter[role]', 'Papel', PersonSolutionRole::from($f['role'])->label(), 'amber', 'briefcase');
        }

        return $chips;
    }

    /**
     * @return array{field: string, label: ?string, value: string, chip: string, icon: string}
     */
    private function chip(string $field, ?string $label, string $value, string $family, string $icon): array
    {
        return [
            'field' => $field,
            'label' => $label,
            'value' => $value,
            'chip'  => CategoryPalette::chipClassForFamily($family),
            'icon'  => $icon,
        ];
    }
}
