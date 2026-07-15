<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\FlowspecChat;
use App\Models\User;

/** Chats do gerador de flowSpec são pessoais: só o dono (ou admin) vê e conversa. */
class FlowspecChatPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, FlowspecChat $chat): bool
    {
        return $user->id === $chat->user_id || $user->role === UserRole::Admin;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, FlowspecChat $chat): bool
    {
        return $user->id === $chat->user_id || $user->role === UserRole::Admin;
    }
}
