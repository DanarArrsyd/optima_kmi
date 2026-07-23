<?php

namespace App\Services\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * Observability hemat token: catat pemakaian token per panggilan LLM, termasuk
 * berapa token prompt yang KENA CACHE. DeepSeek (provider utama, berbayar)
 * meng-cache prefix prompt otomatis & menagih token cache-hit jauh lebih murah;
 * tanpa log ini penghematannya tak kelihatan. Log dipakai untuk membuktikan
 * caching benar-benar jalan & mengukur dampaknya.
 *
 * Format usage beda tiap provider, jadi tiap provider menormalkan dulu lalu
 * memanggil logTokenUsage() dengan angka yang seragam.
 */
trait LogsTokenUsage
{
    protected function logTokenUsage(
        string $provider,
        int $promptTokens,
        int $completionTokens,
        int $cacheHitTokens = 0,
    ): void {
        if (! config('chatbot.log_token_usage', true) || $promptTokens <= 0) {
            return;
        }

        Log::info('chatbot.usage', [
            'provider' => $provider,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cache_hit_tokens' => $cacheHitTokens,
            'cache_hit_pct' => round($cacheHitTokens / $promptTokens * 100, 1),
        ]);
    }

    /**
     * Ekstrak usage dari respons OpenAI-compatible (DeepSeek/Groq/Cerebras).
     * DeepSeek menambah prompt_cache_hit_tokens / prompt_cache_miss_tokens.
     */
    protected function logOpenAiUsage(string $provider, ?array $response): void
    {
        $usage = $response['usage'] ?? null;
        if (! is_array($usage)) {
            return;
        }

        $this->logTokenUsage(
            $provider,
            (int) ($usage['prompt_tokens'] ?? 0),
            (int) ($usage['completion_tokens'] ?? 0),
            (int) ($usage['prompt_cache_hit_tokens'] ?? 0),
        );
    }

    /** Ekstrak usage dari respons Gemini (usageMetadata + cachedContentTokenCount). */
    protected function logGeminiUsage(string $provider, ?array $response): void
    {
        $usage = $response['usageMetadata'] ?? null;
        if (! is_array($usage)) {
            return;
        }

        $this->logTokenUsage(
            $provider,
            (int) ($usage['promptTokenCount'] ?? 0),
            (int) ($usage['candidatesTokenCount'] ?? 0),
            (int) ($usage['cachedContentTokenCount'] ?? 0),
        );
    }
}
