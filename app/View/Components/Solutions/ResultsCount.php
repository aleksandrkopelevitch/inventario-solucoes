<?php

namespace App\View\Components\Solutions;

use App\Models\Solution;
use App\View\Components\Concerns\ResultsCountSlot;

/** Live counter ("N soluções") next to the catalog title. */
class ResultsCount extends ResultsCountSlot
{
    public const DOM_ID = 'solutions-count-slot';

    protected function count(): int
    {
        return Solution::query()->filter($this->filters)->count();
    }

    protected function noun(): array
    {
        return ['solução', 'soluções'];
    }
}
