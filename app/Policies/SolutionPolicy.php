<?php

namespace App\Policies;

use App\Enums\AccessModule;
use App\Models\Solution;
use App\Models\User;

class SolutionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canView(AccessModule::Catalog);
    }

    public function view(User $user, Solution $solution): bool
    {
        return $user->canView(AccessModule::Catalog);
    }

    /** Creating and editing: an Editor of the catalog module (admins included). */
    public function create(User $user): bool
    {
        return $user->canEdit(AccessModule::Catalog);
    }

    public function update(User $user, Solution $solution): bool
    {
        return $user->canEdit(AccessModule::Catalog);
    }

    /**
     * Deleting is admin-only, one tier above editing: it is how a record
     * created by mistake leaves the catalog, and what goes with it (the
     * owners' links, the cadernos' links, a block's identity inside every
     * drawing that used it) is not recoverable from the screen that asked.
     */
    public function delete(User $user, Solution $solution): bool
    {
        return $user->isAdmin();
    }

    /**
     * Handing the catalog spreadsheet to people outside the company (its magic
     * link). Admin, like publishing a caderno (`NotebookPolicy::administer`):
     * an editor curates the records, an admin decides who outside reads them.
     */
    public function share(User $user): bool
    {
        return $user->isAdmin();
    }
}
