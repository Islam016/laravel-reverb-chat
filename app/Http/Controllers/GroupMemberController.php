<?php

namespace App\Http\Controllers;

use App\Actions\ChatService;
use App\Enums\ParticipantRole;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class GroupMemberController extends Controller
{
    public function __construct(private readonly ChatService $chat)
    {
    }

    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('manage', $conversation);

        $data = $request->validate([
            'members' => ['required', 'array', 'min:1'],
            'members.*' => ['integer', 'distinct', 'exists:users,id'],
        ]);

        $this->chat->addMembers($conversation, $data['members']);

        return $this->members($conversation);
    }

    public function update(Request $request, Conversation $conversation, User $user): JsonResponse
    {
        Gate::authorize('manage', $conversation);
        abort_unless($conversation->hasParticipant($user), 404);

        $data = $request->validate([
            'role' => ['required', Rule::enum(ParticipantRole::class)],
        ]);

        $this->chat->setRole($conversation, $user, ParticipantRole::from($data['role']));

        return $this->members($conversation);
    }

    public function destroy(Conversation $conversation, User $user): JsonResponse
    {
        Gate::authorize('manage', $conversation);
        abort_unless($conversation->hasParticipant($user), 404);

        $this->chat->removeMember($conversation, $user);

        return $this->members($conversation);
    }

    public function leave(Request $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('leave', $conversation);

        $this->chat->leave($conversation, $request->user());

        return response()->json(['message' => 'You left the group.']);
    }

    private function members(Conversation $conversation): JsonResponse
    {
        return response()->json([
            'members' => $conversation->participants()->orderBy('name')->get()->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->pivot->role->value,
            ]),
        ]);
    }
}
