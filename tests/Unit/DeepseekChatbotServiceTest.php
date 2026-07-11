<?php
namespace Tests\Unit;

use App\Services\ChatbotTools;
use App\Services\DeepseekChatbotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeepseekChatbotServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_text_reply_when_no_tool_call_needed(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Halo dari DeepSeek.']]],
            ], 200),
        ]);

        $reply = (new DeepseekChatbotService(new ChatbotTools()))->ask([], 'halo');

        $this->assertSame('Halo dari DeepSeek.', $reply);
    }

    public function test_skips_when_key_not_configured(): void
    {
        config(['services.deepseek.key' => null]);
        Http::fake();

        $reply = (new DeepseekChatbotService(new ChatbotTools()))->ask([], 'halo');

        $this->assertNull($reply);
        Http::assertNothingSent();
    }

    public function test_executes_tool_call_then_returns_final_text(): void
    {
        $call = 0;
        Http::fake(function () use (&$call) {
            $call++;
            if ($call === 1) {
                return Http::response([
                    'choices' => [['message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => ['name' => 'getRekapNg', 'arguments' => json_encode(['namaProses' => 'BENDING'])],
                        ]],
                    ]]],
                ], 200);
            }
            return Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Rekap NG selesai.']]],
            ], 200);
        });

        $reply = (new DeepseekChatbotService(new ChatbotTools()))->ask([], 'rekap NG bending');

        $this->assertSame('Rekap NG selesai.', $reply);
    }
}
