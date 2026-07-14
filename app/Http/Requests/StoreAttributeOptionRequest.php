<?php

namespace App\Http\Requests;

use App\Models\AttributeOption;
use App\Rules\Heroicon;
use Illuminate\Foundation\Http\FormRequest;

class StoreAttributeOptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', AttributeOption::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],
            'icon'  => ['nullable', 'string', 'max:64', new Heroicon],
        ];
    }
}
