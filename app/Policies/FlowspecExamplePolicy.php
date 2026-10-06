<?php

namespace App\Policies;

use App\Enums\AccessModule;
use App\Models\FlowspecExample;
use App\Models\User;

/** Curation of the example corpus (F8) is content: Editors of the Especialista module curate, admins delete. */
class FlowspecExamplePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canEdit(AccessModule::Integrations);
    }

    public function create(User $user): bool
    {
        return $user->canEdit(AccessModule::Integrations);
    }

    public function update(User $user, FlowspecExample $example): bool
    {
        return $user->canEdit(AccessModule::Integrations);
    }

    public function delete(User $user, FlowspecExample $example): bool
    {
        return $user->isAdmin();
    }
}
