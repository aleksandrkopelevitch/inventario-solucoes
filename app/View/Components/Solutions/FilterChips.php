<?php

namespace App\View\Components\Solutions;

use App\Models\AttributeOption;
use App\Support\CategoryPalette;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Chips for the active filters in the catalog (F1), each individually removable.
 * Own slot (not part of the grid) to update alongside each filter/search.
 */
class FilterChips extends Component
{
    use Renderable;

    public const DOM_ID = 'solutions-chips-slot';

    /** @param  array<string, mixed>  $filters */
    public function __construct(public array $filters = []) {}

    /** @param  array<string, mixed>  $filters */
    public static function slot(array $filters = []): array
    {
        return (new static($filters))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.solutions.filter-chips', [
            'domId' => self::DOM_ID,
            'chips' => $this->chips(),
        ]);
    }

    /**
     * Each chip carries the filter dimension's own color family + icon, so the
     * active-filters row reads as a colorful, scannable summary instead of a row
     * of identical pale-green pills. Category uses its REAL family (the same one
     * its cards/tiles use); the other dimensions each get a fixed, distinct
     * family from the harmonic palette. `chip` = literal soft classes (resolved
     * via CategoryPalette so Tailwind actually emits them).
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

        if (filled($f['category'] ?? null)) {
            $chips[] = $this->chip(
                'filter[category]', 'Categoria',
                AttributeOption::labelFor('category', $f['category']) ?? $f['category'],
                CategoryPalette::family($f['category']), CategoryPalette::icon($f['category']),
            );
        }

        if (filled($f['directorate'] ?? null)) {
            $chips[] = $this->chip('filter[directorate]', 'Diretoria', $f['directorate'], 'blue', 'building-office-2');
        }

        if (filled($f['environment'] ?? null)) {
            $chips[] = $this->chip('filter[environment]', 'Hospedagem', AttributeOption::labelFor('environment', $f['environment']) ?? $f['environment'], 'teal', 'cloud');
        }

        if (filled($f['contract'] ?? null)) {
            $chips[] = $this->chip('filter[contract]', 'Contrato', AttributeOption::labelFor('contract_status', $f['contract']) ?? $f['contract'], 'amber', 'document-check');
        }

        if (filled($f['status'] ?? null)) {
            $chips[] = $this->chip('filter[status]', 'Status', AttributeOption::labelFor('status', $f['status']) ?? $f['status'], 'emerald', 'signal');
        }

        if ($f['undocumented'] ?? false) {
            $chips[] = $this->chip('filter[undocumented]', null, 'Sem documentação', 'rose', 'document-minus');
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
