<?php
namespace App\Services;

use App\Contracts\ChatbotProviderInterface;
use App\Models\ChatConversation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GeminiChatbotService implements ChatbotProviderInterface
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
