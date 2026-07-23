<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatbotStreamTest extends TestCase
{
    use RefreshDatabase;

    public function test_stream_emits_sse_deltas_and_done_event(): void
    {
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Halo dunia stamping.']]],
        ], 200)]);

        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->post(route('chatbot.stream'), ['message' => 'halo']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');

        $body = $response->streamedContent();
        $this->assertStringContainsString('data:', $body);
        $this->assertStringContainsString('event: done', $body);

        // Rakit ulang teks dari semua delta → harus sama dengan balasan provider.
        preg_match_all('/data: (\{.*?\})\n/', $body, $m);
        $assembled = '';
        $sawDone = false;
        foreach ($m[1] as $json) {
            $payload = json_decode($json, true);
            if (($payload['done'] ?? false) === true) {
                $sawDone = true;
                $this->assertIsArray($payload['suggestions']);
            } else {
                $assembled .= $payload['delta'] ?? '';
            }
        }
        $this->assertSame('Halo dunia stamping.', $assembled);
        $this->assertTrue($sawDone, 'Harus ada event done dengan suggestions.');
    }

    public function test_stream_uses_fallback_text_when_all_providers_fail(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 429),
            'api.groq.com/*' => Http::response([], 500),
        ]);

        $admin = User::factory()->create(['role' => 'admin']);

        $body = $this->actingAs($admin)->post(route('chatbot.stream'), ['message' => 'halo'])
            ->assertOk()->streamedContent();

        preg_match_all('/data: (\{.*?\})\n/', $body, $m);
        $assembled = '';
        foreach ($m[1] as $json) {
            $assembled .= json_decode($json, true)['delta'] ?? '';
        }
        $this->assertStringContainsString('Maaf, sistem AI lagi ga bisa diakses', $assembled);
    }

    public function test_stream_rejects_staff_produksi(): void
    {
        $staff = User::factory()->create(['role' => 'staff_produksi']);

        $this->actingAs($staff)->post(route('chatbot.stream'), ['message' => 'halo'])
            ->assertForbidden();
    }

    public function test_stream_validates_message(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('chatbot.stream'), ['message' => ''])
            ->assertStatus(302); // web validation → redirect back
    }
}
