<?php

namespace App\Policies;

use App\Models\FlowspecChat;
use App\Models\User;

/** FlowSpec generator chats are personal: only the owner (or admin) can view and converse. */
class FlowspecChatPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->canReadInventory();
    }

    public function view(User $user, FlowspecChat $chat): bool
    {
        return $user->id === $chat->user_id || $user->role->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->role->canReadInventory();
    }

    public function update(User $user, FlowspecChat $chat): bool
    {
        return $user->id === $chat->user_id || $user->role->isAdmin();
    }

    /**
     * Whether this person may run the Digibee lifecycle from this conversation
     * — write a flowSpec into a real pipeline, deploy it and fire a battery at
     * it.
     *
     * Two rules composed, and neither substitutes for the other. Seeing the
     * conversation is `view` above (chats are personal), and that is about
     * privacy; REACHING THE REALM is a write capability, and that is about
     * what this app is allowed to do to the platform. A Viewer who owns a chat
     * can read every flowSpec in it and must not deploy one — the same seam as
     * every other `canWrite()` gate in the app.
     */
    public function run(User $user, FlowspecChat $chat): bool
    {
        return $this->view($user, $chat) && $user->role->canWrite();
    }
}
