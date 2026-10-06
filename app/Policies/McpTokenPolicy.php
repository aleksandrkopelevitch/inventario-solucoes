<?php

namespace App\Policies;

use App\Models\User;

/**
 * Minting and destroying MCP tokens is the admin's, and it is deliberately
 * narrower than any module's Editor level.
 *
 * An editor writes CONTENT. A token is not content: it is a key that reads the
 * whole catalog from outside the app, with no session, no expiry and no name on
 * it but the one whoever minted it typed. It belongs beside inviting an account
 * (`UserPolicy::manage`) rather than beside editing a caderno, and for the same
 * reason — both hand out access, and handing out access is the admin's.
 *
 * There is no `update`: a token's `name` is the only thing about it that could
 * be edited, and a token whose name can drift from what holds it is worse than
 * one that has to be replaced. Minting and deleting are the whole vocabulary.
 */
class McpTokenPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user): bool
    {
        return $user->isAdmin();
    }
}
