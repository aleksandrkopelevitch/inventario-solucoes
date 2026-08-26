<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Diagram;
use App\Models\User;

class DiagramPolicy
{
    /** Any authenticated user can browse the diagram catalog. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Diagram $diagram): bool
    {
        return true;
    }

    /** Creation and editing restricted to administrators. */
    public function create(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function update(User $user, Diagram $diagram): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function delete(User $user, Diagram $diagram): bool
    {
        return $user->role === UserRole::Admin;
    }
}
