<?php
namespace Tests\Unit;

use App\Models\ChatConversation;
use App\Models\User;
use App\Services\ChatbotTools;
use App\Services\GroqChatbotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroqChatbotServiceTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(): ChatConversation
    {
        $user = User::factory()->create(['role' => 'admin']);
        return ChatConversation::create(['user_id' => $user->id]);
    }

    public function test_returns_groq_text_reply_directly_when_no_tool_call_needed(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'Halo, ada yang bisa gw bantu?'],
                ]],
            ], 200),
        ]);

        $reply = (new GroqChatbotService(new ChatbotTools()))->ask($this->conversation(), 'halo');

        $this->assertSame('Halo, ada yang bisa gw bantu?', $reply);
    }

    public function test_executes_tool_call_then_returns_final_text(): void
    {
        $call = 0;
        Http::fake(function () use (&$call) {
            $call++;
            if ($call === 1) {
                return Http::response([
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [[
                                'id' => 'call_1',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'getRekapNg',
                                    'arguments' => json_encode(['namaProses' => 'BENDING', 'periode' => '2026-07']),
                                ],
                            ]],
                        ],
                    ]],
                ], 200);
            }
            return Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'NG di proses BENDING bulan itu 0.'],
                ]],
            ], 200);
        });

        $reply = (new GroqChatbotService(new ChatbotTools()))->ask($this->conversation(), 'berapa NG bending bulan lalu?');

        $this->assertSame('NG di proses BENDING bulan itu 0.', $reply);
        Http::assertSentCount(2);
    }

    public function test_returns_null_when_groq_api_fails(): void
    {
        Http::fake(['api.groq.com/*' => Http::response([], 500)]);

        $reply = (new GroqChatbotService(new ChatbotTools()))->ask($this->conversation(), 'halo');

        $this->assertNull($reply);
    }

    public function test_returns_null_when_loop_exceeds_max_iterations(): void
    {
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_x',
                        'type' => 'function',
                        'function' => [
                            'name' => 'getRekapNg',
                            'arguments' => json_encode(['namaProses' => 'X', 'periode' => '2026-07']),
                        ],
                    ]],
                ],
            ]],
        ], 200)]);

        $reply = (new GroqChatbotService(new ChatbotTools()))->ask($this->conversation(), 'halo');

        $this->assertNull($reply);
    }

    public function test_returns_null_when_tool_call_arguments_are_invalid_json(): void
    {
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_bad',
                        'type' => 'function',
                        'function' => [
                            'name' => 'getRekapNg',
                            'arguments' => 'not valid json{{',
                        ],
                    ]],
                ],
            ]],
        ], 200)]);

        $reply = (new GroqChatbotService(new ChatbotTools()))->ask($this->conversation(), 'berapa NG?');

        $this->assertNull($reply);
    }
}
