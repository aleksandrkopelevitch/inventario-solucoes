<?php

namespace App\Http\Requests;

use App\Enums\SubmissionStatus;
use Illuminate\Validation\Rule;

class UpdateSubmissionRequest extends StoreSubmissionRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('submission')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'status' => ['required', Rule::enum(SubmissionStatus::class)],
        ];
    }
}
