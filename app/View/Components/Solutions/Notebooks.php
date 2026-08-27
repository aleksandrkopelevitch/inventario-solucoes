<?php

namespace App\View\Components\Solutions;

use App\Models\Notebook;
use App\Models\Solution;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Documentation column of the solution detail page's "diagramas + documentação"
 * card (the diagrams are the other half — see `Solutions\Diagrams`).
 *
 * It used to list the solution's OWN pages, because a solution owned a page
 * tree. It lists the CADERNOS that document it now, and the difference is the
 * point of the module: the same caderno appears on every solution it describes,
 * so a shared integration is documented once and read from each side of it.
 *
 * A caderno's own page list stays in the caderno — this card names it, counts
 * what is in it and says whether any of it has content. Going one level deeper
 * here would reprint a hundred-page GitBook space inside a summary card.
 *
 * Updatable slot: `Notebooks::slot($solution)` — returned by a page save and by
 * the link/unlink endpoint, so the card stays fresh when the user comes back to
 * this page.
 */
class Notebooks extends Component
{
    use Renderable;

    public const DOM_ID = 'solution-notebooks-slot';

    public function __construct(public Solution $solution) {}

    public static function slot(Solution $solution): array
    {
        return (new static($solution))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.solutions.notebooks', [
            'domId'     => self::DOM_ID,
            'notebooks' => $this->solution->notebooks()
                ->withCount([
                    'pages',
                    // The documented count, not just the total: a caderno with
                    // twelve empty pages covers nothing, and a card that says
                    // "12 páginas" about it is actively misleading.
                    'pages as documented_count' => fn ($q) => $q
                        ->whereNotNull('documentation')->where('documentation', '<>', ''),
                ])
                ->get()
                ->map(fn (Notebook $notebook) => [
                    'name'       => $notebook->name,
                    'url'        => route('notebooks.show', $notebook),
                    'pages'      => $notebook->pages_count,
                    'documented' => $notebook->documented_count,
                    'isShared'   => $notebook->public_token !== null,
                ]),
            'createUrl' => route('notebooks.panel.create'),
        ]);
    }
}
