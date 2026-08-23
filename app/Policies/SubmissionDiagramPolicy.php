<?php

namespace App\Policies;

use App\Models\Submission;
use App\Models\SubmissionDiagram;
use App\Models\User;

/**
 * A drawing has no permissions of its own — it belongs to the submission
 * being argued about, and whoever may edit that may edit its diagrams.
 *
 * It exists as its own policy rather than being folded into
 * `SubmissionPolicy` because the chain requests authorize against whatever
 * `ChainCanvas` the route bound (`Concerns\AuthorizesChainOwner`), and that is
 * a `SubmissionDiagram`. Without this, `can('update', $diagram)` would find no
 * policy and answer false — a 403 on the working path, which reads as a
 * broken canvas rather than as a missing file.
 */
class SubmissionDiagramPolicy
{
    public function view(User $user, SubmissionDiagram $diagram): bool
    {
        return $user->can('view', $this->submission($diagram));
    }

    public function update(User $user, SubmissionDiagram $diagram): bool
    {
        return $user->can('update', $this->submission($diagram));
    }

    public function delete(User $user, SubmissionDiagram $diagram): bool
    {
        return $user->can('update', $this->submission($diagram));
    }

    /**
     * Eager-loaded explicitly: a policy is always handed a SINGLE model, and
     * strict mode does not arm the lazy-loading guard on a single-row fetch —
     * so an unloaded `submission` here would query in silence, once per
     * authorization check, in every environment (see AGENTS.md).
     */
    private function submission(SubmissionDiagram $diagram): Submission
    {
        $diagram->loadMissing('submission');

        return $diagram->submission;
    }
}
