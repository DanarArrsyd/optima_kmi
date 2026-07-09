<?php
namespace App\Services;

use App\Contracts\ChatbotProviderInterface;
use App\Models\ChatConversation;
use App\Services\Concerns\HasChatbotSystemPrompt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GroqChatbotService implements ChatbotProviderInterface
{
    use HasChatbotSystemPrompt;

    private const MAX_ITERATIONS = 5;

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
                    Log::warning('Chatbot tool dispatch failed', ['provider' => 'groq', 'name' => $name, 'message' => $e->getMessage()]);
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
        $body  = [
            'model'    => $model,
            'messages' => $messages,
            'tools'    => $this->toolSchema(),
        ];

        // Retry 1x dengan backoff untuk status transient (server hiccup / rate-limit).
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $response = Http::timeout(15)
                    ->withToken($key)
                    ->post('https://api.groq.com/openai/v1/chat/completions', $body);

                if ($response->successful()) {
                    return $response->json();
                }

                if ($attempt === 1 && in_array($response->status(), [429, 500, 502, 503], true)) {
                    usleep(900_000);
                    continue;
                }

                Log::warning('Groq API non-2xx response', [
                    'status' => $response->status(),
                    'body'   => Str::limit($response->body(), 500),
                ]);
                return null;
            } catch (\Throwable $e) {
                if ($attempt === 1) {
                    usleep(900_000);
                    continue;
                }
                Log::warning('Groq API call failed', ['message' => $e->getMessage()]);
                return null;
            }
        }

        return null;
    }

    private function toolSchema(): array
    {
        return array_map(fn ($tool) => [
            'type'     => 'function',
            'function' => $tool,
        ], $this->tools->schemaDefinitions());
    }
}
