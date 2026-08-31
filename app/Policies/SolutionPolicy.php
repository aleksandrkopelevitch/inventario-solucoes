<?php

namespace App\Policies;

use App\Models\Solution;
use App\Models\User;

class SolutionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Solution $solution): bool
    {
        return true;
    }

    /** Creation and editing need write access (admin or editor). */
    public function create(User $user): bool
    {
        return $user->role->canWrite();
    }

    public function update(User $user, Solution $solution): bool
    {
        return $user->role->canWrite();
    }
}
