<?php

namespace App\Policies;

use App\Enums\UserRole;
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

    /** Criação e edição restritas a administradores (seção 15). */
    public function create(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function update(User $user, Solution $solution): bool
    {
        return $user->role === UserRole::Admin;
    }
}
