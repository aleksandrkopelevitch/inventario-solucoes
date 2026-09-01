<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\Person;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Invites somebody by e-mail — which since 2026-09-01 creates their CATALOG ROW
 * too (`GrantPersonAccess::invite()`), so an account is never born without a
 * human attached. This is where the one ambiguous case is refused instead.
 */
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

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                // The invite will link the person filed under this e-mail. If
                // they already hold an account — a DIFFERENT one, since
                // `unique:users,email` has already passed — linking would
                // silently orphan it, and `people.user_id` is unique so the
                // database would refuse the second link as a 500 naming a
                // constraint. Neither is an answer, so this is.
                $person = Person::withEmail($this->validated('email'))->first();

                if ($person && $person->user_id !== null) {
                    $validator->errors()->add(
                        'email',
                        "\"{$person->name}\" já está no catálogo com uma conta vinculada — troque o perfil na página da pessoa.",
                    );
                }
            },
        ];
    }
}
