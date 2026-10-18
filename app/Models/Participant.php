<?php

namespace App\Models;

use App\Enums\ParticipantRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

class Participant extends Pivot
{
    protected $table = 'conversation_participants';

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'role' => ParticipantRole::class,
            'last_read_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === ParticipantRole::Admin;
    }
}
