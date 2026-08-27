<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Notebook;
use App\Models\User;

class NotebookPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Notebook $notebook): bool
    {
        return true;
    }

    /** Creating a caderno, editing it and writing its pages: admins only (same rule as SolutionPolicy). */
    public function create(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function update(User $user, Notebook $notebook): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function delete(User $user, Notebook $notebook): bool
    {
        return $user->role === UserRole::Admin;
    }
}
