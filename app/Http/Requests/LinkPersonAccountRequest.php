<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Attaches an account that already EXISTS to a person — the other half of the
 * accounts list, and the reason that list has to keep existing.
 *
 * `admin@leomadeiras.com.br` comes from `DatabaseSeeder` and has no Person; the
 * imported catalog has people who were given accounts before the two tables
 * knew about each other. Neither can be fixed by granting (that creates an
 * account), so this exists to say "these two are the same human".
 */
class LinkPersonAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', User::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
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

                if ($this->route('person')->user_id !== null) {
                    $validator->errors()->add('user_id', 'Esta pessoa já tem uma conta vinculada.');

                    return;
                }

                // `people.user_id` is unique, so the database would refuse this
                // too — but as a 500, naming a constraint. The person who owns
                // the other row is what the reader needs to hear.
                $owner = $this->account()?->person;

                if ($owner) {
                    $validator->errors()->add(
                        'user_id',
                        "Esta conta já está vinculada a \"{$owner->name}\".",
                    );
                }
            },
        ];
    }

    public function account(): ?User
    {
        return User::with('person')->find($this->validated('user_id'));
    }
}
