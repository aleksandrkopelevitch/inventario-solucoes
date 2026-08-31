<?php

namespace App\Policies;

use App\Models\FlowspecExample;
use App\Models\User;

/** Curation of the example corpus (F8) is content: writers curate, admins delete. */
class FlowspecExamplePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->canWrite();
    }

    public function create(User $user): bool
    {
        return $user->role->canWrite();
    }

    public function update(User $user, FlowspecExample $example): bool
    {
        return $user->role->canWrite();
    }

    public function delete(User $user, FlowspecExample $example): bool
    {
        return $user->role->canDelete();
    }
}
