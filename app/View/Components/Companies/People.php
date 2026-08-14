<?php

namespace App\View\Components\Companies;

use App\Models\Company;
use App\Models\Person;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * "Pessoas" card on the company's detail page — everyone whose
 * `company_id` points here. Attaching and detaching happen in place on the
 * card now, so it needs to be a slot the mutations can hand fresh HTML back to
 * (it was inlined in `companies/show.blade.php` while it was read-only).
 *
 * Mirror of `People\Systems` on the other side of the relation, but a plain
 * HasMany: "linking" a person is writing their `company_id`, so a person can
 * only ever be in one company, and detaching leaves them with none.
 *
 * Renderable as an updatable slot: `People::slot($company)`.
 */
class People extends Component
{
    use Renderable;

    public const DOM_ID = 'company-people-slot';

    public function __construct(public Company $company) {}

    public static function slot(Company $company): array
    {
        return (new static($company))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        $this->company->loadMissing('people');

        $canEdit = auth()->user()?->can('update', $this->company) ?? false;

        return view('components.companies.people', [
            'domId'   => self::DOM_ID,
            'canEdit' => $canEdit,
            // Everyone who isn't here yet. Someone who already belongs to
            // ANOTHER company stays on the list — moving them is a legitimate
            // edit — but their current company rides along in the option label,
            // so the move is never made blind.
            'available' => $canEdit
                ? Person::query()
                    ->whereKeyNot($this->company->people->modelKeys())
                    ->with('company:id,name')
                    ->orderBy('name')
                    ->get(['id', 'name', 'company_id'])
                : collect(),
        ]);
    }
}
