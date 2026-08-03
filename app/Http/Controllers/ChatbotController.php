<?php

namespace App\Http\Controllers;

use App\Services\CerebrasChatbotService;
use App\Services\DeepseekChatbotService;
use App\Services\GeminiChatbotService;
use App\Services\GroqChatbotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatbotController extends Controller
{
    /** Semua provider dicoba dan gagal — gangguan, menunggu masuk akal. */
    private const FALLBACK = 'Maaf, sistem AI lagi ga bisa diakses, coba lagi nanti.';

    /**
     * Nol provider punya API key. Ini salah setup, bukan gangguan — menyuruh
     * "coba lagi nanti" hanya menunda orang dari perbaikan yang sebenarnya.
     */
    private const FALLBACK_TANPA_KONFIGURASI = 'Layanan AI belum dikonfigurasi: tidak ada provider yang punya API key. '
        .'Hubungi admin untuk mengisi key-nya — menunggu tidak akan memperbaiki ini.';

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
     *
     * Tiap provider dicatat hasilnya — 'ok', 'gagal', atau 'tanpa_key'. Pemisahan
     * itu yang membuat kegagalan bisa didiagnosa: `ask()` mengembalikan null untuk
     * dua sebab yang sangat berbeda, dan sebelumnya keduanya berakhir di pesan
     * "coba lagi nanti" yang sama. Kalau sebabnya nol key, menunggu tidak akan
     * memperbaiki apa pun.
     */
    private function resolveReply(array $history, string $message): string
    {
        $rantai = [
            'deepseek' => $this->deepseek,
            'cerebras' => $this->cerebras,
            'groq' => $this->groq,
            'gemini' => $this->gemini,
        ];

        $status = [];

        foreach ($rantai as $nama => $provider) {
            if (! $provider->isConfigured()) {
                $status[$nama] = 'tanpa_key';

                continue;
            }

            $jawaban = $provider->ask($history, $message);
            if ($jawaban !== null) {
                $status[$nama] = 'ok';

                return $jawaban;
            }

            $status[$nama] = 'gagal';
        }

        $adaYangTerkonfigurasi = in_array('gagal', $status, true);

        Log::error('Chatbot: seluruh rantai provider habis', [
            'provider' => $status,
            'sebab' => $adaYangTerkonfigurasi ? 'semua provider terkonfigurasi gagal' : 'nol provider terkonfigurasi',
        ]);

        return $adaYangTerkonfigurasi ? self::FALLBACK : self::FALLBACK_TANPA_KONFIGURASI;
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
