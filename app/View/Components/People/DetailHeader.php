<?php

namespace App\View\Components\People;

use App\Models\Company;
use App\Models\Person;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Header of the person's detail page (briefing 9.5). Extracted as its own
 * component so that editing the person from their own detail page (via
 * side panel) also updates what's on screen.
 *
 * Renderable as an updatable slot: `DetailHeader::slot($person)`.
 */
class DetailHeader extends Component
{
    use Renderable;

    public const DOM_ID = 'person-detail-header-slot';

    public function __construct(public Person $person) {}

    public static function slot(Person $person): array
    {
        return (new static($person))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        $this->person->loadMissing(['company:id,name,slug', 'contacts']);

        $canEdit = auth()->user()?->can('update', $this->person) ?? false;

        return view('components.people.detail-header', [
            'domId'   => self::DOM_ID,
            'canEdit' => $canEdit,
            // Options for the header's inline company editor — a viewer never
            // renders that select, so don't pay for the query.
            'companies' => $canEdit
                ? Company::orderBy('name')->get(['id', 'name'])
                : collect(),
        ]);
    }
}
