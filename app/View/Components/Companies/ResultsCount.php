<?php

namespace App\View\Components\Companies;

use App\Models\Company;
use App\View\Components\Concerns\ResultsCountSlot;

/** Live counter ("N empresas") next to the company catalog title. */
class ResultsCount extends ResultsCountSlot
{
    public const DOM_ID = 'companies-count-slot';

    protected function count(): int
    {
        return Company::query()->filter($this->filters)->count();
    }

    protected function noun(): array
    {
        return ['empresa', 'empresas'];
    }
}
