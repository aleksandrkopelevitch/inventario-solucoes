<?php

namespace App\Policies;

use App\Models\McpWrite;
use App\Models\User;

/**
 * "Alterações via MCP": reading what chats changed and reverting it. Admin
 * only — the list spans every person's changes, and a revert can delete a
 * record (a reverted create), which is the admin's everywhere in this app.
 */
class McpWritePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function revert(User $user, ?McpWrite $write = null): bool
    {
        return $user->isAdmin();
    }
}
