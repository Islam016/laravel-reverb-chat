<?php

namespace App\Actions;

use App\Enums\ConversationType;
use App\Enums\ParticipantRole;
use App\Events\ConversationChanged;
use App\Events\MessageSent;
use App\Events\MessagesRead;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ChatService
{
    /**
     * Find the direct conversation between two users, or start one.
     */
    public function openDirect(User $from, User $to): Conversation
    {
        if ($from->is($to)) {
            throw ValidationException::withMessages(['user_id' => 'You cannot start a conversation with yourself.']);
        }

        $key = Conversation::directKey($from, $to);

        return DB::transaction(function () use ($from, $to, $key) {
            $conversation = Conversation::query()->where('direct_key', $key)->first();

            if ($conversation) {
                return $conversation;
            }

            $conversation = Conversation::create([
                'type' => ConversationType::Direct,
                'direct_key' => $key,
                'created_by' => $from->getKey(),
            ]);

            $conversation->participants()->attach([
                $from->getKey() => ['role' => ParticipantRole::Member->value],
                $to->getKey() => ['role' => ParticipantRole::Member->value],
            ]);

            return $conversation;
        });
    }

    /**
     * @param  list<int>  $memberIds
     */
    public function createGroup(User $creator, string $name, array $memberIds): Conversation
    {
        $conversation = DB::transaction(function () use ($creator, $name, $memberIds) {
            $conversation = Conversation::create([
                'type' => ConversationType::Group,
                'name' => $name,
                'created_by' => $creator->getKey(),
            ]);

            $members = collect($memberIds)
                ->reject(fn ($id) => (int) $id === $creator->getKey())
                ->mapWithKeys(fn ($id) => [(int) $id => ['role' => ParticipantRole::Member->value]])
                ->all();

            $conversation->participants()->attach(
                [$creator->getKey() => ['role' => ParticipantRole::Admin->value]] + $members
            );

            return $conversation;
        });

        $this->notifyChanged($conversation, $conversation->participants()->pluck('users.id')->all(), 'created');

        return $conversation;
    }

    public function rename(Conversation $group, string $name): Conversation
    {
        $group->update(['name' => $name]);
        $this->notifyChanged($group, $this->memberIds($group), 'renamed');

        return $group;
    }

    public function changePhoto(Conversation $group, UploadedFile $photo): Conversation
    {
        $old = $group->photo_path;

        $group->update(['photo_path' => $photo->store('group-photos/'.$group->getKey())]);

        if ($old) {
            Storage::delete($old);
        }

        $this->notifyChanged($group, $this->memberIds($group), 'photo');

        return $group;
    }

    /**
     * @param  list<int>  $userIds
     */
    public function addMembers(Conversation $group, array $userIds): void
    {
        $new = collect($userIds)
            ->map(fn ($id) => (int) $id)
            ->diff($this->memberIds($group))
            ->values();

        $group->participants()->attach(
            $new->mapWithKeys(fn ($id) => [$id => ['role' => ParticipantRole::Member->value]])->all()
        );

        $this->notifyChanged($group, $this->memberIds($group), 'members');
    }

    public function removeMember(Conversation $group, User $member): void
    {
        if ($group->isAdmin($member) && $group->adminCount() === 1) {
            throw ValidationException::withMessages(['user' => 'Promote another admin before removing the last one.']);
        }

        $group->participants()->detach($member->getKey());

        $this->notifyChanged($group, [...$this->memberIds($group), $member->getKey()], 'members');
    }

    public function setRole(Conversation $group, User $member, ParticipantRole $role): void
    {
        if ($role === ParticipantRole::Member && $group->isAdmin($member) && $group->adminCount() === 1) {
            throw ValidationException::withMessages(['role' => 'A group needs at least one admin.']);
        }

        $group->participants()->updateExistingPivot($member->getKey(), ['role' => $role->value]);

        $this->notifyChanged($group, $this->memberIds($group), 'roles');
    }

    /**
     * Leave a group. If the last admin leaves, the longest-standing member
     * becomes admin; if nobody is left, the group is deleted.
     */
    public function leave(Conversation $group, User $member): void
    {
        DB::transaction(function () use ($group, $member) {
            $wasLastAdmin = $group->isAdmin($member) && $group->adminCount() === 1;

            $group->participants()->detach($member->getKey());

            $remaining = $group->participants()->orderBy('conversation_participants.created_at')->orderBy('conversation_participants.id')->get();

            if ($remaining->isEmpty()) {
                $this->delete($group);

                return;
            }

            if ($wasLastAdmin) {
                $group->participants()->updateExistingPivot($remaining->first()->getKey(), ['role' => ParticipantRole::Admin->value]);
            }

            $this->notifyChanged($group, [...$remaining->pluck('id')->all(), $member->getKey()], 'members');
        });
    }

    public function delete(Conversation $group): void
    {
        $memberIds = $this->memberIds($group);

        Storage::deleteDirectory('attachments/'.$group->getKey());
        Storage::deleteDirectory('group-photos/'.$group->getKey());

        $group->delete();

        if ($memberIds !== []) {
            ConversationChanged::dispatch($group->getKey(), $memberIds, 'deleted');
        }
    }

    public function send(Conversation $conversation, User $sender, ?string $body, ?UploadedFile $attachment = null): Message
    {
        $body = $body !== null ? trim($body) : null;

        if (($body === null || $body === '') && $attachment === null) {
            throw ValidationException::withMessages(['body' => 'Write a message or attach a file.']);
        }

        $message = DB::transaction(function () use ($conversation, $sender, $body, $attachment) {
            $message = $conversation->messages()->create([
                'user_id' => $sender->getKey(),
                'body' => $body ?: null,
                'attachment_path' => $attachment?->store('attachments/'.$conversation->getKey()),
                'attachment_name' => $attachment?->getClientOriginalName(),
                'attachment_mime' => $attachment?->getMimeType(),
                'attachment_size' => $attachment?->getSize(),
            ]);

            $conversation->forceFill(['last_message_at' => $message->created_at])->save();

            // Sending a message means you have read everything before it.
            $this->markRead($conversation, $sender, $message, broadcast: false);

            return $message;
        });

        MessageSent::dispatch($message);

        return $message;
    }

    public function markRead(Conversation $conversation, User $reader, ?Message $upTo = null, bool $broadcast = true): ?int
    {
        $lastId = $upTo?->getKey() ?? $conversation->messages()->max('id');

        if ($lastId === null) {
            return null;
        }

        $current = $conversation->participant($reader)?->last_read_message_id;

        if ($current !== null && $current >= $lastId) {
            return $current;
        }

        $conversation->participants()->updateExistingPivot($reader->getKey(), [
            'last_read_message_id' => $lastId,
            'last_read_at' => now(),
        ]);

        if ($broadcast) {
            MessagesRead::dispatch($conversation->getKey(), $reader->getKey(), (int) $lastId);
        }

        return (int) $lastId;
    }

    /**
     * @return list<int>
     */
    private function memberIds(Conversation $conversation): array
    {
        return $conversation->participants()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  list<int>  $userIds
     */
    private function notifyChanged(Conversation $conversation, array $userIds, string $change): void
    {
        ConversationChanged::dispatch($conversation->getKey(), array_values(array_unique($userIds)), $change);
    }
}
