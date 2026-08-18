<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Submission;
use App\Models\User;

/**
 * Reading a submission is open to every authenticated user — the point of
 * hosting the committee's material here is that it stops being a `.pptx` in
 * somebody's Downloads.
 *
 * Writing is restricted to the person who opened it (the architect preparing
 * it) and to administrators. Note this is deliberately WIDER than
 * SolutionPolicy, which is admin-only: a submission is authored by whoever is
 * proposing, not by whoever curates the catalog.
 */
class SubmissionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Submission $submission): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Submission $submission): bool
    {
        return $user->role === UserRole::Admin || $submission->created_by_id === $user->id;
    }

    public function delete(User $user, Submission $submission): bool
    {
        return $this->update($user, $submission);
    }
}
