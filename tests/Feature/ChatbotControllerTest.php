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

    public function test_cannot_send_message_using_another_users_conversation_id(): void
    {
        $admin1 = User::factory()->create(['role' => 'admin']);
        $admin2 = User::factory()->create(['role' => 'admin']);
        $conversationOfAdmin1 = ChatConversation::create(['user_id' => $admin1->id]);

        $response = $this->actingAs($admin2)->postJson(route('chatbot.send-message'), [
            'message' => 'halo', 'conversation_id' => $conversationOfAdmin1->id,
        ]);

        $response->assertNotFound();
    }

    public function test_current_user_message_is_not_sent_twice_to_gemini(): void
    {
        $capturedContents = null;

        // NOTE: assertions must NOT run inside this closure — GeminiChatbotService::callGemini()
        // wraps the HTTP call in a try/catch(\Throwable), so a failed assertion thrown here would be
        // silently swallowed and turned into the fallback reply instead of failing the test.
        Http::fake(function ($request) use (&$capturedContents) {
            $capturedContents = $request->data()['contents'];

            return Http::response([
                'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Halo juga!']]]]],
            ], 200);
        });

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('chatbot.send-message'), ['message' => 'halo dunia'])
            ->assertOk()
            ->assertJson(['reply' => 'Halo juga!']);

        $this->assertNotNull($capturedContents, 'Expected the outbound Gemini request to be captured.');

        $userTurns = array_values(array_filter($capturedContents, fn ($c) => $c['role'] === 'user'));
        $matchingTurns = array_filter($userTurns, fn ($c) => $c['parts'][0]['text'] === 'halo dunia');

        $this->assertCount(
            1,
            $matchingTurns,
            'The current user message must appear exactly once in the outbound Gemini contents, not duplicated.'
        );
    }

    public function test_sending_message_touches_conversation_updated_at(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Oke']]]]],
        ], 200)]);

        $admin = User::factory()->create(['role' => 'admin']);
        $conversation = ChatConversation::create(['user_id' => $admin->id]);
        $conversation->forceFill(['updated_at' => now()->subDay()])->save();
        $originalUpdatedAt = $conversation->updated_at;

        $this->actingAs($admin)->postJson(route('chatbot.send-message'), [
            'message' => 'halo', 'conversation_id' => $conversation->id,
        ])->assertOk();

        $this->assertTrue($conversation->fresh()->updated_at->gt($originalUpdatedAt));
    }

    public function test_conversations_list_returns_only_own_conversations_newest_first(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $otherAdmin = User::factory()->create(['role' => 'admin']);

        $older = ChatConversation::create(['user_id' => $admin->id]);
        $older->messages()->create(['role' => 'user', 'content' => 'Pertanyaan pertama soal MTBF mesin M-01']);
        $older->forceFill(['updated_at' => now()->subHour()])->save();

        $newer = ChatConversation::create(['user_id' => $admin->id]);
        $newer->messages()->create(['role' => 'user', 'content' => 'Rekap NG bulan ini']);

        ChatConversation::create(['user_id' => $otherAdmin->id]);

        $response = $this->actingAs($admin)->getJson(route('chatbot.conversations'));

        $response->assertOk();
        $ids = array_column($response->json(), 'id');
        $this->assertSame([$newer->id, $older->id], $ids);
        $this->assertSame('Rekap NG bulan ini', $response->json()[0]['title']);
    }

    public function test_staff_produksi_cannot_list_conversations(): void
    {
        $staff = User::factory()->create(['role' => 'staff_produksi']);

        $this->actingAs($staff)->getJson(route('chatbot.conversations'))->assertForbidden();
    }
}
