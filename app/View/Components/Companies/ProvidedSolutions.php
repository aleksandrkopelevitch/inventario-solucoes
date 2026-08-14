<?php

namespace App\View\Components\Companies;

use App\Models\Company;
use App\Models\Solution;
use App\View\Components\Concerns\Renderable;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * "Sistemas fornecidos" card on the company's detail page — every solution
 * whose `vendor_company_id` points here. Attaching and detaching happen in
 * place on the card, so it's a slot the mutations can answer with (it was
 * inlined in `companies/show.blade.php` while it was read-only).
 *
 * Same shape as `Companies\People`: a plain HasMany, so "linking" is writing
 * the solution's `vendor_company_id` and detaching leaves it with no vendor.
 *
 * Renderable as an updatable slot: `ProvidedSolutions::slot($company)`.
 */
class ProvidedSolutions extends Component
{
    use Renderable;

    public const DOM_ID = 'company-solutions-slot';

    public function __construct(public Company $company) {}

    public static function slot(Company $company): array
    {
        return (new static($company))->toSlot(self::DOM_ID);
    }

    public function render(): View
    {
        $this->company->loadMissing('providedSolutions');

        $canEdit = auth()->user()?->can('update', $this->company) ?? false;

        return view('components.companies.provided-solutions', [
            'domId'   => self::DOM_ID,
            'canEdit' => $canEdit,
            // Everything this company doesn't provide yet. One already tied to
            // ANOTHER vendor stays on the list (re-assigning is a legitimate
            // edit), with that vendor in the option label so the move is
            // never made blind.
            'available' => $canEdit
                ? Solution::query()
                    ->whereKeyNot($this->company->providedSolutions->modelKeys())
                    ->with('vendor:id,name')
                    ->orderBy('name')
                    ->get(['id', 'name', 'vendor_company_id'])
                : collect(),
        ]);
    }
}
