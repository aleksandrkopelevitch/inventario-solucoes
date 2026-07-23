<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\FlowspecExample;
use App\Models\User;

/** Curation of the example corpus (F8) is restricted to administrators. */
class FlowspecExamplePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function update(User $user, FlowspecExample $example): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function delete(User $user, FlowspecExample $example): bool
    {
        return $user->role === UserRole::Admin;
    }
}
