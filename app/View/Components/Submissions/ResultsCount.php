<?php

namespace App\View\Components\Submissions;

use App\Models\Submission;
use App\View\Components\Concerns\ResultsCountSlot;

/** Live counter ("N submissões") above the committee's list — on the canvas, not a hero panel. */
class ResultsCount extends ResultsCountSlot
{
    public const DOM_ID = 'submissions-count-slot';

    protected function count(): int
    {
        return Submission::query()->filter($this->filters)->count();
    }

    protected function noun(): array
    {
        return ['submissão', 'submissões'];
    }

    protected function variant(): string
    {
        return 'plain';
    }
}
