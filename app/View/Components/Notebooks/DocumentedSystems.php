<?php

namespace App\View\Components\Notebooks;

use App\Models\Notebook;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * "Esse caderno contempla o(s) sistema(s): A, B, C" — the sentence that opens
 * the documentation reader's right rail, above the page's own headings.
 *
 * It replaced an icon in the top bar. Which systems a caderno documents is a
 * FACT about what you are reading, not an action to perform on it, and a
 * squares glyph next to Salvar said neither: you had to open a dropdown to
 * learn something the screen could simply state. Reading it costs nothing now;
 * changing it is one click on the sentence, which opens the same popover
 * (x-notebooks.linked-solutions) the icon used to.
 *
 * Its own slot for the same reason LinkedSolutions is one — the popover writes
 * through `NotebookController::syncSolutions()`, and the sentence has to agree
 * with what was just saved without a page load. The two are returned together;
 * this one wraps the other, so a swap of both is idempotent.
 */
class DocumentedSystems extends Component
{
    use Renderable;

    public const DOM_ID = 'notebook-documented-systems-slot';

    public function __construct(public Notebook $notebook) {}

    public static function slot(Notebook $notebook): array
    {
        return (new static($notebook))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.notebooks.documented-systems', [
            'domId'    => self::DOM_ID,
            'notebook' => $this->notebook,
            'linked'   => $this->notebook->solutions()->orderBy('name')->get(['solutions.id', 'solutions.name']),
        ]);
    }
}
