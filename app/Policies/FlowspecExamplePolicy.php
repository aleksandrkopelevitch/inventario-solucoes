<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/** Curation of the example corpus (F8) is restricted to administrators. */
class FlowspecExamplePolicy
{
    public function create(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }
}
