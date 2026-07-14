<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'   => ['required', 'string', 'max:255'],
            'email'  => ['required', 'email', Rule::unique('users', 'email')->ignore(auth()->id())],
            'avatar' => ['nullable', 'image', 'max:2048'],
        ];
    }
}
