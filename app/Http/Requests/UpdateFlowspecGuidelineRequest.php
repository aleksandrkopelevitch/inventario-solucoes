<?php

namespace App\Http\Requests;

class UpdateFlowspecGuidelineRequest extends FlowspecGuidelineRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('guideline')) ?? false;
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
