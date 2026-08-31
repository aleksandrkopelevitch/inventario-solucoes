<?php

namespace App\Policies;

use App\Models\FlowspecGuideline;
use App\Models\User;

/** Curation of the guideline documents (F8) is content: writers curate, admins delete. */
class FlowspecGuidelinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->canWrite();
    }

    public function create(User $user): bool
    {
        return $user->role->canWrite();
    }

    public function update(User $user, FlowspecGuideline $guideline): bool
    {
        return $user->role->canWrite();
    }

    public function delete(User $user, FlowspecGuideline $guideline): bool
    {
        return $user->role->canDelete();
    }
}
