<?php

namespace App\View\Components\Submissions;

use App\Models\Submission;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Live counter next to the list title — its own slot, since it sits outside
 * the list's container in the DOM and must update in lockstep with it.
 */
class ResultsCount extends Component
{
    use Renderable;

    public const DOM_ID = 'submissions-count-slot';

    /** @param  array<string, mixed>  $filters */
    public function __construct(public array $filters = []) {}

    /** @param  array<string, mixed>  $filters */
    public static function slot(array $filters = []): array
    {
        return (new static($filters))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.submissions.results-count', [
            'domId' => self::DOM_ID,
            // Same local scope the list uses — a new filter field is added in
            // one place, not two.
            'count' => Submission::query()->filter($this->filters)->count(),
        ]);
    }
}
