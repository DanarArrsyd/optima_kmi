<?php
namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Services\GeminiChatbotService;
use App\Services\GroqChatbotService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
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

        return response()->json(['conversation_id' => $conversation->id, 'reply' => $reply]);
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
}
