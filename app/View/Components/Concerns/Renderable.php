<?php

namespace App\View\Components\Concerns;

use Illuminate\Support\Facades\Blade;

/**
 * Gives a View Component the ability to render itself into an "updatable slot"
 * payload consumed by resources/js/modules/ajax-slot.js.
 *
 * Controllers return `['updatableSlots' => [SomeComponent::slot()]]` after a
 * mutation; the JS swaps the matching DOM node(s) with the fresh HTML.
 *
 * Usage on a View Component:
 *
 *     use App\View\Components\Concerns\Renderable;
 *
 *     class Index extends Component
 *     {
 *         use Renderable;
 *
 *         public static function slot(): array
 *         {
 *             return (new static)->toSlot('users-index-slot');
 *         }
 *     }
 */
trait Renderable
{
    /**
     * Render this component and wrap it as an updatable-slot payload.
     *
     * The id may be pipe-separated to replace several DOM nodes with the
     * same HTML (e.g. "header-widget-slot|sidebar-widget-slot").
     *
     * @return array{id: string, content: string}
     */
    public function toSlot(string $id): array
    {
        return [
            'id'      => $id,
            'content' => Blade::renderComponent($this),
        ];
    }
}
