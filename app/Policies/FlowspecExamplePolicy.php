<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/** Curadoria do corpus de exemplos (F8) é restrita a administradores. */
class FlowspecExamplePolicy
{
    public function create(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }
}
