<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', User::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            // Every case the enum knows: the explicit two-value list this
            // replaces was left over from the removed `agent` role, and it is
            // what silently refused `writer` the day it was added.
            'role' => ['required', Rule::enum(UserRole::class)],
        ];
    }
}
