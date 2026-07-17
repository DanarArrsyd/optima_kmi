<?php
namespace Tests\Feature;

use App\Services\Concerns\HasChatbotSystemPrompt;
use App\Services\Concerns\LogsTokenUsage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Kunci fondasi prompt-caching:
 *  1. Prefix system prompt STABIL antar-hari — kalau bagian volatil (tanggal)
 *     bocor ke depan, cache prefix DeepSeek pecah tiap request = biaya penuh.
 *  2. Usage/cache-hit ter-log supaya hematnya kelihatan & terbukti.
 */
class ChatbotPromptCacheTest extends TestCase
{
    private function promptObject(): object
    {
        return new class {
            use HasChatbotSystemPrompt;
            public function build(): string { return $this->systemPrompt(); }
        };
    }

    public function test_system_prompt_prefix_stable_across_days(): void
    {
        $obj = $this->promptObject();

        Carbon::setTestNow('2026-07-17 09:00:00');
        $a = $obj->build();
        Carbon::setTestNow('2026-11-30 22:00:00');
        $b = $obj->build();
        Carbon::setTestNow();

        // Semua SEBELUM baris "Konteks waktu" harus identik — itu prefix yang di-cache.
        $prefixA = substr($a, 0, strpos($a, 'Konteks waktu'));
        $prefixB = substr($b, 0, strpos($b, 'Konteks waktu'));

        $this->assertNotSame('', $prefixA);
        $this->assertSame($prefixA, $prefixB, 'Prefix system prompt harus stabil antar-hari (jangan sisipkan tanggal di depan).');
        $this->assertStringContainsString('2026-07-17', $a);
        $this->assertStringContainsString('2026-11-30', $b);
    }

    public function test_logs_openai_cache_hit_usage(): void
    {
        config(['chatbot.log_token_usage' => true]);
        Log::spy();

        $logger = new class {
            use LogsTokenUsage;
            public function run(array $r): void { $this->logOpenAiUsage('DeepSeek', $r); }
        };

        $logger->run(['usage' => [
            'prompt_tokens' => 1000,
            'completion_tokens' => 120,
            'prompt_cache_hit_tokens' => 800,
        ]]);

        Log::shouldHaveReceived('info')->once()->withArgs(function ($msg, $ctx) {
            return $msg === 'chatbot.usage'
                && $ctx['provider'] === 'DeepSeek'
                && $ctx['cache_hit_tokens'] === 800
                && $ctx['cache_hit_pct'] === 80.0;
        });
    }

    public function test_usage_logging_can_be_disabled(): void
    {
        config(['chatbot.log_token_usage' => false]);
        Log::spy();

        $logger = new class {
            use LogsTokenUsage;
            public function run(array $r): void { $this->logOpenAiUsage('DeepSeek', $r); }
        };

        $logger->run(['usage' => ['prompt_tokens' => 500, 'completion_tokens' => 50]]);

        Log::shouldNotHaveReceived('info');
    }
}
