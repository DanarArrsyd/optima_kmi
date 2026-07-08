<?php
namespace Tests\Unit;

use App\Models\ChatConversation;
use App\Models\User;
use App\Services\ChatbotTools;
use App\Services\GeminiChatbotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiChatbotServiceTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(): ChatConversation
    {
        $user = User::factory()->create(['role' => 'admin']);
        return ChatConversation::create(['user_id' => $user->id]);
    }

    public function test_returns_gemini_text_reply_directly_when_no_tool_call_needed(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['role' => 'model', 'parts' => [['text' => 'Halo, ada yang bisa gw bantu?']]],
                ]],
            ], 200),
        ]);

        $reply = (new GeminiChatbotService(new ChatbotTools()))->ask($this->conversation(), 'halo');

        $this->assertSame('Halo, ada yang bisa gw bantu?', $reply);
    }

    public function test_executes_tool_call_then_returns_final_text(): void
    {
        $call = 0;
        Http::fake(function () use (&$call) {
            $call++;
            if ($call === 1) {
                return Http::response([
                    'candidates' => [[
                        'content' => ['role' => 'model', 'parts' => [[
                            'functionCall' => ['name' => 'getRekapNg', 'args' => ['namaProses' => 'BENDING', 'periode' => '2026-07']],
                        ]]],
                    ]],
                ], 200);
            }
            return Http::response([
                'candidates' => [[
                    'content' => ['role' => 'model', 'parts' => [['text' => 'NG di proses BENDING bulan itu 0.']]],
                ]],
            ], 200);
        });

        $reply = (new GeminiChatbotService(new ChatbotTools()))->ask($this->conversation(), 'berapa NG bending bulan lalu?');

        $this->assertSame('NG di proses BENDING bulan itu 0.', $reply);
        Http::assertSentCount(2);
    }

    public function test_returns_fallback_message_when_gemini_api_fails(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 500)]);

        $reply = (new GeminiChatbotService(new ChatbotTools()))->ask($this->conversation(), 'halo');

        $this->assertSame('Maaf, sistem AI lagi ga bisa diakses, coba lagi nanti.', $reply);
    }

    public function test_returns_fallback_message_when_loop_exceeds_max_iterations(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [[
                    'functionCall' => ['name' => 'getRekapNg', 'args' => ['namaProses' => 'X', 'periode' => '2026-07']],
                ]]],
            ]],
        ], 200)]);

        $reply = (new GeminiChatbotService(new ChatbotTools()))->ask($this->conversation(), 'halo');

        $this->assertSame('Maaf, sistem AI lagi ga bisa diakses, coba lagi nanti.', $reply);
    }
}
