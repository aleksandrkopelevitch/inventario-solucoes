<?php

namespace App\View\Components\Concerns;

use App\Support\CategoryPalette;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * The active-filters row of a catalog index — the footer of x-ui.filter-bar,
 * one removable chip per filter in use plus "Limpar tudo" — as its own
 * updatable slot.
 *
 * Companies, people and solutions each had a copy of this class and of its
 * view, identical except for the DOM id, the filter form and the chips
 * themselves. What differs is now the three abstract members below; the markup
 * is `components.ui.filter-chips`.
 *
 * Kept out of `App\View\Components\Ui` so no tag resolves to this abstract
 * class: a subclass (`x-companies.filter-chips`, …) is the component, and the
 * shared view is only its template — it needs the subclass's data.
 */
abstract class FilterChipsSlot extends Component
{
    use Renderable;

    /** @param  array<string, mixed>  $filters */
    public function __construct(public array $filters = []) {}

    /** @param  array<string, mixed>  $filters */
    public static function slot(array $filters = []): array
    {
        return (new static($filters))->toSlot(static::DOM_ID);
    }

    /** Id of the index's filter form, which the chips clear fields of. */
    abstract protected function formId(): string;

    /** The index the cleared form is re-submitted to. */
    abstract protected function indexUrl(): string;

    /**
     * Each chip wears its dimension's color family + icon, so the row reads as
     * a colorful summary instead of identical pale-green pills.
     *
     * @return array<int, array{field: string, label: ?string, value: string, chip: string, icon: string}>
     */
    abstract protected function chips(): array;

    public function render(): View
    {
        return view('components.ui.filter-chips', [
            'domId'  => static::DOM_ID,
            'chips'  => $this->chips(),
            'formId' => $this->formId(),
            'url'    => $this->indexUrl(),
        ]);
    }

    /**
     * `chip` holds literal soft classes, resolved through CategoryPalette so
     * Tailwind actually emits them.
     *
     * @return array{field: string, label: ?string, value: string, chip: string, icon: string}
     */
    protected function chip(string $field, ?string $label, string $value, string $family, string $icon): array
    {
        return [
            'field' => $field,
            'label' => $label,
            'value' => $value,
            'chip'  => CategoryPalette::chipClassForFamily($family),
            'icon'  => $icon,
        ];
    }
}
