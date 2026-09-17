<?php

namespace App\Policies;

use App\Models\Solution;
use App\Models\User;

class SolutionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->canReadInventory();
    }

    public function view(User $user, Solution $solution): bool
    {
        return $user->role->canReadInventory();
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

    /**
     * Deleting is admin-only, one tier above editing: it is how a record
     * created by mistake leaves the catalog, and what goes with it (the
     * owners' links, the cadernos' links, a block's identity inside every
     * drawing that used it) is not recoverable from the screen that asked.
     */
    public function delete(User $user, Solution $solution): bool
    {
        return $user->role->canDelete();
    }
}
