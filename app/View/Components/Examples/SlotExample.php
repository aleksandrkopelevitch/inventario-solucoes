<?php

namespace App\View\Components\Examples;

use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Reference implementation of the updatable-slot pattern. Real feature
 * components (catalog index, coverage widget, etc.) should follow this shape:
 * a `slot()` static that returns `toSlot('<stable-dom-id>')`.
 *
 * Rendered in Blade as: <x-examples.slot-example :count="3" />
 */
class SlotExample extends Component
{
    use Renderable;

    public const DOM_ID = 'slot-example-slot';

    public function __construct(public int $count = 0) {}

    public static function slot(int $count = 0): array
    {
        return (new static($count))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.examples.slot-example');
    }
}
