<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Changes one account's role, from the "Usuários" panel.
 *
 * Until this existed a role could only be chosen at INVITE time, so promoting a
 * viewer to editor, or taking the admin off somebody who left the team, meant
 * an `update` straight against the database on the droplet — the one
 * administrative act in the app that had no screen.
 *
 * The one refusal lives in `after()` rather than in a policy, because it is not
 * about who is asking (an admin is asking — that is the policy's job) but about
 * WHICH account is being changed. It is also, on its own, what keeps at least
 * one admin in the system; see the note where the second guard would have gone.
 */
class UpdateUserRoleRequest extends FormRequest
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

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var User $target */
                $target = $this->route('user');

                // Nobody changes their OWN role. A dropdown that can take away
                // the panel you are standing in is a foot-gun with no upside:
                // the demotion would land, the list would come back without the
                // control, and the person would have locked themselves out of
                // user management in one click. Another admin can always do it.
                if ($this->user()->is($target)) {
                    $validator->errors()->add(
                        'role',
                        'Você não pode alterar o seu próprio perfil — peça a outro administrador.',
                    );

                    return;
                }

                // There is deliberately NO second guard for "the last admin".
                //
                // It would be dead code: the rule above already guarantees the
                // invariant. Removing an admin's role requires an admin asking
                // (the policy) about somebody else (the rule above), which means
                // two admins exist — so one always survives the change. There is
                // no other path: an invite only ever ADDS an admin, and nothing
                // else in the app writes `role`.
                //
                // The reachable half of "who protects the last admin" is
                // therefore this: the last one cannot be demoted because the
                // only account allowed to try is their own.
            },
        ];
    }
}
