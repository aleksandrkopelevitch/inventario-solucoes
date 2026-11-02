<?php

namespace App\View\Components\People;

use App\Enums\PersonSolutionRole;
use App\Models\Company;
use App\View\Components\Concerns\FilterChipsSlot;

/** Chips for the active filters in the people catalog, each individually removable. */
class FilterChips extends FilterChipsSlot
{
    public const DOM_ID = 'people-chips-slot';

    protected function formId(): string
    {
        return 'people-filter-form';
    }

    protected function indexUrl(): string
    {
        return route('people.index');
    }

    protected function chips(): array
    {
        $f = $this->filters;
        $chips = [];

        if (filled($f['search'] ?? null)) {
            $chips[] = $this->chip('filter[search]', 'Busca', $f['search'], 'slate', 'magnifying-glass');
        }

        if (filled($f['company'] ?? null)) {
            $chips[] = $this->chip('filter[company]', 'Empresa', Company::find($f['company'])?->name ?? $f['company'], 'blue', 'building-office-2');
        }

        // tryFrom: the value comes from the query string, and `from()` turned
        // an unknown one into a 500 on the whole index.
        if (filled($f['role'] ?? null)) {
            $chips[] = $this->chip('filter[role]', 'Papel', PersonSolutionRole::tryFrom($f['role'])?->label() ?? $f['role'], 'amber', 'briefcase');
        }

        return $chips;
    }
}
