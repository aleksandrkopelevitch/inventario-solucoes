<?php

namespace App\View\Components\Companies;

use App\Enums\CompanyKind;
use App\View\Components\Concerns\FilterChipsSlot;

/** Chips for the active filters in the company catalog, each individually removable. */
class FilterChips extends FilterChipsSlot
{
    public const DOM_ID = 'companies-chips-slot';

    protected function formId(): string
    {
        return 'companies-filter-form';
    }

    protected function indexUrl(): string
    {
        return route('companies.index');
    }

    protected function chips(): array
    {
        $f = $this->filters;
        $chips = [];

        if (filled($f['search'] ?? null)) {
            $chips[] = $this->chip('filter[search]', 'Busca', $f['search'], 'slate', 'magnifying-glass');
        }

        // tryFrom: the value comes from the query string, and `from()` turned
        // an unknown one into a 500 on the whole index.
        if (filled($f['kind'] ?? null)) {
            $chips[] = $this->chip('filter[kind]', 'Tipo', CompanyKind::tryFrom($f['kind'])?->label() ?? $f['kind'], 'teal', 'tag');
        }

        return $chips;
    }
}
