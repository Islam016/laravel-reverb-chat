<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Sent to each affected user's personal channel when a conversation they
 * belong to (or just left) changes, so their sidebar can refresh.
 */
class ConversationChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  list<int>  $userIds
     */
    public function __construct(
        public readonly int $conversationId,
        public readonly array $userIds,
        public readonly string $change,
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(fn (int $id) => new PrivateChannel('users.'.$id), $this->userIds);
    }

    public function broadcastAs(): string
    {
        return 'conversation.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'change' => $this->change,
        ];
    }
}
