<?php

namespace App\Policies;

use App\Enums\AccessModule;
use App\Models\Diagram;
use App\Models\User;

class DiagramPolicy
{
    /** Reading the diagrams: any level but None in the documentation module. */
    public function viewAny(User $user): bool
    {
        return $user->canView(AccessModule::Documentation);
    }

    public function view(User $user, Diagram $diagram): bool
    {
        return $user->canView(AccessModule::Documentation);
    }

    /** Creating and editing: an Editor of the documentation module. DELETING a
     *  drawing stays with the admin — prose elsewhere cites it and survives it. */
    public function create(User $user): bool
    {
        return $user->canEdit(AccessModule::Documentation);
    }

    public function update(User $user, Diagram $diagram): bool
    {
        return $user->canEdit(AccessModule::Documentation);
    }

    public function delete(User $user, Diagram $diagram): bool
    {
        return $user->isAdmin();
    }
}
