<?php

namespace App\View\Components\People;

use App\Models\Person;
use App\View\Components\Concerns\ResultsCountSlot;

/** Live counter ("N pessoas") next to the people catalog title. */
class ResultsCount extends ResultsCountSlot
{
    public const DOM_ID = 'people-count-slot';

    protected function count(): int
    {
        return Person::query()->filter($this->filters)->count();
    }

    protected function noun(): array
    {
        return ['pessoa', 'pessoas'];
    }
}
