<?php

namespace App\Policies;

use App\Models\FlowspecChat;
use App\Models\User;

/** FlowSpec generator chats are personal: only the owner (or admin) can view and converse. */
class FlowspecChatPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, FlowspecChat $chat): bool
    {
        return $user->id === $chat->user_id || $user->role->isAdmin();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, FlowspecChat $chat): bool
    {
        return $user->id === $chat->user_id || $user->role->isAdmin();
    }
}
