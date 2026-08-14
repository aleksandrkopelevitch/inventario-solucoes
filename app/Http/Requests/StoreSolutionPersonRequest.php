<?php

namespace App\Http\Requests;

use App\Enums\PersonSolutionRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Links ONE person to a solution, with a role, from the solution's detail page
 * (the "owners" grid). Mirror of `StorePersonSolutionRequest`, which does the
 * same from the person's side — same `person_solution` pivot.
 */
class StoreSolutionPersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('solution')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `unique` on the pivot, and deliberately NOT scoped to the role:
            // the table's own unique index is (person, solution, role), so it
            // would happily accept the same person twice under two roles — and
            // the rest of the app assumes ONE link per (person, solution)
            // (`Person::solutions()` would list the solution twice, and
            // `updateExistingPivot` would hit both rows). The picker only offers
            // people who aren't linked yet; this catches a stale page.
            'person_id' => [
                'required',
                'integer',
                'exists:people,id',
                Rule::unique('person_solution', 'person_id')
                    ->where('solution_id', $this->route('solution')->id),
            ],
            'role' => ['required', Rule::enum(PersonSolutionRole::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'person_id.unique' => 'Esta pessoa já está vinculada a este sistema.',
        ];
    }
}
