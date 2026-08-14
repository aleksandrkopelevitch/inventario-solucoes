<?php

namespace App\View\Components\People;

use App\Enums\PersonSolutionRole;
use App\Models\Person;
use App\Models\Solution;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * "Sistemas" card on the person's detail page — the `person_solution` links,
 * each with its role. Attaching, detaching and re-roling all happen here now,
 * so it needs to be a slot the mutations can hand fresh HTML back to (it was
 * inlined in `people/show.blade.php` while it was read-only).
 *
 * Renderable as an updatable slot: `Systems::slot($person)`.
 */
class Systems extends Component
{
    use Renderable;

    public const DOM_ID = 'person-systems-slot';

    public function __construct(public Person $person) {}

    public static function slot(Person $person): array
    {
        return (new static($person))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        $this->person->loadMissing('solutions:id,name,slug');

        $canEdit = auth()->user()?->can('update', $this->person) ?? false;

        return view('components.people.systems', [
            'domId'   => self::DOM_ID,
            'canEdit' => $canEdit,
            'roles'   => PersonSolutionRole::cases(),
            // Only what can still be added — offering an already-linked
            // solution would just fail the request's `unique` check.
            'available' => $canEdit
                ? Solution::query()
                    ->whereNotIn('id', $this->person->solutions->modelKeys())
                    ->orderBy('name')
                    ->get(['id', 'name'])
                : collect(),
        ]);
    }
}
