<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Inviting/listing user accounts is admin-only — same rule as
 * `AttributeOptionPolicy::manage`. There is no self-registration in this
 * app: every account is created here, by an admin, and the invited person
 * sets their own password through the existing password-reset flow.
 */
class UserPolicy
{
    public function manage(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }
}
