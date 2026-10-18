<?php

namespace App\Models;

use App\Enums\ConversationType;
use App\Enums\ParticipantRole;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    protected $fillable = [
        'type',
        'name',
        'photo_path',
        'direct_key',
        'created_by',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => ConversationType::class,
            'last_message_at' => 'datetime',
        ];
    }

    public static function directKey(User $a, User $b): string
    {
        $ids = [$a->getKey(), $b->getKey()];
        sort($ids);

        return implode(':', $ids);
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_participants')
            ->using(Participant::class)
            ->withPivot(['role', 'last_read_message_id', 'last_read_at'])
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function isGroup(): bool
    {
        return $this->type === ConversationType::Group;
    }

    public function hasParticipant(User $user): bool
    {
        return $this->participants()->whereKey($user->getKey())->exists();
    }

    public function participant(User $user): ?Participant
    {
        /** @var User|null $member */
        $member = $this->participants()->whereKey($user->getKey())->first();

        return $member?->pivot;
    }

    public function isAdmin(User $user): bool
    {
        return $this->participant($user)?->isAdmin() ?? false;
    }

    public function adminCount(): int
    {
        return $this->participants()->wherePivot('role', ParticipantRole::Admin->value)->count();
    }

    /**
     * Unread messages for a participant (messages from others after their last read one).
     */
    public function unreadCountFor(User $user): int
    {
        $lastRead = $this->participant($user)?->last_read_message_id ?? 0;

        return $this->messages()
            ->where('id', '>', $lastRead)
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $user->getKey()))
            ->count();
    }

    /**
     * Display name from a participant's point of view.
     */
    public function titleFor(User $viewer): string
    {
        if ($this->isGroup()) {
            return $this->name ?? 'Group';
        }

        return $this->participants->firstWhere('id', '!=', $viewer->getKey())?->name ?? 'Direct message';
    }
}
