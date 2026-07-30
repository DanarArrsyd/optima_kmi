<?php

namespace App\Services;

use App\Contracts\ChatbotProviderInterface;
use App\Services\Chatbot\CapabilityRegistry;
use App\Services\Concerns\HasChatbotSystemPrompt;
use App\Services\Concerns\HasRetryingHttpCall;
use App\Services\Concerns\LogsTokenUsage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Basis provider chat OpenAI-compatible (chat/completions + function tools).
 * Groq, Cerebras, DeepSeek, OpenAI, dll cuma beda endpoint/key/model.
 */
abstract class OpenAiCompatibleChatbotService implements ChatbotProviderInterface
{
    use HasChatbotSystemPrompt, HasRetryingHttpCall, LogsTokenUsage;

    private const MAX_ITERATIONS = 3;

    public function __construct(
        protected ChatbotTools $tools,
        protected ?CapabilityRegistry $registry = null,
    ) {}

    /** Registry capability — sumber schema + dispatch. Resolve lazy dari container. */
    protected function registry(): CapabilityRegistry
    {
        return $this->registry ??= app(CapabilityRegistry::class);
    }

    abstract protected function endpoint(): string;

    abstract protected function apiKey(): ?string;

    abstract protected function model(): ?string;

    abstract protected function providerLabel(): string;

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey()) && ! empty($this->model());
    }

    public function ask(array $history, string $userMessage): ?string
    {
        // Provider tak dikonfigurasi (key/model kosong) → lewati, biar fallback jalan.
        if (! $this->isConfigured()) {
            return null;
        }

        $messages = [['role' => 'system', 'content' => $this->systemPrompt()]];
        $messages = array_merge($messages, $this->buildHistory($history));
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        // Subset schema dihitung SEKALI dari konteks percakapan (history + pesan),
        // lalu dipakai untuk semua iterasi tool-loop — hemat token schema.
        $toolSchema = $this->toolSchema($this->relevanceContext($history, $userMessage));

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $response = $this->call($messages, $toolSchema);
            if ($response === null) {
                return null;
            }
            $this->logOpenAiUsage($this->providerLabel(), $response);

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
                $capability = $name !== null ? $this->registry()->find($name) : null;
                // Fallback method_exists jaga-jaga tool callable yang belum jadi capability.
                if ($capability === null && ($name === null || ! method_exists($this->tools, $name))) {
                    return null;
                }

                // Model kadang kirim arguments "null"/"" untuk tool tanpa argumen wajib —
                // perlakukan sebagai array kosong, jangan gagalkan seluruh jawaban.
                $args = json_decode($toolCall['function']['arguments'] ?? '[]', true) ?? [];
                if (! is_array($args)) {
                    return null;
                }

                try {
                    $result = $capability ? $this->registry()->run($capability, $args) : $this->tools->{$name}(...$args);
                } catch (\Throwable $e) {
                    Log::warning('Chatbot tool dispatch failed', ['provider' => $this->providerLabel(), 'name' => $name, 'message' => $e->getMessage()]);

                    return null;
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'] ?? '',
                    'content' => json_encode($result),
                ];
            }
        }

        return null;
    }

    private function buildHistory(array $history): array
    {
        return array_map(fn ($m) => [
            'role' => ($m['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user',
            'content' => $m['content'] ?? '',
        ], $history);
    }

    private function call(array $messages, array $toolSchema): ?array
    {
        return $this->withRetry(
            fn () => Http::timeout(20)->withToken($this->apiKey())->post($this->endpoint(), [
                'model' => $this->model(),
                'messages' => $messages,
                'tools' => $toolSchema,
            ]),
            $this->providerLabel()
        );
    }

    /** Teks konteks untuk relevansi tool-subsetting: pesan sekarang + isi history. */
    private function relevanceContext(array $history, string $userMessage): string
    {
        $past = implode(' ', array_map(fn ($m) => (string) ($m['content'] ?? ''), $history));

        return $userMessage.' '.$past;
    }

    private function toolSchema(string $context): array
    {
        return array_map(fn ($tool) => [
            'type' => 'function',
            'function' => $tool,
        ], $this->registry()->schemaForQuery($context));
    }
}
