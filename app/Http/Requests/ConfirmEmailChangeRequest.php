<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The link mailed to a NEW address when somebody changes their e-mail on the
 * profile. Until it is opened, the account keeps the old address.
 *
 * The change used to apply on save, with nothing proving the person owned the
 * new mailbox. The e-mail is how an account is found — by an admin's "Conceder
 * acesso", by SSO's first visit — so a member could set theirs to a
 * colleague's address and be handed that colleague's identity later.
 *
 * Valid only with its signature, only for the signed-in account it was made
 * for, and only while that account still has the address it was requested
 * from (`from`): a newer change, or an admin's, kills the older links.
 */
class ConfirmEmailChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->hasValidSignature()
            && (int) $this->query('user') === (int) $this->user()?->id;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'from'  => ['required', 'string'],
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

                $user = $this->user();

                if (! hash_equals(self::addressFingerprint($user->email), (string) $this->query('from'))) {
                    $validator->errors()->add('email', 'Este link já não vale: o e-mail da conta mudou desde que ele foi enviado.');

                    return;
                }

                $taken = User::withTrashed()
                    ->whereFoldedIs('email', $this->query('email'))
                    ->whereKeyNot($user->id)
                    ->exists();

                if ($taken) {
                    $validator->errors()->add('email', 'Este e-mail já é usado por outra conta.');
                }
            },
        ];
    }

    /** What `from` carries: the address the change was requested from. */
    public static function addressFingerprint(string $email): string
    {
        return hash('sha256', mb_strtolower($email));
    }
}
