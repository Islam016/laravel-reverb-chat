<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Read receipt: tells the other participants how far a user has read.
 */
class MessagesRead implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly int $conversationId,
        public readonly int $userId,
        public readonly int $lastReadMessageId,
    ) {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversations.'.$this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'messages.read';
    }

    /**
     * @return array<string, int>
     */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'last_read_message_id' => $this->lastReadMessageId,
        ];
    }
}
