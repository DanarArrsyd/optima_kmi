<?php

namespace App\Http\Controllers;

use App\Services\CerebrasChatbotService;
use App\Services\ChatbotTools;
use App\Services\DeepseekChatbotService;
use App\Services\GeminiChatbotService;
use App\Services\GroqChatbotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatbotController extends Controller
{
    private const FALLBACK = 'Maaf, sistem AI lagi ga bisa diakses, coba lagi nanti.';

    public function __construct(
        private DeepseekChatbotService $deepseek,
        private CerebrasChatbotService $cerebras,
        private GeminiChatbotService $gemini,
        private GroqChatbotService $groq,
    ) {}

    public function index(): View
    {
        return view('chatbot.index');
    }

    /**
     * DIAGNOSTIK SEMENTARA — hapus setelah AI provider produksi terpecahkan.
     * Probe kedua provider (dengan & tanpa tools) untuk mengisolasi penyebab
     * fallback "Maaf,": key hilang, model stale, tools ditolak, atau egress blok.
     * Tidak membocorkan API key (hanya boolean key_set + model + status/body error).
     */
    public function health(ChatbotTools $tools): JsonResponse
    {
        $gKey = config('services.gemini.key');
        $gModel = config('services.gemini.model');
        $qKey = config('services.groq.key');
        $qModel = config('services.groq.model');
        $cKey = config('services.cerebras.key');
        $cModel = config('services.cerebras.model');
        $dKey = config('services.deepseek.key');
        $dModel = config('services.deepseek.model');
        $schema = $tools->schemaDefinitions();

        $probe = function (callable $call): array {
            try {
                $r = $call();

                return ['status' => $r->status(), 'ok' => $r->successful(), 'body' => $r->successful() ? '(ok)' : Str::limit($r->body(), 600)];
            } catch (\Throwable $e) {
                return ['status' => 'EXCEPTION', 'ok' => false, 'body' => $e->getMessage()];
            }
        };

        $geminiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$gModel}:generateContent?key={$gKey}";
        $groqUrl = 'https://api.groq.com/openai/v1/chat/completions';
        $cerebrasUrl = 'https://api.cerebras.ai/v1/chat/completions';
        $deepseekUrl = 'https://api.deepseek.com/chat/completions';

        return response()->json([
            'deepseek' => [
                'key_set' => (bool) $dKey,
                'model' => $dModel,
                'no_tools' => $probe(fn () => Http::timeout(20)->withToken($dKey)->post($deepseekUrl, [
                    'model' => $dModel, 'messages' => [['role' => 'user', 'content' => 'balas: ok']],
                ])),
                'with_tools' => $probe(fn () => Http::timeout(20)->withToken($dKey)->post($deepseekUrl, [
                    'model' => $dModel,
                    'messages' => [['role' => 'user', 'content' => 'balas: ok']],
                    'tools' => array_map(fn ($t) => ['type' => 'function', 'function' => $t], $schema),
                ])),
            ],
            'cerebras' => [
                'key_set' => (bool) $cKey,
                'model' => $cModel,
                'available_models' => (function () use ($cKey) {
                    try {
                        $r = Http::timeout(20)->withToken($cKey)->get('https://api.cerebras.ai/v1/models');

                        return $r->successful()
                            ? array_map(fn ($m) => $m['id'] ?? '?', $r->json('data') ?? [])
                            : ['error' => $r->status(), 'body' => Str::limit($r->body(), 300)];
                    } catch (\Throwable $e) {
                        return ['error' => $e->getMessage()];
                    }
                })(),
                'no_tools' => $probe(fn () => Http::timeout(20)->withToken($cKey)->post($cerebrasUrl, [
                    'model' => $cModel, 'messages' => [['role' => 'user', 'content' => 'balas: ok']],
                ])),
                'with_tools' => $probe(fn () => Http::timeout(20)->withToken($cKey)->post($cerebrasUrl, [
                    'model' => $cModel,
                    'messages' => [['role' => 'user', 'content' => 'balas: ok']],
                    'tools' => array_map(fn ($t) => ['type' => 'function', 'function' => $t], $schema),
                ])),
            ],
            'gemini' => [
                'key_set' => (bool) $gKey,
                'model' => $gModel,
                'no_tools' => $probe(fn () => Http::timeout(20)->post($geminiUrl, [
                    'contents' => [['role' => 'user', 'parts' => [['text' => 'balas: ok']]]],
                ])),
                'with_tools' => $probe(fn () => Http::timeout(20)->post($geminiUrl, [
                    'contents' => [['role' => 'user', 'parts' => [['text' => 'balas: ok']]]],
                    'tools' => [['functionDeclarations' => $schema]],
                ])),
            ],
            'groq' => [
                'key_set' => (bool) $qKey,
                'model' => $qModel,
                'no_tools' => $probe(fn () => Http::timeout(20)->withToken($qKey)->post($groqUrl, [
                    'model' => $qModel, 'messages' => [['role' => 'user', 'content' => 'balas: ok']],
                ])),
                'with_tools' => $probe(fn () => Http::timeout(20)->withToken($qKey)->post($groqUrl, [
                    'model' => $qModel,
                    'messages' => [['role' => 'user', 'content' => 'balas: ok']],
                    'tools' => array_map(fn ($t) => ['type' => 'function', 'function' => $t], $schema),
                ])),
            ],
        ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /** Aturan validasi request chat, dipakai bareng sendMessage & stream. */
    private const MESSAGE_RULES = [
        'message' => ['required', 'string', 'max:2000'],
        'history' => ['nullable', 'array', 'max:20'],
        'history.*.role' => ['required', 'in:user,assistant'],
        'history.*.content' => ['required', 'string', 'max:4000'],
    ];

    public function sendMessage(Request $request): JsonResponse
    {
        $data = $request->validate(self::MESSAGE_RULES);
        $reply = $this->resolveReply($data['history'] ?? [], $data['message']);

        return response()->json([
            'reply' => $reply,
            'suggestions' => $this->suggestFollowUps($data['message'], $reply),
        ]);
    }

    /**
     * Varian streaming (Server-Sent Events). Jawaban dihasilkan sync (tool-loop
     * butuh hasil tool utuh), lalu dikirim bertahap sebagai event SSE supaya
     * frontend bisa render progresif. TANPA sleep di server — begitu jawaban
     * jadi, chunk di-flush cepat; worker tak ditahan lebih lama dari generasi.
     * Cadence "ngetik" diatur di sisi klien. /chatbot/message tetap jadi fallback.
     */
    public function stream(Request $request): StreamedResponse
    {
        $data = $request->validate(self::MESSAGE_RULES);
        $history = $data['history'] ?? [];
        $message = $data['message'];

        return response()->stream(function () use ($history, $message) {
            // ask() di dalam closure: koneksi kebuka duluan (klien tampil "mengetik"),
            // baru streaming hasil begitu siap.
            $reply = $this->resolveReply($history, $message);
            $suggestions = $this->suggestFollowUps($message, $reply);

            foreach ($this->chunkReply($reply) as $chunk) {
                $this->sse(['delta' => $chunk]);
            }
            $this->sse(['done' => true, 'suggestions' => $suggestions], 'done');
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no', // matikan buffering nginx/proxy
        ]);
    }

    /**
     * Rantai fallback provider: DeepSeek (paid, andal) → Cerebras → Groq → Gemini.
     * Provider tanpa key otomatis di-skip (ask() balikin null).
     */
    private function resolveReply(array $history, string $message): string
    {
        return $this->deepseek->ask($history, $message)
            ?? $this->cerebras->ask($history, $message)
            ?? $this->groq->ask($history, $message)
            ?? $this->gemini->ask($history, $message)
            ?? self::FALLBACK;
    }

    /** Pecah jawaban jadi potongan kecil untuk SSE (per kata, spasi dipertahankan). */
    private function chunkReply(string $reply): array
    {
        return preg_split('/(?<=\s)/u', $reply, -1, PREG_SPLIT_NO_EMPTY) ?: [$reply];
    }

    /** Kirim satu event SSE dan flush. */
    private function sse(array $payload, ?string $event = null): void
    {
        if ($event !== null) {
            echo "event: {$event}\n";
        }
        echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE)."\n\n";

        if (function_exists('ob_get_level') && ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }

    /**
     * Saran follow-up kontekstual (rule-based, tanpa call LLM tambahan).
     * Deteksi topik dari pertanyaan + jawaban terakhir, lalu tawarkan pertanyaan
     * lanjutan yang relevan. Backfill acak dari pool umum sampai 3 slot terisi.
     */
    private function suggestFollowUps(string $userMessage, string $reply): array
    {
        $hay = strtolower($userMessage.' '.$reply);

        $sets = [
            'dandori' => [
                'Jenis losstime dandori apa yang paling sering muncul?',
                'Dandori losstime apa yang paling banyak makan waktu?',
                'Berapa total over menit dandori keseluruhan?',
            ],
            'dies' => [
                'Dies mana yang paling sering breakdown?',
                'Penyebab breakdown dies yang paling umum apa?',
                'Berapa total kejadian breakdown dies?',
            ],
            'mesin' => [
                'Mesin mana yang paling sering breakdown?',
                'Problem mesin apa yang paling sering terjadi?',
                'Ranking mesin paling bermasalah urutannya gimana?',
            ],
            'produksi' => [
                'Total produksi keseluruhan berapa Pcs?',
                'Rata-rata SPH produksi berapa?',
                'Bandingkan produksi bulan ini dengan bulan lalu.',
            ],
        ];

        // Urutan cek penting: dandori & dies diperiksa sebelum mesin
        // supaya "breakdown dies" tidak ketangkap sebagai topik mesin.
        $topic = match (true) {
            Str::contains($hay, ['dandori', 'losstime', 'setup']) => 'dandori',
            Str::contains($hay, ['dies']) => 'dies',
            Str::contains($hay, ['mesin', 'breakdown']) => 'mesin',
            Str::contains($hay, ['produksi', 'sph', 'pcs', 'qty']) => 'produksi',
            default => null,
        };

        $pool = [
            'Problem dies apa yang paling sering muncul?',
            'Mesin apa yang paling sering rusak?',
            'Dandori losstime apa yang paling banyak makan waktu?',
            'Total produksi keseluruhan berapa Pcs?',
            'Problem mesin apa yang paling sering terjadi?',
            'Rata-rata SPH produksi berapa?',
        ];

        $norm = fn ($q) => strtolower(trim($q));
        $asked = $norm($userMessage);

        // Saran kontekstual dulu, buang yang sama persis dengan pertanyaan barusan.
        $picked = array_values(array_filter($sets[$topic] ?? [], fn ($q) => $norm($q) !== $asked));

        // Backfill acak dari pool umum sampai 3, tanpa duplikat.
        $extra = array_values(array_filter(
            $pool,
            fn ($q) => $norm($q) !== $asked && ! in_array($q, $picked, true)
        ));
        shuffle($extra);
        while (count($picked) < 3 && $extra) {
            $picked[] = array_shift($extra);
        }

        return array_slice($picked, 0, 3);
    }
}
