<?php
namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Services\ChatbotTools;
use App\Services\GeminiChatbotService;
use App\Services\GroqChatbotService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ChatbotController extends Controller
{
    private const FALLBACK = 'Maaf, sistem AI lagi ga bisa diakses, coba lagi nanti.';

    public function __construct(
        private GeminiChatbotService $gemini,
        private GroqChatbotService $groq,
    ) {}

    public function index(): View
    {
        return view('chatbot.index');
    }

    public function sendMessage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message'         => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'integer', 'exists:chat_conversations,id'],
        ]);

        $conversation = ($data['conversation_id'] ?? null)
            ? ChatConversation::where('user_id', $request->user()->id)->findOrFail($data['conversation_id'])
            : ChatConversation::create(['user_id' => $request->user()->id]);

        $reply = $this->gemini->ask($conversation->fresh('messages'), $data['message'])
            ?? $this->groq->ask($conversation->fresh('messages'), $data['message'])
            ?? self::FALLBACK;

        $conversation->messages()->create(['role' => 'user', 'content' => $data['message']]);
        $conversation->messages()->create(['role' => 'assistant', 'content' => $reply]);

        return response()->json([
            'conversation_id' => $conversation->id,
            'reply'           => $reply,
            'suggestions'     => $this->suggestFollowUps($data['message'], $reply),
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

    /**
     * DIAGNOSTIK SEMENTARA — hapus setelah Groq 500 terpecahkan.
     * Tes Groq langsung (tanpa & dengan tools) untuk mengisolasi penyebab 500.
     */
    public function debugGroq(ChatbotTools $tools): JsonResponse
    {
        $key   = config('services.groq.key');
        $model = config('services.groq.model');
        $url   = 'https://api.groq.com/openai/v1/chat/completions';

        $base = ['model' => $model, 'messages' => [['role' => 'user', 'content' => 'test, jawab: ok']]];

        $toolSchema = array_map(
            fn ($t) => ['type' => 'function', 'function' => $t],
            $tools->schemaDefinitions()
        );

        $probe = function (array $payload) use ($key, $url) {
            try {
                $r = Http::timeout(15)->withToken($key)->post($url, $payload);
                return ['status' => $r->status(), 'body' => $r->json() ?? Str::limit($r->body(), 1500)];
            } catch (\Throwable $e) {
                return ['status' => 'EXCEPTION', 'body' => $e->getMessage()];
            }
        };

        return response()->json([
            'model'      => $model,
            'key_set'    => (bool) $key,
            'key_prefix' => $key ? substr($key, 0, 4) . '...' : null,
            'tool_count' => count($toolSchema),
            'tanpa_tools' => $probe($base),
            'dengan_tools' => $probe($base + ['tools' => $toolSchema]),
        ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function conversations(Request $request): JsonResponse
    {
        $conversations = ChatConversation::where('user_id', $request->user()->id)
            ->with(['messages' => fn ($q) => $q->oldest()->limit(1)])
            ->latest('updated_at')
            ->get();

        return response()->json($conversations->map(fn ($c) => [
            'id'         => $c->id,
            'title'      => Str::limit($c->messages->first()?->content ?? 'Percakapan baru', 40),
            'updated_at' => $c->updated_at,
        ]));
    }

    public function show(Request $request, ChatConversation $conversation): JsonResponse
    {
        abort_if($conversation->user_id !== $request->user()->id, 404);

        return response()->json($conversation->messages->map(fn ($m) => [
            'role' => $m->role, 'content' => $m->content,
        ]));
    }

    public function destroy(Request $request, ChatConversation $conversation): JsonResponse
    {
        abort_if($conversation->user_id !== $request->user()->id, 404);

        $conversation->delete();

        return response()->json(['deleted' => true]);
    }
}
