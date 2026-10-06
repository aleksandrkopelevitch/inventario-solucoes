<?php

namespace App\Policies;

use App\Enums\AccessModule;
use App\Models\FlowspecChat;
use App\Models\User;

/**
 * Especialista em Integrações (`AccessModule::Integrations`).
 *
 * A Reader of the module reads EVERY conversation, not just their own; None
 * does not see the module at all. Starting one, writing in it and running the Digibee lifecycle from
 * it is the module's Editor, and only in a conversation of their own: somebody
 * else's thread is read, never continued (an admin may do both).
 */
class FlowspecChatPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canView(AccessModule::Integrations);
    }

    public function view(User $user, FlowspecChat $chat): bool
    {
        return $user->canView(AccessModule::Integrations);
    }

    public function create(User $user): bool
    {
        return $user->canEdit(AccessModule::Integrations);
    }

    public function update(User $user, FlowspecChat $chat): bool
    {
        return $user->isAdmin()
            || ($user->id === $chat->user_id && $user->canEdit(AccessModule::Integrations));
    }

    /**
     * Whether this person may run the Digibee lifecycle from this conversation
     * — write a flowSpec into a real pipeline, deploy it and fire a battery at
     * it.
     *
     * The same rule as writing in the conversation: its owner, an Editor of
     * the module, or an admin. Reading a thread — which every account may — is
     * never enough to reach the realm with it.
     */
    public function run(User $user, FlowspecChat $chat): bool
    {
        return $this->update($user, $chat);
    }
}
