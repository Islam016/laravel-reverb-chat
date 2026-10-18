<?php

namespace Tests\Feature;

use App\Events\ConversationChanged;
use App\Events\MessageSent;
use App\Events\MessagesRead;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GroupConversationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /** @var Collection<int, User> */
    private Collection $members;

    private Conversation $group;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([MessageSent::class, MessagesRead::class, ConversationChanged::class]);
        Storage::fake('local');

        $this->admin = User::factory()->create();
        $this->members = User::factory()->count(3)->create();

        $id = $this->actingAs($this->admin)
            ->postJson('/conversations/groups', ['name' => 'Site team', 'members' => $this->members->pluck('id')->all()])
            ->assertCreated()
            ->assertJsonPath('data.is_admin', true)
            ->json('data.id');

        $this->group = Conversation::findOrFail($id);
    }

    private function roleOf(User $user): ?string
    {
        return $this->group->participant($user)?->role->value;
    }

    public function test_creator_becomes_admin_and_members_are_notified(): void
    {
        $this->assertSame('admin', $this->roleOf($this->admin));
        $this->assertSame('member', $this->roleOf($this->members[0]));
        $this->assertCount(4, $this->group->participants);

        Event::assertDispatched(ConversationChanged::class, fn (ConversationChanged $event) => $event->change === 'created'
            && count($event->userIds) === 4);
    }

    public function test_members_can_chat_in_the_group(): void
    {
        $this->actingAs($this->members[1])
            ->postJson("/conversations/{$this->group->id}/messages", ['body' => 'Morning all'])
            ->assertCreated();

        $this->actingAs($this->members[2])
            ->getJson("/conversations/{$this->group->id}")
            ->assertOk()
            ->assertJsonPath('conversation.title', 'Site team')
            ->assertJsonPath('messages.0.body', 'Morning all');
    }

    public function test_only_admins_can_rename_or_change_the_photo(): void
    {
        $this->actingAs($this->members[0])
            ->postJson("/conversations/{$this->group->id}", ['name' => 'Hijacked'])
            ->assertForbidden()
            ->assertJson(['message' => 'Only group admins can do this.']);

        $this->actingAs($this->admin)
            ->post("/conversations/{$this->group->id}", [
                'name' => 'Site team - Taif',
                'photo' => UploadedFile::fake()->image('logo.png'),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Site team - Taif');

        Storage::disk('local')->assertExists($this->group->fresh()->photo_path);
        $this->actingAs($this->members[0])->get("/conversations/{$this->group->id}/photo")->assertOk();
    }

    public function test_admin_can_add_and_remove_members(): void
    {
        $newcomer = User::factory()->create();

        $this->actingAs($this->admin)
            ->postJson("/conversations/{$this->group->id}/members", ['members' => [$newcomer->id, $this->members[0]->id]])
            ->assertOk()
            ->assertJsonCount(5, 'members');

        $this->actingAs($this->admin)
            ->deleteJson("/conversations/{$this->group->id}/members/{$this->members[0]->id}")
            ->assertOk()
            ->assertJsonCount(4, 'members');

        $this->actingAs($this->members[0])->getJson("/conversations/{$this->group->id}")->assertForbidden();
    }

    public function test_members_cannot_manage_membership(): void
    {
        $this->actingAs($this->members[0])
            ->deleteJson("/conversations/{$this->group->id}/members/{$this->members[1]->id}")
            ->assertForbidden();

        $this->actingAs($this->members[0])
            ->postJson("/conversations/{$this->group->id}/members", ['members' => [User::factory()->create()->id]])
            ->assertForbidden();
    }

    public function test_admin_can_promote_and_demote(): void
    {
        $this->actingAs($this->admin)
            ->patchJson("/conversations/{$this->group->id}/members/{$this->members[0]->id}", ['role' => 'admin'])
            ->assertOk();
        $this->assertSame('admin', $this->roleOf($this->members[0]));

        // The promoted member can now demote the original admin.
        $this->actingAs($this->members[0])
            ->patchJson("/conversations/{$this->group->id}/members/{$this->admin->id}", ['role' => 'member'])
            ->assertOk();
        $this->assertSame('member', $this->roleOf($this->admin));
    }

    public function test_the_last_admin_cannot_be_demoted_or_removed(): void
    {
        $this->actingAs($this->admin)
            ->patchJson("/conversations/{$this->group->id}/members/{$this->admin->id}", ['role' => 'member'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->actingAs($this->admin)
            ->deleteJson("/conversations/{$this->group->id}/members/{$this->admin->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user');
    }

    public function test_when_the_last_admin_leaves_the_oldest_member_is_promoted(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/conversations/{$this->group->id}/leave")
            ->assertOk();

        $this->assertFalse($this->group->hasParticipant($this->admin));
        $this->assertSame(1, $this->group->adminCount());
        $this->assertSame(3, $this->group->participants()->count());
    }

    public function test_group_is_deleted_when_everyone_leaves(): void
    {
        foreach ([$this->admin, ...$this->members] as $user) {
            $this->actingAs($user)->postJson("/conversations/{$this->group->id}/leave")->assertOk();
            $this->group->unsetRelations();
        }

        $this->assertModelMissing($this->group);
    }

    public function test_only_admins_can_delete_the_group(): void
    {
        $this->actingAs($this->members[0])->deleteJson("/conversations/{$this->group->id}")->assertForbidden();

        $this->actingAs($this->admin)->deleteJson("/conversations/{$this->group->id}")->assertOk();

        $this->assertModelMissing($this->group);
        Event::assertDispatched(ConversationChanged::class, fn (ConversationChanged $event) => $event->change === 'deleted');
    }

    public function test_direct_conversations_cannot_be_managed_or_left(): void
    {
        $directId = $this->actingAs($this->admin)
            ->postJson('/conversations/direct', ['user_id' => $this->members[0]->id])
            ->json('data.id');

        $this->actingAs($this->admin)->postJson("/conversations/{$directId}", ['name' => 'x'])
            ->assertForbidden()
            ->assertJson(['message' => 'Direct conversations cannot be managed.']);

        $this->actingAs($this->admin)->postJson("/conversations/{$directId}/leave")->assertForbidden();
    }

    public function test_sidebar_lists_conversations_with_latest_message(): void
    {
        $this->actingAs($this->members[0])
            ->postJson("/conversations/{$this->group->id}/messages", ['body' => 'Concrete arrives at 7']);

        $this->actingAs($this->admin)
            ->getJson('/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Site team')
            ->assertJsonPath('data.0.last_message.body', 'Concrete arrives at 7')
            ->assertJsonPath('data.0.unread_count', 1);
    }
}
