<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ParsesFlowspecContextInput;
use Illuminate\Foundation\Http\FormRequest;

class StoreFlowspecMessageRequest extends FormRequest
{
    use ParsesFlowspecContextInput;

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
            'message' => ['required', 'string', 'max:8000'],
            ...$this->contextRules(),
        ];
    }
}
