<?php
namespace Tests\Unit;

use App\Services\ChatbotTools;
use App\Services\GeminiChatbotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiChatbotServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_gemini_text_reply_directly_when_no_tool_call_needed(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['role' => 'model', 'parts' => [['text' => 'Halo, ada yang bisa gw bantu?']]],
                ]],
            ], 200),
        ]);

        $reply = (new GeminiChatbotService(new ChatbotTools()))->ask([], 'halo');

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

        $reply = (new GeminiChatbotService(new ChatbotTools()))->ask([], 'berapa NG bending bulan lalu?');

        $this->assertSame('NG di proses BENDING bulan itu 0.', $reply);
        Http::assertSentCount(2);
    }

    public function test_returns_null_when_gemini_api_fails(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 500)]);

        $reply = (new GeminiChatbotService(new ChatbotTools()))->ask([], 'halo');

        $this->assertNull($reply);
    }

    public function test_returns_null_when_loop_exceeds_max_iterations(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [[
                    'functionCall' => ['name' => 'getRekapNg', 'args' => ['namaProses' => 'X', 'periode' => '2026-07']],
                ]]],
            ]],
        ], 200)]);

        $reply = (new GeminiChatbotService(new ChatbotTools()))->ask([], 'halo');

        $this->assertNull($reply);
    }

    public function test_returns_null_when_tool_call_has_missing_required_args(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [[
                    'functionCall' => ['name' => 'getMtbfMttrDies', 'args' => ['partNo' => 'BT-091A-3']],
                ]]],
            ]],
        ], 200)]);

        $reply = (new GeminiChatbotService(new ChatbotTools()))->ask([], 'berapa MTBF dies itu?');

        $this->assertNull($reply);
    }

    public function test_zero_argument_tool_call_is_echoed_back_as_json_object_not_array(): void
    {
        $call = 0;
        $capturedBody = null;
        Http::fake(function ($request) use (&$call, &$capturedBody) {
            $call++;
            if ($call === 1) {
                return Http::response([
                    'candidates' => [[
                        'content' => ['role' => 'model', 'parts' => [[
                            'functionCall' => ['name' => 'getTopProblemDies', 'args' => new \stdClass()],
                        ]]],
                    ]],
                ], 200);
            }

            $capturedBody = $request->body();

            return Http::response([
                'candidates' => [[
                    'content' => ['role' => 'model', 'parts' => [['text' => 'Problem paling sering: motor overheat.']]],
                ]],
            ], 200);
        });

        $reply = (new GeminiChatbotService(new ChatbotTools()))->ask([], 'problem dies apa yang paling sering muncul?');

        $this->assertSame('Problem paling sering: motor overheat.', $reply);
        $this->assertNotNull($capturedBody);
        $this->assertStringNotContainsString('"args":[]', $capturedBody, 'Empty functionCall.args must be encoded as a JSON object ({}), not an array ([]), or Gemini rejects the request with a 400 INVALID_ARGUMENT.');
    }

    public function test_prepends_history_mapping_assistant_to_model_role(): void
    {
        $captured = null;
        Http::fake(function ($request) use (&$captured) {
            $captured = $request->data()['contents'];
            return Http::response([
                'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'oke']]]]],
            ], 200);
        });

        $history = [
            ['role' => 'user', 'content' => 'pertama'],
            ['role' => 'assistant', 'content' => 'jawaban pertama'],
        ];

        (new GeminiChatbotService(new ChatbotTools()))->ask($history, 'kedua');

        $this->assertSame('user', $captured[0]['role']);
        $this->assertSame('pertama', $captured[0]['parts'][0]['text']);
        $this->assertSame('model', $captured[1]['role']);
        $this->assertSame('kedua', $captured[2]['parts'][0]['text']);
    }
}
