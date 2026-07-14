<?php

namespace App\View\Components\AttributeOptions;

use App\Enums\AttributeGroup;
use App\Models\AttributeOption;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Lista + form de criação de um grupo de atributo, dentro da área "Gerenciar
 * atributos" (Modal única — ver `AttributeOptionController`). Slot próprio
 * por grupo, para uma mutação num grupo não precisar re-renderizar os outros.
 */
class GroupList extends Component
{
    use Renderable;

    public function __construct(public AttributeGroup $group) {}

    public static function slot(AttributeGroup $group): array
    {
        return (new static($group))->toSlot(self::domId($group));
    }

    public function render(): View
    {
        return view('components.attribute-options.group-list', [
            'domId'   => self::domId($this->group),
            'group'   => $this->group,
            'options' => AttributeOption::options($this->group->value),
        ]);
    }

    /**
     * Deliberately private: `Illuminate\View\Component::data()` auto-exports
     * every PUBLIC method (static or not) as a view variable — a public
     * `domId()` here would collide with and silently overwrite the `$domId`
     * string above with a Closure when rendered via the `<x-...>` tag syntax
     * (as opposed to direct PHP instantiation, which never calls `data()`).
     */
    private static function domId(AttributeGroup $group): string
    {
        return "attribute-options-{$group->value}-list";
    }
}
