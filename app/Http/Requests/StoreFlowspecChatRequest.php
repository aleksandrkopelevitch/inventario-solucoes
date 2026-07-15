<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ParsesFlowspecContextInput;
use App\Models\FlowspecChat;
use Illuminate\Foundation\Http\FormRequest;

class StoreFlowspecChatRequest extends FormRequest
{
    use ParsesFlowspecContextInput;

    public function authorize(): bool
    {
        return $this->user()?->can('create', FlowspecChat::class) ?? false;
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
