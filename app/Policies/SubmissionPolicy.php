<?php

namespace App\Policies;

use App\Enums\AccessModule;
use App\Models\Submission;
use App\Models\User;

/**
 * Comitê de Arquitetura (`AccessModule::Committee`).
 *
 * Reading a submission is the module's Reader (any level but None) — the
 * point of hosting the committee's material here is that it stops being a
 * `.pptx` in somebody's Downloads.
 *
 * Opening one is the module's Editor. Changing one is still AUTHORED rather
 * than curated: its creator (while an Editor of the module) or an admin — an
 * Editor does not rewrite a colleague's proposal. Deleting is the admin's.
 */
class SubmissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canView(AccessModule::Committee);
    }

    public function view(User $user, Submission $submission): bool
    {
        return $user->canView(AccessModule::Committee);
    }

    public function create(User $user): bool
    {
        return $user->canEdit(AccessModule::Committee);
    }

    public function update(User $user, Submission $submission): bool
    {
        return $user->isAdmin()
            || ($submission->created_by_id === $user->id && $user->canEdit(AccessModule::Committee));
    }

    public function delete(User $user, Submission $submission): bool
    {
        return $user->isAdmin();
    }
}
