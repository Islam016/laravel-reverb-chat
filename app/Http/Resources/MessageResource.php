<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Message
 */
class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'body' => $this->body,
            'sender' => $this->sender ? ['id' => $this->sender->id, 'name' => $this->sender->name] : null,
            'attachment' => $this->hasAttachment() ? [
                'name' => $this->attachment_name,
                'mime' => $this->attachment_mime,
                'size' => $this->attachment_size,
                'is_image' => $this->isImage(),
                'url' => route('messages.attachment', $this->resource),
            ] : null,
            // Read receipts: who (other than the sender) has read up to this message.
            'read_by' => $this->whenLoaded('conversation', fn () => $this->conversation->participants
                ->filter(fn ($user) => $user->id !== $this->user_id
                    && $user->pivot->last_read_message_id !== null
                    && $user->pivot->last_read_message_id >= $this->id)
                ->pluck('id')
                ->values()
                ->all(), []),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
