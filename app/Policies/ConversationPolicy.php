<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $conversation->hasParticipant($user);
    }

    public function send(User $user, Conversation $conversation): bool
    {
        return $conversation->hasParticipant($user);
    }

    /**
     * Rename, change photo, add/remove members, change roles, delete.
     */
    public function manage(User $user, Conversation $conversation): Response
    {
        if (! $conversation->isGroup()) {
            return Response::deny('Direct conversations cannot be managed.');
        }

        return $conversation->isAdmin($user)
            ? Response::allow()
            : Response::deny('Only group admins can do this.');
    }

    public function leave(User $user, Conversation $conversation): Response
    {
        if (! $conversation->isGroup()) {
            return Response::deny('You cannot leave a direct conversation.');
        }

        return $conversation->hasParticipant($user)
            ? Response::allow()
            : Response::deny('You are not a member of this group.');
    }
}
