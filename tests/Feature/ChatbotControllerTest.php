<?php
namespace Tests\Feature;

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
        $response->assertJsonStructure(['reply', 'suggestions']);
    }

    public function test_send_message_does_not_persist_to_database(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Halo!']]]]],
        ], 200)]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('chatbot.send-message'), ['message' => 'halo'])->assertOk();

        $this->assertDatabaseCount('chat_messages', 0);
        $this->assertDatabaseCount('chat_conversations', 0);
    }

    public function test_history_is_forwarded_to_provider(): void
    {
        $captured = null;
        Http::fake(function ($request) use (&$captured) {
            $captured = $request->data()['contents'];
            return Http::response([
                'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'oke']]]]],
            ], 200);
        });

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('chatbot.send-message'), [
            'message' => 'kedua',
            'history' => [
                ['role' => 'user', 'content' => 'pertama'],
                ['role' => 'assistant', 'content' => 'jawaban pertama'],
            ],
        ])->assertOk();

        $texts = array_map(fn ($c) => $c['parts'][0]['text'], $captured);
        $this->assertContains('pertama', $texts);
        $this->assertContains('kedua', $texts);
    }

    public function test_rejects_history_with_invalid_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('chatbot.send-message'), [
            'message' => 'halo',
            'history' => [['role' => 'system', 'content' => 'x']],
        ])->assertStatus(422);
    }

    public function test_rejects_history_longer_than_20(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $history = array_fill(0, 21, ['role' => 'user', 'content' => 'x']);

        $this->actingAs($admin)->postJson(route('chatbot.send-message'), [
            'message' => 'halo', 'history' => $history,
        ])->assertStatus(422);
    }

    public function test_staff_produksi_cannot_send_message(): void
    {
        $staff = User::factory()->create(['role' => 'staff_produksi']);

        $this->actingAs($staff)->postJson(route('chatbot.send-message'), ['message' => 'halo'])
            ->assertForbidden();
    }

    public function test_admin_can_view_chatbot_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('chatbot.index'))->assertOk();
    }

    public function test_staff_produksi_cannot_view_chatbot_page(): void
    {
        $staff = User::factory()->create(['role' => 'staff_produksi']);

        $this->actingAs($staff)->get(route('chatbot.index'))->assertForbidden();
    }

    public function test_falls_back_to_groq_when_gemini_fails(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 429),
            'api.groq.com/*' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Jawaban dari Groq.']]],
            ], 200),
        ]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('chatbot.send-message'), ['message' => 'halo'])
            ->assertOk()->assertJson(['reply' => 'Jawaban dari Groq.']);
    }

    public function test_returns_generic_fallback_when_both_providers_fail(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 429),
            'api.groq.com/*' => Http::response([], 500),
        ]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('chatbot.send-message'), ['message' => 'halo'])
            ->assertOk()->assertJson(['reply' => 'Maaf, sistem AI lagi ga bisa diakses, coba lagi nanti.']);
    }
}
