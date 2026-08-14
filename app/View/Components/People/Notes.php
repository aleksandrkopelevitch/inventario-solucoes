<?php

namespace App\View\Components\People;

use App\Models\Person;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * "Anotações" card on the person's detail page — the `notes` column, which the
 * page never showed at all before it became editable in place.
 *
 * Its own component (not inlined in `people/show.blade.php`) so it's a real
 * slot target: notes can change from here OR from the side panel's form, and
 * both responses have to be able to refresh it.
 *
 * Renderable as an updatable slot: `Notes::slot($person)`.
 */
class Notes extends Component
{
    use Renderable;

    public const DOM_ID = 'person-notes-slot';

    public function __construct(public Person $person) {}

    public static function slot(Person $person): array
    {
        return (new static($person))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        return view('components.people.notes', [
            'domId'   => self::DOM_ID,
            'canEdit' => auth()->user()?->can('update', $this->person) ?? false,
        ]);
    }
}
