<?php

namespace Tests\Unit;

use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_belongs_to_user_and_has_many_messages(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $conversation = ChatConversation::create(['user_id' => $user->id]);

        $conversation->messages()->create(['role' => 'user', 'content' => 'Berapa MTBF dies BT-091A-3?']);
        $conversation->messages()->create(['role' => 'assistant', 'content' => 'MTBF-nya 120 jam.']);

        $this->assertTrue($conversation->user->is($user));
        $this->assertCount(2, $conversation->fresh()->messages);
        $this->assertSame('user', $conversation->messages->first()->role);
    }

    public function test_soft_delete_conversation_keeps_row_but_hides_from_query(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $conversation = ChatConversation::create(['user_id' => $user->id]);
        $id = $conversation->id;

        $conversation->delete();

        $this->assertSoftDeleted('chat_conversations', ['id' => $id]);
    }
}
