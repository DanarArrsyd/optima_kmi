<?php
namespace App\Services;

use App\Contracts\ChatbotProviderInterface;
use App\Models\ChatConversation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GroqChatbotService implements ChatbotProviderInterface
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
        $messages = [['role' => 'system', 'content' => self::SYSTEM_PROMPT]];
        $messages = array_merge($messages, $this->buildHistory($conversation));
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $response = $this->callGroq($messages);
            if ($response === null) {
                return null;
            }

            $message = $response['choices'][0]['message'] ?? null;
            if ($message === null) {
                return null;
            }

            $toolCalls = $message['tool_calls'] ?? null;

            if (empty($toolCalls)) {
                return $message['content'] ?? null;
            }

            $messages[] = $message;

            foreach ($toolCalls as $toolCall) {
                $name = $toolCall['function']['name'] ?? null;
                if ($name === null || !method_exists($this->tools, $name)) {
                    return null;
                }

                $args = json_decode($toolCall['function']['arguments'] ?? '[]', true);
                if (!is_array($args)) {
                    return null;
                }

                try {
                    $result = $this->tools->{$name}(...$args);
                } catch (\Throwable $e) {
                    Log::warning('Chatbot tool dispatch failed', ['name' => $name, 'message' => $e->getMessage()]);
                    return null;
                }

                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $toolCall['id'] ?? '',
                    'content'      => json_encode($result),
                ];
            }
        }

        return null;
    }

    private function buildHistory(ChatConversation $conversation): array
    {
        return $conversation->messages->map(fn ($m) => [
            'role'    => $m->role === 'assistant' ? 'assistant' : 'user',
            'content' => $m->content,
        ])->values()->toArray();
    }

    private function callGroq(array $messages): ?array
    {
        $model = config('services.groq.model');
        $key   = config('services.groq.key');

        try {
            $response = Http::timeout(15)
                ->withToken($key)
                ->post('https://api.groq.com/openai/v1/chat/completions', [
                    'model'    => $model,
                    'messages' => $messages,
                    'tools'    => $this->toolSchema(),
                ]);

            if (!$response->successful()) {
                Log::warning('Groq API non-2xx response', ['status' => $response->status()]);
                return null;
            }

            return $response->json();
        } catch (\Throwable $e) {
            Log::warning('Groq API call failed', ['message' => $e->getMessage()]);
            return null;
        }
    }

    private function toolSchema(): array
    {
        return array_map(fn ($tool) => [
            'type'     => 'function',
            'function' => $tool,
        ], $this->tools->schemaDefinitions());
    }
}
