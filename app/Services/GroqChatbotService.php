<?php
namespace App\Services;

use App\Contracts\ChatbotProviderInterface;
use App\Models\ChatConversation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GroqChatbotService implements ChatbotProviderInterface
{
    private const MAX_ITERATIONS = 5;

    private const SYSTEM_PROMPT = <<<TEXT
        Kamu adalah asisten AI untuk OPTIMA, sistem MES pabrik stamping PT. Kenco Manufactur Indonesia.
        Jawab HANYA berdasarkan hasil pemanggilan tools yang tersedia. Jangan pernah mengarang angka.
        Kalau tool mengembalikan daftar data mentah (misalnya list problem/penyebab/tindakan), rangkum HANYA pola yang benar-benar muncul berulang di data tersebut — jangan menyimpulkan penyebab teknis atau solusi yang tidak tertulis literal di data.
        Kalau user tidak menyebutkan periode, JANGAN tanya balik ke user — langsung panggil tool tanpa parameter periode (otomatis pakai semua data). Dalam kasus ini JANGAN isi parameter bulan/periode (bulanMulai, bulanAkhir, periode, bulan) dengan tebakan atau tanggal hari ini — biarkan kosong sepenuhnya. Kalau user menyebut bulan, kirim SELALU dalam format YYYY-MM (contoh 2026-07). Untuk pertanyaan "X apa yang PALING SERING/PALING BANYAK muncul", gunakan tool ranking (getTopProblemDies, getTopProblemMesin, getRankingBreakdownDies, getRankingBreakdownMesin) — bukan tool daftar mentah (getProblemDies, getProblemMesin) — kecuali user secara eksplisit minta detail/daftar tiap kejadian.
        Jika tool mengembalikan field 'error' atau 'catatan', sampaikan isinya ke user dengan bahasa percakapan biasa. JANGAN PERNAH menyebut nama tool/fungsi (misal getRekapDandori), kata "tools", atau kata "error" ke user. Kalau isinya soal data tidak ada/belum tercatat, jawab santai bahwa datanya belum ada — bukan seolah sistem rusak.
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
