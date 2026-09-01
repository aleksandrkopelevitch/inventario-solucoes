<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Switches an account off, from the accounts roster.
 *
 * The roster is where this has to be possible, because an account does not need
 * a Person: revoking lived only on a person's Acesso card, so an orphan —
 * `admin@leomadeiras.com.br` among them — could have its ROLE changed there and
 * could not be switched off anywhere at all.
 *
 * One refusal, and it is the same one `UpdateUserRoleRequest` carries for the
 * same reason: **nobody revokes their own account.** It is also, on its own,
 * what keeps at least one admin able to log in — revoking requires an admin
 * asking about somebody else, which means two accounts with the panel exist, so
 * one always survives. No "last admin" guard is needed here either, and adding
 * one would be dead code (see UpdateUserRoleRequest).
 */
class RevokeUserAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', User::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->user()->is($this->route('user'))) {
                    $validator->errors()->add(
                        'user',
                        'Você não pode remover o seu próprio acesso — peça a outro administrador.',
                    );
                }
            },
        ];
    }
}
