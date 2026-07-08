<?php
namespace App\Services;

use App\Contracts\ChatbotProviderInterface;
use App\Models\ChatConversation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiChatbotService implements ChatbotProviderInterface
{
    private const MAX_ITERATIONS = 5;

    private const SYSTEM_PROMPT = <<<TEXT
        Kamu adalah asisten AI untuk OPTIMA, sistem MES pabrik stamping PT. Kenco Manufactur Indonesia.
        Jawab HANYA berdasarkan hasil pemanggilan tools yang tersedia. Jangan pernah mengarang angka.
        Jika tool mengembalikan 'error', sampaikan pesan error itu ke user secara natural.
        Jawab dalam Bahasa Indonesia, singkat dan jelas.
        TEXT;

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
                    Log::warning('Chatbot tool dispatch failed', ['name' => $name, 'message' => $e->getMessage()]);
                    return null;
                }

                $contents[] = ['role' => 'model', 'parts' => [['functionCall' => $part['functionCall']]]];
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

        try {
            $response = Http::timeout(15)->post(
                "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}",
                [
                    'system_instruction' => ['parts' => [['text' => self::SYSTEM_PROMPT]]],
                    'contents'           => $contents,
                    'tools'              => [['functionDeclarations' => $this->tools->schemaDefinitions()]],
                ]
            );

            if (!$response->successful()) {
                Log::warning('Gemini API non-2xx response', ['status' => $response->status()]);
                return null;
            }

            return $response->json();
        } catch (\Throwable $e) {
            Log::warning('Gemini API call failed', ['message' => $e->getMessage()]);
            return null;
        }
    }
}
