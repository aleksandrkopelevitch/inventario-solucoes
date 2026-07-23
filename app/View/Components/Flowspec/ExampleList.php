<?php

namespace App\View\Components\Flowspec;

use App\Enums\FlowspecTag;
use App\Models\FlowspecExample;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * List + creation form for the flowSpec reference base (F8), inside the admin
 * "Gerenciar referências" modal (FlowspecExampleController). Renderable as a
 * single updatable slot, re-rendered after every create/update/delete so the
 * create form also resets (same pattern as AttributeOptions\GroupList).
 */
class ExampleList extends Component
{
    use Renderable;

    public const DOM_ID = 'flowspec-example-list-slot';

    public static function slot(): array
    {
        return (new static)->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.flowspec.example-list', [
            'domId'    => self::DOM_ID,
            'examples' => FlowspecExample::query()->orderBy('name')->get(),
            'tags'     => FlowspecTag::cases(),
        ]);
    }
}
