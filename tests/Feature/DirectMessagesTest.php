<?php

namespace Tests\Feature;

use App\Events\ConversationChanged;
use App\Events\MessageSent;
use App\Events\MessagesRead;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DirectMessagesTest extends TestCase
{
    use RefreshDatabase;

    private User $sara;

    private User $omar;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([MessageSent::class, MessagesRead::class, ConversationChanged::class]);
        Storage::fake('local');

        $this->sara = User::factory()->create(['name' => 'Sara']);
        $this->omar = User::factory()->create(['name' => 'Omar']);
    }

    private function openDirect(): Conversation
    {
        $id = $this->actingAs($this->sara)
            ->postJson('/conversations/direct', ['user_id' => $this->omar->id])
            ->json('data.id');

        return Conversation::findOrFail($id);
    }

    public function test_opening_a_direct_conversation_reuses_the_existing_one(): void
    {
        $this->actingAs($this->sara)
            ->postJson('/conversations/direct', ['user_id' => $this->omar->id])
            ->assertCreated()
            ->assertJsonPath('data.type', 'direct')
            ->assertJsonPath('data.title', 'Omar')
            ->assertJsonCount(2, 'data.participants');

        $this->actingAs($this->omar)
            ->postJson('/conversations/direct', ['user_id' => $this->sara->id])
            ->assertOk()
            ->assertJsonPath('data.title', 'Sara');

        $this->assertSame(1, Conversation::count());
    }

    public function test_you_cannot_message_yourself(): void
    {
        $this->actingAs($this->sara)
            ->postJson('/conversations/direct', ['user_id' => $this->sara->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');
    }

    public function test_sending_a_message_broadcasts_it(): void
    {
        $conversation = $this->openDirect();

        $this->actingAs($this->sara)
            ->postJson("/conversations/{$conversation->id}/messages", ['body' => '  Hello Omar  '])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Hello Omar')
            ->assertJsonPath('data.sender.id', $this->sara->id);

        Event::assertDispatched(MessageSent::class, fn (MessageSent $event) => $event->message->body === 'Hello Omar'
            && $event->broadcastOn()[0]->name === "private-conversations.{$conversation->id}");

        $this->assertNotNull($conversation->fresh()->last_message_at);
    }

    public function test_empty_messages_are_rejected(): void
    {
        $conversation = $this->openDirect();

        $this->actingAs($this->sara)
            ->postJson("/conversations/{$conversation->id}/messages", ['body' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');

        Event::assertNotDispatched(MessageSent::class);
    }

    public function test_outsiders_cannot_read_or_write(): void
    {
        $conversation = $this->openDirect();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->getJson("/conversations/{$conversation->id}")->assertForbidden();
        $this->actingAs($outsider)->postJson("/conversations/{$conversation->id}/messages", ['body' => 'hi'])->assertForbidden();
        $this->actingAs($outsider)->postJson("/conversations/{$conversation->id}/read")->assertForbidden();
    }

    public function test_files_can_be_attached_and_downloaded_by_participants_only(): void
    {
        $conversation = $this->openDirect();

        $response = $this->actingAs($this->sara)
            ->post("/conversations/{$conversation->id}/messages", [
                'attachment' => UploadedFile::fake()->create('site-report.pdf', 120, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.body', null)
            ->assertJsonPath('data.attachment.name', 'site-report.pdf')
            ->assertJsonPath('data.attachment.is_image', false);

        $message = $conversation->messages()->first();
        Storage::disk('local')->assertExists($message->attachment_path);

        $this->actingAs($this->omar)->get($response->json('data.attachment.url'))->assertOk()->assertDownload('site-report.pdf');
        $this->actingAs(User::factory()->create())->get($response->json('data.attachment.url'))->assertForbidden();
    }

    public function test_images_are_flagged_and_shown_inline(): void
    {
        $conversation = $this->openDirect();

        $this->actingAs($this->sara)
            ->post("/conversations/{$conversation->id}/messages", [
                'body' => 'Photo from the site',
                'attachment' => UploadedFile::fake()->image('site.jpg', 400, 300),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.attachment.is_image', true);
    }

    public function test_unsafe_file_types_are_rejected(): void
    {
        $conversation = $this->openDirect();

        $this->actingAs($this->sara)
            ->post("/conversations/{$conversation->id}/messages", [
                'attachment' => UploadedFile::fake()->create('setup.exe', 10, 'application/x-msdownload'),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attachment');
    }

    public function test_read_receipts_are_stored_and_broadcast(): void
    {
        $conversation = $this->openDirect();

        $this->actingAs($this->sara)->postJson("/conversations/{$conversation->id}/messages", ['body' => 'One']);
        $lastId = $this->actingAs($this->sara)
            ->postJson("/conversations/{$conversation->id}/messages", ['body' => 'Two'])
            ->json('data.id');

        // Omar has not read anything yet.
        $this->actingAs($this->omar)
            ->getJson('/conversations')
            ->assertJsonPath('data.0.unread_count', 2);

        $this->actingAs($this->omar)
            ->postJson("/conversations/{$conversation->id}/read")
            ->assertOk()
            ->assertJsonPath('last_read_message_id', $lastId);

        Event::assertDispatched(MessagesRead::class, fn (MessagesRead $event) => $event->userId === $this->omar->id
            && $event->lastReadMessageId === $lastId);

        $this->actingAs($this->omar)->getJson('/conversations')->assertJsonPath('data.0.unread_count', 0);

        $this->actingAs($this->sara)
            ->getJson("/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('messages.1.body', 'Two')
            ->assertJsonPath('messages.1.read_by', [$this->omar->id]);
    }

    public function test_marking_read_never_moves_backwards(): void
    {
        $conversation = $this->openDirect();

        $first = $this->actingAs($this->sara)->postJson("/conversations/{$conversation->id}/messages", ['body' => 'One'])->json('data.id');
        $second = $this->actingAs($this->sara)->postJson("/conversations/{$conversation->id}/messages", ['body' => 'Two'])->json('data.id');

        $this->actingAs($this->omar)->postJson("/conversations/{$conversation->id}/read");
        $this->actingAs($this->omar)
            ->postJson("/conversations/{$conversation->id}/read", ['message_id' => $first])
            ->assertJsonPath('last_read_message_id', $second);
    }

    public function test_older_messages_load_page_by_page(): void
    {
        $conversation = $this->openDirect();

        foreach (range(1, 35) as $i) {
            $conversation->messages()->create(['user_id' => $this->sara->id, 'body' => "Message {$i}"]);
        }

        $page = $this->actingAs($this->omar)->getJson("/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonCount(30, 'messages')
            ->assertJsonPath('messages.0.body', 'Message 6')
            ->assertJsonPath('has_more', true);

        $this->actingAs($this->omar)
            ->getJson("/conversations/{$conversation->id}?before=".$page->json('messages.0.id'))
            ->assertJsonCount(5, 'messages')
            ->assertJsonPath('has_more', false);
    }

    public function test_only_participants_can_join_the_private_channel(): void
    {
        $conversation = $this->openDirect();
        $payload = ['socket_id' => '1234.5678', 'channel_name' => "private-conversations.{$conversation->id}"];

        $this->actingAs($this->omar)->post('/broadcasting/auth', $payload)->assertOk()->assertJsonStructure(['auth']);
        $this->actingAs(User::factory()->create())->post('/broadcasting/auth', $payload)->assertForbidden();
    }
}
