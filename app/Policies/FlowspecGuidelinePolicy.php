<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\FlowspecGuideline;
use App\Models\User;

/** Curation of the guideline documents (F8) is restricted to administrators. */
class FlowspecGuidelinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function update(User $user, FlowspecGuideline $guideline): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function delete(User $user, FlowspecGuideline $guideline): bool
    {
        return $user->role === UserRole::Admin;
    }
}
