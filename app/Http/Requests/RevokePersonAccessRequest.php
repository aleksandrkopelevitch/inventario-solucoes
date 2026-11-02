<?php

namespace App\Http\Requests;

use App\Models\Person;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "Remover acesso" on a person's card — the second door to revoking an
 * account, and it has to refuse what the roster's door (RevokeUserAccessRequest)
 * refuses: your own account. Without it an admin could soft-delete their own
 * login from their own person page, the last admin included, which only a
 * database edit undoes.
 */
class RevokePersonAccessRequest extends FormRequest
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
                /** @var Person $person */
                $person = $this->route('person');

                if ($person->user_id !== null && $person->user_id === $this->user()->id) {
                    $validator->errors()->add(
                        'user',
                        'Você não pode remover o seu próprio acesso — peça a outro administrador.',
                    );
                }
            },
        ];
    }
}
