<?php

namespace App\Policies;

use App\Enums\AccessModule;
use App\Models\Person;
use App\Models\User;

/**
 * Catalog module (`AccessModule::Catalog`): its Reader reads, its Editor
 * creates and changes, None does not see it. Nothing here deletes — there is no delete route.
 */
class PersonPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canView(AccessModule::Catalog);
    }

    public function view(User $user, Person $person): bool
    {
        return $user->canView(AccessModule::Catalog);
    }

    public function create(User $user): bool
    {
        return $user->canEdit(AccessModule::Catalog);
    }

    public function update(User $user, Person $person): bool
    {
        return $user->canEdit(AccessModule::Catalog);
    }
}
