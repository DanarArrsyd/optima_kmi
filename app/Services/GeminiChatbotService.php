<?php

namespace App\Services;

use App\Contracts\ChatbotProviderInterface;
use App\Services\Chatbot\CapabilityRegistry;
use App\Services\Concerns\HasChatbotSystemPrompt;
use App\Services\Concerns\HasRetryingHttpCall;
use App\Services\Concerns\LogsTokenUsage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiChatbotService implements ChatbotProviderInterface
{
    use HasChatbotSystemPrompt, HasRetryingHttpCall, LogsTokenUsage;

    private const MAX_ITERATIONS = 3;

    public function __construct(
        private ChatbotTools $tools,
        private ?CapabilityRegistry $registry = null,
    ) {}

    /** Registry capability — sumber schema + dispatch. Resolve lazy dari container. */
    private function registry(): CapabilityRegistry
    {
        return $this->registry ??= app(CapabilityRegistry::class);
    }

    public function ask(array $history, string $userMessage): ?string
    {
        $contents = $this->buildHistory($history);
        $contents[] = ['role' => 'user', 'parts' => [['text' => $userMessage]]];

        // Subset schema dihitung sekali dari konteks percakapan (hemat token).
        $past = implode(' ', array_map(fn ($m) => (string) ($m['content'] ?? ''), $history));
        $schema = $this->registry()->schemaForQuery($userMessage.' '.$past);

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $response = $this->callGemini($contents, $schema);
            if ($response === null) {
                return null;
            }
            $this->logGeminiUsage('Gemini', $response);

            $part = $response['candidates'][0]['content']['parts'][0] ?? null;
            if ($part === null) {
                return null;
            }

            if (isset($part['text'])) {
                return $part['text'];
            }

            if (isset($part['functionCall'])) {
                $name = $part['functionCall']['name'] ?? null;
                if ($name === null) {
                    return null;
                }

                $args = $part['functionCall']['args'] ?? [];

                $capability = $this->registry()->find($name);
                // Fallback method_exists jaga-jaga tool callable yang belum jadi capability.
                if ($capability === null && ! method_exists($this->tools, $name)) {
                    return null;
                }

                try {
                    $result = $capability ? $this->registry()->run($capability, $args) : $this->tools->{$name}(...$args);
                } catch (\Throwable $e) {
                    Log::warning('Chatbot tool dispatch failed', ['provider' => 'gemini', 'name' => $name, 'message' => $e->getMessage()]);

                    return null;
                }

                // Empty PHP array encodes to JSON "[]", but Gemini's proto expects an
                // object ("{}") for functionCall.args — cast so a zero-argument tool
                // call (now common with all-time-default tools) round-trips validly.
                $contents[] = ['role' => 'model', 'parts' => [['functionCall' => [
                    'name' => $name,
                    'args' => (object) $args,
                ]]]];
                $contents[] = ['role' => 'function', 'parts' => [[
                    'functionResponse' => ['name' => $name, 'response' => $result],
                ]]];

                continue;
            }

            return null;
        }

        return null;
    }

    private function buildHistory(array $history): array
    {
        return array_map(fn ($m) => [
            'role' => ($m['role'] ?? 'user') === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $m['content'] ?? '']],
        ], $history);
    }

    private function callGemini(array $contents, array $schema): ?array
    {
        $model = config('services.gemini.model');
        $key = config('services.gemini.key');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";
        $body = [
            'system_instruction' => ['parts' => [['text' => $this->systemPrompt()]]],
            'contents' => $contents,
            'tools' => [['functionDeclarations' => $schema]],
        ];

        return $this->withRetry(fn () => Http::timeout(15)->post($url, $body), 'Gemini');
    }
}
