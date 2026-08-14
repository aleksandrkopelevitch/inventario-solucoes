<?php

namespace App\Http\Requests;

use App\Enums\PersonSolutionRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Links a person to ONE solution, with a role, from the person's detail page.
 * The panel's chips widget still does bulk linking; this attaches one.
 */
class StorePersonSolutionRequest extends FormRequest
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
            // `unique` on the pivot: the picker only offers solutions that
            // aren't linked yet, but a stale page (or a second tab) could still
            // post one that has been linked since it was rendered, and the
            // pivot carries no unique index to catch it.
            'solution_id' => [
                'required',
                'integer',
                'exists:solutions,id',
                Rule::unique('person_solution', 'solution_id')
                    ->where('person_id', $this->route('person')->id),
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
            'solution_id.unique' => 'Esta pessoa já está vinculada a este sistema.',
        ];
    }
}
