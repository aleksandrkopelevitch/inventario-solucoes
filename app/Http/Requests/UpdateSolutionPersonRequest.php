<?php

namespace App\Http\Requests;

use App\Enums\PersonSolutionRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edits ONE existing person↔solution link, in place from the owners grid on the
 * solution's detail page. Mirror of `UpdatePersonSolutionRequest`, which does
 * the same from the person's side — same `person_solution` pivot, both fields
 * `sometimes` because each inline editor confirms only its own.
 *
 * The grid renders an editor for `person_id` alone: the ROLE is which of the
 * three columns the row sits in, and there's no honest place to put a role
 * picker inside a column already titled with one — re-roling stays on the
 * person's page, where the role is a badge of its own. The rule below is here
 * anyway so this endpoint stays symmetric with its mirror.
 */
class UpdateSolutionPersonRequest extends FormRequest
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
            // Re-points the link at another person. `unique` on the pivot,
            // excluding this row's own person (so re-confirming the same one
            // isn't reported as a duplicate of itself): the picker only offers
            // people who aren't linked yet, but a stale page (or a second tab)
            // could still post one linked since it was rendered — and the
            // pivot's unique index is on (person, solution, role), so a
            // different role would slip a second link past it.
            'person_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:people,id',
                Rule::unique('person_solution', 'person_id')
                    ->where('solution_id', $this->route('solution')->id)
                    ->whereNot('person_id', $this->route('person')->id),
            ],
            'role' => ['sometimes', 'required', Rule::enum(PersonSolutionRole::class)],
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
