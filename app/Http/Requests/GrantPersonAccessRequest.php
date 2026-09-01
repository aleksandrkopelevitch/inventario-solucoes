<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\Person;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Gives a person an account.
 *
 * Authorized by `UserPolicy::manage` (admin) and NOT by `PersonPolicy::update`,
 * which is the whole reason this is a separate request. An EDITOR may edit this
 * person — their job title, their company, which systems they own — and must not
 * be able to hand out an account, least of all an admin one. The two live on the
 * same page and answer to different rules.
 */
class GrantPersonAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', User::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
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

                /** @var Person $person */
                $person = $this->route('person');

                // An account is addressed by its email — it is the login. A
                // person without one cannot be given access, and saying so here
                // is better than creating an account nobody can sign in to.
                if (blank($person->email)) {
                    $validator->errors()->add(
                        'role',
                        'Cadastre o e-mail desta pessoa antes de conceder acesso — é com ele que ela faz login.',
                    );

                    return;
                }

                if ($person->user_id !== null) {
                    $validator->errors()->add('role', 'Esta pessoa já tem acesso.');

                    return;
                }

                // A LIVE account already using this email belongs to somebody
                // else's row (or to an orphan on the accounts list): linking is
                // the gesture for that, not granting, and creating a second
                // account would hit the unique index anyway. A SOFT-DELETED one
                // is the revoke/re-grant case and is restored by the action.
                $taken = User::where('email', $person->email)
                    ->whereHas('person', fn ($query) => $query->whereKeyNot($person->getKey()))
                    ->exists();

                if ($taken) {
                    $validator->errors()->add(
                        'role',
                        'Já existe uma conta com este e-mail, vinculada a outra pessoa.',
                    );
                }
            },
        ];
    }
}
