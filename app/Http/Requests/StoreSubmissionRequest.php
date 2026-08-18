<?php

namespace App\Http\Requests;

use App\Models\Submission;
use Illuminate\Foundation\Http\FormRequest;

class StoreSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Submission::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'                => ['required', 'string', 'max:255'],
            'solution_id'         => ['nullable', 'integer', 'exists:solutions,id'],
            'requester_person_id' => ['nullable', 'integer', 'exists:people,id'],
            'committee_date'      => ['nullable', 'date'],
            'ticket_reference'    => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['solution_id', 'requester_person_id', 'committee_date', 'ticket_reference'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => filled($this->input($field)) ? $this->input($field) : null]);
            }
        }
    }
}
