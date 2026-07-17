<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Integration;
use App\Models\User;

class IntegrationPolicy
{
    /** Any authenticated user can browse the integration catalog. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Integration $integration): bool
    {
        return true;
    }

    /** Creation and editing restricted to administrators (section 9.3). */
    public function create(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function update(User $user, Integration $integration): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function delete(User $user, Integration $integration): bool
    {
        return $user->role === UserRole::Admin;
    }
}
