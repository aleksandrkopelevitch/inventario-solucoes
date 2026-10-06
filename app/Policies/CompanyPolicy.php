<?php

namespace App\Policies;

use App\Enums\AccessModule;
use App\Models\Company;
use App\Models\User;

/**
 * Catalog module (`AccessModule::Catalog`): its Reader reads, its Editor
 * creates and changes, None does not see it. Nothing here deletes — there is no delete route.
 */
class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canView(AccessModule::Catalog);
    }

    public function view(User $user, Company $company): bool
    {
        return $user->canView(AccessModule::Catalog);
    }

    public function create(User $user): bool
    {
        return $user->canEdit(AccessModule::Catalog);
    }

    public function update(User $user, Company $company): bool
    {
        return $user->canEdit(AccessModule::Catalog);
    }
}
