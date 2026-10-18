<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Only participants may listen to a conversation.
Broadcast::channel('conversations.{conversation}', function (User $user, Conversation $conversation) {
    return $conversation->hasParticipant($user)
        ? ['id' => $user->id, 'name' => $user->name]
        : false;
});

// Personal channel for sidebar updates.
Broadcast::channel('users.{id}', fn (User $user, int $id) => $user->id === $id);
