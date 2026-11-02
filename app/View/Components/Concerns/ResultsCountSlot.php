<?php

namespace App\View\Components\Concerns;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The live "N soluções" counter beside a catalog index's title, as its own
 * updatable slot — it sits outside the list in the DOM but has to change with
 * it on every filter and search.
 *
 * Four classes and four views used to carry this, identical but for the model
 * and the noun. A subclass now says only those; the markup is
 * `components.ui.results-count`. Kept out of `App\View\Components\Ui` so no
 * tag resolves to this abstract class: a subclass is the component, and the
 * shared view is only its template.
 */
abstract class ResultsCountSlot extends Component
{
    use Renderable;

    /** @param  array<string, mixed>  $filters */
    public function __construct(public array $filters = []) {}

    /** @param  array<string, mixed>  $filters */
    public static function slot(array $filters = []): array
    {
        return (new static($filters))->toSlot(static::DOM_ID);
    }

    /** The count, through the same local scope the list uses. */
    abstract protected function count(): int;

    /** @return array{0: string, 1: string} singular and plural, PT-BR */
    abstract protected function noun(): array;

    /** `hero` on an x-ui.hero-panel gradient, `plain` on the canvas. */
    protected function variant(): string
    {
        return 'hero';
    }

    public function render(): View
    {
        $count = $this->count();
        [$singular, $plural] = $this->noun();

        return view('components.ui.results-count', [
            'domId'   => static::DOM_ID,
            'count'   => $count,
            'noun'    => $count === 1 ? $singular : $plural,
            'variant' => $this->variant(),
        ]);
    }
}
