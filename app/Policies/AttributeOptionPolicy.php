<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Managing attribute values (Category, Status, Directorate, etc.) is
 * configuration that cuts across the whole catalog — restricted to
 * administrators, same rule as `SolutionPolicy::create/update`.
 */
class AttributeOptionPolicy
{
    public function manage(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }
}
