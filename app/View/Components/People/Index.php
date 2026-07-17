<?php

namespace App\View\Components\People;

use App\Models\Person;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\Component;

/**
 * List of people (F5), updatable slot `people-index-slot`. Search by
 * name/company/system and filters by company and role.
 */
class Index extends Component
{
    use Renderable;

    public const DOM_ID = 'people-index-slot';

    /** @param  array<string, mixed>  $filters */
    public function __construct(public array $filters = []) {}

    /** @param  array<string, mixed>  $filters */
    public static function slot(array $filters = []): array
    {
        return (new static($filters))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.people.index', [
            'domId'   => self::DOM_ID,
            'people'  => $this->query()->get(),
            'filters' => $this->filters,
        ]);
    }

    private function query(): Builder
    {
        return Person::query()
            ->filter($this->filters)
            ->with('company:id,name,slug,logo_path')
            ->withCount('solutions')
            ->orderBy('name');
    }
}
