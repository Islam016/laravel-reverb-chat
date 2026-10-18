<?php

namespace App\Http\Controllers;

use App\Actions\ChatService;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageController extends Controller
{
    /** Allowed attachment types: images, PDFs, Office documents, text and archives. */
    private const ATTACHMENT_RULES = ['file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip'];

    public function __construct(private readonly ChatService $chat)
    {
    }

    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('send', $conversation);

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'attachment' => ['nullable', ...self::ATTACHMENT_RULES],
        ]);

        $message = $this->chat->send($conversation, $request->user(), $data['body'] ?? null, $request->file('attachment'));

        $message->load(['sender', 'conversation.participants']);

        return MessageResource::make($message)->response()->setStatusCode(201);
    }

    public function attachment(Message $message): StreamedResponse
    {
        Gate::authorize('view', $message->conversation);

        abort_unless($message->hasAttachment() && Storage::exists($message->attachment_path), 404);

        return $message->isImage()
            ? Storage::response($message->attachment_path, $message->attachment_name)
            : Storage::download($message->attachment_path, $message->attachment_name);
    }

    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);

        $data = $request->validate([
            'message_id' => ['nullable', 'integer'],
        ]);

        $upTo = isset($data['message_id'])
            ? $conversation->messages()->findOrFail($data['message_id'])
            : null;

        $lastRead = $this->chat->markRead($conversation, $request->user(), $upTo);

        return response()->json(['last_read_message_id' => $lastRead]);
    }
}
