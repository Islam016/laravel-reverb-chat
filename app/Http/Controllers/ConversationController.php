<?php

namespace App\Http\Controllers;

use App\Actions\ChatService;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConversationController extends Controller
{
    public function __construct(private readonly ChatService $chat)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $conversations = $request->user()->conversations()
            ->with(['participants', 'latestMessage'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('conversations.id')
            ->get();

        return ConversationResource::collection($conversations);
    }

    public function storeDirect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $conversation = $this->chat->openDirect($request->user(), User::findOrFail($data['user_id']));

        return ConversationResource::make($conversation->load(['participants', 'latestMessage']))
            ->response()
            ->setStatusCode($conversation->wasRecentlyCreated ? 201 : 200);
    }

    public function storeGroup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'members' => ['required', 'array', 'min:1'],
            'members.*' => ['integer', 'distinct', 'exists:users,id'],
        ]);

        $group = $this->chat->createGroup($request->user(), $data['name'], $data['members']);

        return ConversationResource::make($group->load(['participants', 'latestMessage']))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Messages, newest page first. Pass ?before={message id} to load older ones.
     */
    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);

        $data = $request->validate([
            'before' => ['nullable', 'integer'],
        ]);

        $conversation->load(['participants', 'latestMessage']);

        $messages = $conversation->messages()
            ->with('sender')
            ->when($data['before'] ?? null, fn ($q, $before) => $q->where('id', '<', $before))
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->reverse()
            ->values()
            ->each->setRelation('conversation', $conversation);

        return response()->json([
            'conversation' => ConversationResource::make($conversation)->resolve($request),
            'messages' => MessageResource::collection($messages)->resolve($request),
            'has_more' => $messages->isNotEmpty() && $conversation->messages()->where('id', '<', $messages->first()->id)->exists(),
        ]);
    }

    public function update(Request $request, Conversation $conversation): ConversationResource
    {
        Gate::authorize('manage', $conversation);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'photo' => ['sometimes', 'image', 'max:2048'],
        ]);

        if (isset($data['name'])) {
            $this->chat->rename($conversation, $data['name']);
        }

        if ($request->hasFile('photo')) {
            $this->chat->changePhoto($conversation, $request->file('photo'));
        }

        return ConversationResource::make($conversation->fresh(['participants', 'latestMessage']));
    }

    public function destroy(Conversation $conversation): JsonResponse
    {
        Gate::authorize('manage', $conversation);

        $this->chat->delete($conversation);

        return response()->json(['message' => 'Group deleted.']);
    }

    public function photo(Conversation $conversation): StreamedResponse
    {
        Gate::authorize('view', $conversation);

        abort_unless($conversation->photo_path && Storage::exists($conversation->photo_path), 404);

        return Storage::response($conversation->photo_path);
    }
}
