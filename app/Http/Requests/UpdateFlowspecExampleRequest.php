<?php

namespace App\Http\Requests;

class UpdateFlowspecExampleRequest extends FlowspecExampleRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('example')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
