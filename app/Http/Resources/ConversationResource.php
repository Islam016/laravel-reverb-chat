<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Conversation
 */
class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'title' => $this->titleFor($viewer),
            'photo_url' => $this->photo_path ? route('conversations.photo', $this->resource) : null,
            'is_admin' => $this->isGroup() && $this->participants->firstWhere('id', $viewer->id)?->pivot->isAdmin(),
            'participants' => $this->participants->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->pivot->role->value,
            ])->values(),
            'last_message' => $this->whenLoaded('latestMessage', fn () => $this->latestMessage ? [
                'body' => $this->latestMessage->body ?? ($this->latestMessage->attachment_name ? '📎 '.$this->latestMessage->attachment_name : null),
                'sender_id' => $this->latestMessage->user_id,
                'created_at' => $this->latestMessage->created_at->toIso8601String(),
            ] : null),
            'unread_count' => $this->unreadCountFor($viewer),
            'last_message_at' => $this->last_message_at?->toIso8601String(),
        ];
    }
}
