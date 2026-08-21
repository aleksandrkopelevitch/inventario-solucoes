<?php

namespace App\Http\Requests;

use App\Models\Submission;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating a submission — deliberately just two fields.
 *
 * Requester, committee date and ticket reference used to be asked here too,
 * duplicating the header's own inline-edit for no reason: asking for them at
 * creation meant filling them in twice, once blind in a panel and once for
 * real once the record existed. They are still fully editable, just only on
 * the detail header (see `UpdateSubmissionFieldRequest`).
 */
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
            'name'        => ['required', 'string', 'max:255'],
            'solution_id' => ['nullable', 'integer', 'exists:solutions,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('solution_id')) {
            $this->merge(['solution_id' => filled($this->input('solution_id')) ? $this->input('solution_id') : null]);
        }
    }
}
