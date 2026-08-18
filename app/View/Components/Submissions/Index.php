<?php

namespace App\View\Components\Submissions;

use App\Models\Submission;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/** The submissions list, renderable as an updatable slot. */
class Index extends Component
{
    use Renderable;

    public const DOM_ID = 'submissions-index-slot';

    /** @param  array<string, mixed>  $filters */
    public function __construct(public array $filters = []) {}

    /** @param  array<string, mixed>  $filters */
    public static function slot(array $filters = []): array
    {
        return (new static($filters))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.submissions.index', [
            'domId'       => self::DOM_ID,
            'submissions' => Submission::query()
                ->filter($this->filters)
                // Constrained eager loads — the row shows a name and a count.
                ->with(['solution:id,name,slug', 'requester:id,name'])
                ->withCount(['sections as answered_count' => fn ($q) => $q->whereNotNull('content')->where('content', '!=', '')])
                ->latest('id')
                ->get(),
            'filters' => $this->filters,
        ]);
    }
}
