<?php

namespace App\Policies;

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

    /** Creation and editing need write access; DELETING a drawing does not
     *  — prose elsewhere cites it and survives it, so that stays with the admin. */
    public function create(User $user): bool
    {
        return $user->role->canWrite();
    }

    public function update(User $user, Diagram $diagram): bool
    {
        return $user->role->canWrite();
    }

    public function delete(User $user, Diagram $diagram): bool
    {
        return $user->role->canDelete();
    }
}
