<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\DocumentationGroup;
use App\Models\User;

class DocumentationGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, DocumentationGroup $group): bool
    {
        return true;
    }

    /** Criação, edição e páginas restritas a administradores (mesma regra de SolutionPolicy). */
    public function create(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function update(User $user, DocumentationGroup $group): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function delete(User $user, DocumentationGroup $group): bool
    {
        return $user->role === UserRole::Admin;
    }
}
