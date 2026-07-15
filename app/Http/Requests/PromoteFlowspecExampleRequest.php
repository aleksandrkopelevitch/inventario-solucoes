<?php

namespace App\Http\Requests;

use App\Enums\FlowspecTag;
use App\Models\FlowspecExample;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PromoteFlowspecExampleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('update', $this->route('chat')) ?? false)
            && ($this->user()?->can('create', FlowspecExample::class) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:2000'],
            'tags'        => ['required', 'array', 'min:1'],
            'tags.*'      => [Rule::in(FlowspecTag::values())],
        ];
    }
}
