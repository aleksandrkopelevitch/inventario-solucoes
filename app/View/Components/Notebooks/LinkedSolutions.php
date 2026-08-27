<?php

namespace App\View\Components\Notebooks;

use App\Models\Notebook;
use App\Models\Solution;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The solutions a caderno documents, as an updatable slot
 * (`notebook-solutions-slot`) inside the documentation editor's toolbar
 * dropdown — the one place the link is edited while actually reading the pages
 * it applies to.
 *
 * It is its own slot rather than part of the editor page because linking is a
 * mutation with a visible effect on THIS screen (the chips) and on screens the
 * user isn't looking at (each solution's detail card) — see
 * `NotebookController::syncSolutions()`, which returns both.
 */
class LinkedSolutions extends Component
{
    use Renderable;

    public const DOM_ID = 'notebook-solutions-slot';

    public function __construct(public Notebook $notebook) {}

    public static function slot(Notebook $notebook): array
    {
        return (new static($notebook))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.notebooks.linked-solutions', [
            'domId'    => self::DOM_ID,
            'notebook' => $this->notebook,
            'action'   => route('notebooks.solutions', $this->notebook),
            'all'      => Solution::orderBy('name')->get(['id', 'name']),
            'linked'   => $this->notebook->solutions()->get(['solutions.id', 'solutions.name', 'solutions.slug']),
        ]);
    }
}
