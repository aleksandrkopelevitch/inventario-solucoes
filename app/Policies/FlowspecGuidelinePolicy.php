<?php

namespace App\Policies;

use App\Enums\AccessModule;
use App\Models\FlowspecGuideline;
use App\Models\User;

/** Curation of the guideline documents (F8) is content: Editors of the Especialista module curate, admins delete. */
class FlowspecGuidelinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canEdit(AccessModule::Integrations);
    }

    public function create(User $user): bool
    {
        return $user->canEdit(AccessModule::Integrations);
    }

    public function update(User $user, FlowspecGuideline $guideline): bool
    {
        return $user->canEdit(AccessModule::Integrations);
    }

    public function delete(User $user, FlowspecGuideline $guideline): bool
    {
        return $user->isAdmin();
    }
}
