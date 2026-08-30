<?php

namespace App\View\Components\Notebooks;

use App\Models\Notebook;
use App\Models\Solution;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\Component;

/**
 * The cadernos catalog, renderable as an updatable slot
 * (`notebooks-index-slot`) — one card per notebook with its page coverage and
 * the solutions it documents.
 *
 * The search deliberately spans three things a person might remember about a
 * caderno: its own name, a page title inside it, and the name of a solution it
 * describes. The last one is what makes "where is the Digibee documentation?"
 * answerable without knowing what the caderno holding it was called.
 */
class Index extends Component
{
    use Renderable;

    public const DOM_ID = 'notebooks-index-slot';

    /** @param  array<string, mixed>  $filters */
    public function __construct(public array $filters = []) {}

    /** @param  array<string, mixed>  $filters */
    public static function slot(array $filters = []): array
    {
        return (new static($filters))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        $search = trim((string) ($this->filters['search'] ?? ''));
        $status = in_array($this->filters['status'] ?? null, ['documented', 'empty', 'shared', 'unlinked'], true)
            ? $this->filters['status']
            : null;

        $notebooks = Notebook::query()
            ->select('id', 'name', 'slug', 'public_token')
            ->withCount([
                'pages',
                'pages as documented_count' => fn (Builder $q) => $q
                    ->whereNotNull('documentation')->where('documentation', '<>', ''),
            ])
            ->with('solutions:id,name,slug')
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereFolded('name', $search)
                ->orWhereHas('pages', fn (Builder $p) => $p->whereFolded('title', $search))
                ->orWhereHas('solutions', fn (Builder $s) => $s->whereFolded('name', $search))))
            ->when($status === 'documented', fn (Builder $q) => $q->whereHas('documentedPages'))
            ->when($status === 'empty', fn (Builder $q) => $q->whereDoesntHave('documentedPages'))
            ->when($status === 'shared', fn (Builder $q) => $q->whereNotNull('public_token'))
            ->when($status === 'unlinked', fn (Builder $q) => $q->whereDoesntHave('solutions'))
            ->orderBy('name')
            ->get();

        return view('components.notebooks.index', [
            'domId'     => self::DOM_ID,
            'notebooks' => $notebooks->map(fn (Notebook $notebook) => [
                'name'       => $notebook->name,
                'url'        => route('notebooks.show', $notebook),
                'panelUrl'   => route('notebooks.panel.edit', $notebook),
                'pages'      => $notebook->pages_count,
                'documented' => $notebook->documented_count,
                'isShared'   => $notebook->public_token !== null,
                // Per row, and against the real model: `update` on
                // NotebookPolicy takes a Notebook, so a `@can('update',
                // Notebook::class)` in the view is a TypeError, not a denial.
                'canEdit'   => auth()->user()?->can('update', $notebook) ?? false,
                'solutions' => $notebook->solutions->map(fn (Solution $solution) => [
                    'name' => $solution->name,
                    'url'  => route('solutions.show', $solution),
                ])->all(),
            ]),
            'hasFilters' => $search !== '' || $status !== null,
        ]);
    }
}
