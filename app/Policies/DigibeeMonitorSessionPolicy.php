<?php

namespace App\Policies;

use App\Models\User;

/**
 * The server's Digibee session is the admin's, like the MCP tokens and for the
 * same reason: it is not content, it is a credential — a person's whole
 * Digibee role, production deletes included — and deciding whose identity the
 * server reads Digibee with is handing out access.
 */
class DigibeeMonitorSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /** Starting (and driving, and cancelling) a sign-in in the server's browser. */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }
}
