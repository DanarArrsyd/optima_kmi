<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use App\Services\ChatbotTools;
use App\Services\CerebrasChatbotService;
use App\Services\GeminiChatbotService;
use App\Services\GroqChatbotService;

class ChatbotController extends Controller
{
    private const FALLBACK = 'Maaf, sistem AI lagi ga bisa diakses, coba lagi nanti.';

    public function __construct(
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
        $gKey   = config('services.gemini.key');
        $gModel = config('services.gemini.model');
        $qKey   = config('services.groq.key');
        $qModel = config('services.groq.model');
        $cKey   = config('services.cerebras.key');
        $cModel = config('services.cerebras.model');
        $schema = $tools->schemaDefinitions();

        $probe = function (callable $call): array {
            try {
                $r = $call();
                return ['status' => $r->status(), 'ok' => $r->successful(), 'body' => $r->successful() ? '(ok)' : Str::limit($r->body(), 600)];
            } catch (\Throwable $e) {
                return ['status' => 'EXCEPTION', 'ok' => false, 'body' => $e->getMessage()];
            }
        };

        $geminiUrl   = "https://generativelanguage.googleapis.com/v1beta/models/{$gModel}:generateContent?key={$gKey}";
        $groqUrl     = 'https://api.groq.com/openai/v1/chat/completions';
        $cerebrasUrl = 'https://api.cerebras.ai/v1/chat/completions';

        return response()->json([
            'cerebras' => [
                'key_set'    => (bool) $cKey,
                'model'      => $cModel,
                'no_tools'   => $probe(fn () => Http::timeout(20)->withToken($cKey)->post($cerebrasUrl, [
                    'model' => $cModel, 'messages' => [['role' => 'user', 'content' => 'balas: ok']],
                ])),
                'with_tools' => $probe(fn () => Http::timeout(20)->withToken($cKey)->post($cerebrasUrl, [
                    'model'    => $cModel,
                    'messages' => [['role' => 'user', 'content' => 'balas: ok']],
                    'tools'    => array_map(fn ($t) => ['type' => 'function', 'function' => $t], $schema),
                ])),
            ],
            'gemini' => [
                'key_set'    => (bool) $gKey,
                'model'      => $gModel,
                'no_tools'   => $probe(fn () => Http::timeout(20)->post($geminiUrl, [
                    'contents' => [['role' => 'user', 'parts' => [['text' => 'balas: ok']]]],
                ])),
                'with_tools' => $probe(fn () => Http::timeout(20)->post($geminiUrl, [
                    'contents' => [['role' => 'user', 'parts' => [['text' => 'balas: ok']]]],
                    'tools'    => [['functionDeclarations' => $schema]],
                ])),
            ],
            'groq' => [
                'key_set'    => (bool) $qKey,
                'model'      => $qModel,
                'no_tools'   => $probe(fn () => Http::timeout(20)->withToken($qKey)->post($groqUrl, [
                    'model' => $qModel, 'messages' => [['role' => 'user', 'content' => 'balas: ok']],
                ])),
                'with_tools' => $probe(fn () => Http::timeout(20)->withToken($qKey)->post($groqUrl, [
                    'model'    => $qModel,
                    'messages' => [['role' => 'user', 'content' => 'balas: ok']],
                    'tools'    => array_map(fn ($t) => ['type' => 'function', 'function' => $t], $schema),
                ])),
            ],
        ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function sendMessage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message'           => ['required', 'string', 'max:2000'],
            'history'           => ['nullable', 'array', 'max:20'],
            'history.*.role'    => ['required', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:4000'],
        ]);

        $history = $data['history'] ?? [];

        // Urutan fallback: Cerebras (free tier lebih lega) → Groq → Gemini.
        // Provider tanpa key otomatis di-skip (ask() balikin null), jadi aman
        // walau CEREBRAS_API_KEY belum diisi.
        $reply = $this->cerebras->ask($history, $data['message'])
            ?? $this->groq->ask($history, $data['message'])
            ?? $this->gemini->ask($history, $data['message'])
            ?? self::FALLBACK;

        return response()->json([
            'reply'       => $reply,
            'suggestions' => $this->suggestFollowUps($data['message'], $reply),
        ]);
    }

    /**
     * Saran follow-up kontekstual (rule-based, tanpa call LLM tambahan).
     * Deteksi topik dari pertanyaan + jawaban terakhir, lalu tawarkan pertanyaan
     * lanjutan yang relevan. Backfill acak dari pool umum sampai 3 slot terisi.
     */
    private function suggestFollowUps(string $userMessage, string $reply): array
    {
        $hay = strtolower($userMessage . ' ' . $reply);

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
            Str::contains($hay, ['dandori', 'losstime', 'setup'])   => 'dandori',
            Str::contains($hay, ['dies'])                            => 'dies',
            Str::contains($hay, ['mesin', 'breakdown'])             => 'mesin',
            Str::contains($hay, ['produksi', 'sph', 'pcs', 'qty'])  => 'produksi',
            default                                                  => null,
        };

        $pool = [
            'Problem dies apa yang paling sering muncul?',
            'Mesin apa yang paling sering rusak?',
            'Dandori losstime apa yang paling banyak makan waktu?',
            'Total produksi keseluruhan berapa Pcs?',
            'Problem mesin apa yang paling sering terjadi?',
            'Rata-rata SPH produksi berapa?',
        ];

        $norm   = fn ($q) => strtolower(trim($q));
        $asked  = $norm($userMessage);

        // Saran kontekstual dulu, buang yang sama persis dengan pertanyaan barusan.
        $picked = array_values(array_filter($sets[$topic] ?? [], fn ($q) => $norm($q) !== $asked));

        // Backfill acak dari pool umum sampai 3, tanpa duplikat.
        $extra = array_values(array_filter(
            $pool,
            fn ($q) => $norm($q) !== $asked && !in_array($q, $picked, true)
        ));
        shuffle($extra);
        while (count($picked) < 3 && $extra) {
            $picked[] = array_shift($extra);
        }

        return array_slice($picked, 0, 3);
    }
}
