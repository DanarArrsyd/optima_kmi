<?php
namespace App\Services;

use App\Contracts\ChatbotProviderInterface;
use App\Models\ChatConversation;
use App\Services\Concerns\HasChatbotSystemPrompt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GeminiChatbotService implements ChatbotProviderInterface
{
    use HasChatbotSystemPrompt;

    private const MAX_ITERATIONS = 5;

    public function __construct(private ChatbotTools $tools) {}

    public function ask(ChatConversation $conversation, string $userMessage): ?string
    {
        $contents = $this->buildHistory($conversation);
        $contents[] = ['role' => 'user', 'parts' => [['text' => $userMessage]]];

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $response = $this->callGemini($contents);
            if ($response === null) {
                return null;
            }

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

                if (!method_exists($this->tools, $name)) {
                    return null;
                }

                try {
                    $result = $this->tools->{$name}(...$args);
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

    private function buildHistory(ChatConversation $conversation): array
    {
        return $conversation->messages->map(fn ($m) => [
            'role'  => $m->role === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $m->content]],
        ])->values()->toArray();
    }

    private function callGemini(array $contents): ?array
    {
        $model = config('services.gemini.model');
        $key   = config('services.gemini.key');
        $url   = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";
        $body  = [
            'system_instruction' => ['parts' => [['text' => self::SYSTEM_PROMPT]]],
            'contents'           => $contents,
            'tools'              => [['functionDeclarations' => $this->tools->schemaDefinitions()]],
        ];

        // Retry 1x dengan backoff untuk status transient (rate-limit / server hiccup),
        // supaya burst RPM yang pulih cepat tidak langsung jatuh ke FALLBACK.
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $response = Http::timeout(15)->post($url, $body);

                if ($response->successful()) {
                    return $response->json();
                }

                if ($attempt === 1 && in_array($response->status(), [429, 500, 502, 503], true)) {
                    usleep(900_000);
                    continue;
                }

                Log::warning('Gemini API non-2xx response', [
                    'status' => $response->status(),
                    'body'   => Str::limit($response->body(), 500),
                ]);
                return null;
            } catch (\Throwable $e) {
                if ($attempt === 1) {
                    usleep(900_000);
                    continue;
                }
                Log::warning('Gemini API call failed', ['message' => $e->getMessage()]);
                return null;
            }
        }

        return null;
    }
}
