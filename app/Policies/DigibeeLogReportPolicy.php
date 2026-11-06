<?php

namespace App\Policies;

use App\Enums\AccessModule;
use App\Models\DigibeeLogReport;
use App\Models\User;

/**
 * Reading the Digibee logs through iSol is the Integrations module's, at any
 * level (Leitor or Editor): searching and collecting a report are reads, and a
 * report is visible to everybody who reads the module, like the Especialista's
 * conversations. The session they read WITH is the admin's
 * (`DigibeeMonitorSessionPolicy`).
 */
class DigibeeLogReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canView(AccessModule::Integrations);
    }

    public function view(User $user, DigibeeLogReport $report): bool
    {
        return $user->canView(AccessModule::Integrations);
    }

    /** Searching and collecting: reads, so the module's Reader may. */
    public function create(User $user): bool
    {
        return $user->canView(AccessModule::Integrations);
    }
}
