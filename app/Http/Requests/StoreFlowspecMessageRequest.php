<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFlowspecMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('chat')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message'     => ['required', 'string', 'max:8000'],
            'solutions'   => ['nullable', 'array'],
            'solutions.*' => ['integer', 'exists:solutions,id'],
        ];
    }
}
