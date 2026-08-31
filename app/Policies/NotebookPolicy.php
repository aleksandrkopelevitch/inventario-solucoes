<?php

namespace App\Policies;

use App\Models\Notebook;
use App\Models\User;

class NotebookPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Notebook $notebook): bool
    {
        return true;
    }

    /** Creating a caderno and writing its pages: write access (same rule as SolutionPolicy). */
    public function create(User $user): bool
    {
        return $user->role->canWrite();
    }

    public function update(User $user, Notebook $notebook): bool
    {
        return $user->role->canWrite();
    }

    public function delete(User $user, Notebook $notebook): bool
    {
        return $user->role->canDelete();
    }

    /**
     * The caderno as an OBJECT OF ADMINISTRATION rather than a body of text:
     * its public link and its secret code.
     *
     * A separate ability, not `update`, and that split is the whole point of
     * having it. Both of these reach beyond the page an editor is writing:
     * publishing puts the caderno in front of anyone holding a URL, and the
     * secret code is what lets a person read every protected value in it
     * (App\Support\Documentation\SecretText). An editor who could rotate the
     * code, or read it off this panel, would be able to unlock exactly what the
     * feature exists to keep from them.
     */
    public function administer(User $user, Notebook $notebook): bool
    {
        return $user->role->isAdmin();
    }
}
