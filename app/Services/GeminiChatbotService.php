<?php
namespace App\Services;

use App\Models\ChatConversation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiChatbotService
{
    private const MAX_ITERATIONS = 5;
    private const FALLBACK = 'Maaf, sistem AI lagi ga bisa diakses, coba lagi nanti.';

    private const SYSTEM_PROMPT = <<<TEXT
        Kamu adalah asisten AI untuk OPTIMA, sistem MES pabrik stamping PT. Kenco Manufactur Indonesia.
        Jawab HANYA berdasarkan hasil pemanggilan tools yang tersedia. Jangan pernah mengarang angka.
        Jika tool mengembalikan 'error', sampaikan pesan error itu ke user secara natural.
        Jawab dalam Bahasa Indonesia, singkat dan jelas.
        TEXT;

    public function __construct(private ChatbotTools $tools) {}

    public function ask(ChatConversation $conversation, string $userMessage): string
    {
        $contents = $this->buildHistory($conversation);
        $contents[] = ['role' => 'user', 'parts' => [['text' => $userMessage]]];

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $response = $this->callGemini($contents);
            if ($response === null) {
                return self::FALLBACK;
            }

            $part = $response['candidates'][0]['content']['parts'][0] ?? null;
            if ($part === null) {
                return self::FALLBACK;
            }

            if (isset($part['text'])) {
                return $part['text'];
            }

            if (isset($part['functionCall'])) {
                $name = $part['functionCall']['name'] ?? null;
                if ($name === null) {
                    return self::FALLBACK;
                }

                $args = $part['functionCall']['args'] ?? [];

                if (!method_exists($this->tools, $name)) {
                    return self::FALLBACK;
                }

                try {
                    $result = $this->tools->{$name}(...$args);
                } catch (\Throwable $e) {
                    Log::warning('Chatbot tool dispatch failed', ['name' => $name, 'message' => $e->getMessage()]);
                    return self::FALLBACK;
                }

                $contents[] = ['role' => 'model', 'parts' => [['functionCall' => $part['functionCall']]]];
                $contents[] = ['role' => 'function', 'parts' => [[
                    'functionResponse' => ['name' => $name, 'response' => $result],
                ]]];
                continue;
            }

            return self::FALLBACK;
        }

        return self::FALLBACK;
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
                    'tools'              => [['functionDeclarations' => $this->toolSchema()]],
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

    private function toolSchema(): array
    {
        return [
            [
                'name' => 'getMtbfMttrDies',
                'description' => 'Ambil MTBF/MTTR untuk sebuah part/dies pada bulan tertentu, per proses.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'partNo' => ['type' => 'string', 'description' => 'Nomor part/dies, contoh: BT-091A-3'],
                        'bulan'  => ['type' => 'string', 'description' => 'Format YYYY-MM, contoh: 2026-07'],
                    ],
                    'required' => ['partNo', 'bulan'],
                ],
            ],
            [
                'name' => 'getMtbfMttrMesin',
                'description' => 'Ambil MTBF/MTTR untuk sebuah mesin pada bulan tertentu.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'idMesin' => ['type' => 'string', 'description' => 'Kode mesin, contoh: M-01'],
                        'bulan'   => ['type' => 'string', 'description' => 'Format YYYY-MM'],
                    ],
                    'required' => ['idMesin', 'bulan'],
                ],
            ],
            [
                'name' => 'getRekapNg',
                'description' => 'Rekap total qty NG dan NG rate untuk sebuah nama proses pada bulan tertentu.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'namaProses' => ['type' => 'string', 'description' => 'Nama proses, contoh: BENDING'],
                        'periode'    => ['type' => 'string', 'description' => 'Format YYYY-MM'],
                    ],
                    'required' => ['namaProses', 'periode'],
                ],
            ],
            [
                'name' => 'bandingkanProduksi',
                'description' => 'Bandingkan total qty aktual & SPH rata-rata produksi antara 2 bulan.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'periode1' => ['type' => 'string', 'description' => 'Format YYYY-MM, periode pertama'],
                        'periode2' => ['type' => 'string', 'description' => 'Format YYYY-MM, periode kedua'],
                    ],
                    'required' => ['periode1', 'periode2'],
                ],
            ],
        ];
    }
}
