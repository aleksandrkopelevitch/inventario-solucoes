<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Attaches ONE solution to a company as its vendor, from the company's detail
 * page — which is writing that solution's `vendor_company_id`. The solution's
 * own header does it from the other side (its vendor chip).
 *
 * Authorised by `update` on the COMPANY: the solution's record is what changes,
 * but both policies are admin-only, so there's no gap between the two
 * (`SolutionPolicy::update` === `CompanyPolicy::update`). Revisit this if
 * either ever grows a narrower rule.
 */
class StoreCompanySolutionRequest extends FormRequest
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
            // No "already this company's" check: the picker only offers systems
            // that aren't, and re-posting one that is writes the same
            // `vendor_company_id` — a no-op, not something worth a 422.
            'solution_id' => ['required', 'integer', 'exists:solutions,id'],
        ];
    }
}
