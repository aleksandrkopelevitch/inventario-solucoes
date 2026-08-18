<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSubmissionSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('submission')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // No length cap: a section is prose the committee reads, and truncating
        // it at an arbitrary number is worse than a long answer.
        return ['content' => ['present', 'nullable', 'string']];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('content')) {
            $this->merge(['content' => filled($this->input('content')) ? $this->input('content') : null]);
        }
    }
}
