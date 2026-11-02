<?php

namespace App\View\Components\Solutions;

use App\Models\AttributeOption;
use App\Support\CategoryPalette;
use App\View\Components\Concerns\FilterChipsSlot;

/**
 * Chips for the active filters in the catalog (F1), each individually removable.
 * Own slot (not part of the grid) to update alongside each filter/search.
 */
class FilterChips extends FilterChipsSlot
{
    public const DOM_ID = 'solutions-chips-slot';

    protected function formId(): string
    {
        return 'solutions-filter-form';
    }

    protected function indexUrl(): string
    {
        return route('solutions.index');
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
    protected function chips(): array
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
}
