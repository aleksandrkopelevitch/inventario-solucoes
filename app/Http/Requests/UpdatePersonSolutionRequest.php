<?php

namespace App\Http\Requests;

use App\Enums\PersonSolutionRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edits ONE existing person↔solution link, in place from the "Sistemas" card on
 * the person's detail page. The row carries two independent editors — the
 * system it points at (the name) and its role (the badge) — so both fields are
 * `sometimes`: each confirm sends only its own field.
 */
class UpdatePersonSolutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('person')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Re-points the link at another system. `unique` on the pivot,
            // excluding this row's own solution (so re-confirming the same one
            // isn't reported as a duplicate of itself): the picker only offers
            // systems that aren't linked yet, but a stale page (or a second
            // tab) could still post one linked since it was rendered, and the
            // pivot's unique index is on (person, solution, role) — a different
            // role would slip a second link to the same system past it.
            'solution_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:solutions,id',
                Rule::unique('person_solution', 'solution_id')
                    ->where('person_id', $this->route('person')->id)
                    ->whereNot('solution_id', $this->route('solution')->id),
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
            'solution_id.unique' => 'Esta pessoa já está vinculada a este sistema.',
        ];
    }
}
