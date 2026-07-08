<?php
namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatbotControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_send_message_and_gets_reply(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Halo!']]]]],
        ], 200)]);

        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson(route('chatbot.send-message'), ['message' => 'halo']);

        $response->assertOk()->assertJson(['reply' => 'Halo!']);
        $this->assertDatabaseHas('chat_messages', ['role' => 'user', 'content' => 'halo']);
        $this->assertDatabaseHas('chat_messages', ['role' => 'assistant', 'content' => 'Halo!']);
    }

    public function test_staff_produksi_cannot_access_chatbot(): void
    {
        $staff = User::factory()->create(['role' => 'staff_produksi']);

        $response = $this->actingAs($staff)->postJson(route('chatbot.send-message'), ['message' => 'halo']);

        $response->assertForbidden();
    }

    public function test_reusing_conversation_id_appends_to_same_conversation(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Oke']]]]],
        ], 200)]);

        $admin = User::factory()->create(['role' => 'admin']);
        $conversation = ChatConversation::create(['user_id' => $admin->id]);

        $this->actingAs($admin)->postJson(route('chatbot.send-message'), [
            'message' => 'lanjut', 'conversation_id' => $conversation->id,
        ])->assertOk();

        $this->assertSame(2, $conversation->fresh()->messages()->count());
    }
}
