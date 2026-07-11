<?php
namespace App\Services;

use App\Contracts\ChatbotProviderInterface;
use App\Services\Concerns\HasChatbotSystemPrompt;
use App\Services\Concerns\HasRetryingHttpCall;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GroqChatbotService implements ChatbotProviderInterface
{
    use HasChatbotSystemPrompt, HasRetryingHttpCall;

    private const MAX_ITERATIONS = 3;

    public function __construct(private ChatbotTools $tools) {}

    public function ask(array $history, string $userMessage): ?string
    {
        $messages = [['role' => 'system', 'content' => self::SYSTEM_PROMPT]];
        $messages = array_merge($messages, $this->buildHistory($history));
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

                // Model kadang kirim arguments "null"/"" untuk tool tanpa argumen wajib —
                // perlakukan sebagai array kosong, jangan gagalkan seluruh jawaban.
                $args = json_decode($toolCall['function']['arguments'] ?? '[]', true) ?? [];
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

    private function buildHistory(array $history): array
    {
        return array_map(fn ($m) => [
            'role'    => ($m['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user',
            'content' => $m['content'] ?? '',
        ], $history);
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

        return $this->withRetry(
            fn () => Http::timeout(15)->withToken($key)->post('https://api.groq.com/openai/v1/chat/completions', $body),
            'Groq'
        );
    }

    private function toolSchema(): array
    {
        return array_map(fn ($tool) => [
            'type'     => 'function',
            'function' => $tool,
        ], $this->tools->schemaDefinitions());
    }
}
