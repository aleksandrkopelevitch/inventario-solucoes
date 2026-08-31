<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Attaches ONE person to a company from the company's detail page — which is
 * writing that person's `company_id`. The panel's person form still does it
 * from the other side; this attaches one from here.
 *
 * Authorised by `update` on the COMPANY: the person's own record is what
 * changes, but both policies need the same write access, so there's no gap between the two
 * (`PersonPolicy::update` === `CompanyPolicy::update`). Revisit this if either
 * ever grows a narrower rule.
 */
class StoreCompanyPersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('company')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // No "already in this company" check: the picker only offers people
            // who aren't, and re-posting one who is (a stale page, a second tab)
            // writes the same `company_id` it already has — a no-op, not
            // something worth a 422.
            'person_id' => ['required', 'integer', 'exists:people,id'],
        ];
    }
}
