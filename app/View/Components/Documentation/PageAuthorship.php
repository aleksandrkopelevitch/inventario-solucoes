<?php

namespace App\View\Components\Documentation;

use App\Models\DocumentationPage;
use App\Support\Documentation\Authorship;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Who created the page and who saved it last, in the reading screen's right
 * rail — a fact ABOUT the page, which is what that rail holds (the top bar is
 * for acting on it). An updatable slot (`page-authorship-slot`) because the
 * editor's save returns it: after somebody saves, "atualizada há 3 dias" by
 * somebody else would be wrong on their own screen.
 *
 * The two users are loaded here with `loadMissing()` rather than by the
 * caller, since this is the one place that reads them and strict mode would
 * turn a forgotten eager load into a 500.
 */
class PageAuthorship extends Component
{
    use Renderable;

    public const DOM_ID = 'page-authorship-slot';

    public function __construct(public DocumentationPage $page) {}

    public static function slot(DocumentationPage $page): array
    {
        return (new static($page))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        $this->page->loadMissing(['creator:id,name', 'updater:id,name']);

        return view('components.documentation.page-authorship', [
            'domId'   => self::DOM_ID,
            'created' => Authorship::of($this->page->creator, $this->page->created_at),
            // A page nobody has saved since it was created has one event, not
            // two: repeating it as "atualizada" would read as a second edit.
            'updated' => $this->page->updated_at?->gt($this->page->created_at)
                ? Authorship::of($this->page->updater, $this->page->updated_at)
                : null,
        ]);
    }
}
