<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Person;
use App\Models\User;

class PersonPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Person $person): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function update(User $user, Person $person): bool
    {
        return $user->role === UserRole::Admin;
    }
}
