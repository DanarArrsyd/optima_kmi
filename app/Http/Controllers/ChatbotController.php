<?php
namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Services\GeminiChatbotService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ChatbotController extends Controller
{
    public function __construct(private GeminiChatbotService $svc) {}

    public function sendMessage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message'         => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'integer', 'exists:chat_conversations,id'],
        ]);

        $conversation = ($data['conversation_id'] ?? null)
            ? ChatConversation::where('user_id', $request->user()->id)->findOrFail($data['conversation_id'])
            : ChatConversation::create(['user_id' => $request->user()->id]);

        $reply = $this->svc->ask($conversation->fresh('messages'), $data['message']);

        $conversation->messages()->create(['role' => 'user', 'content' => $data['message']]);
        $conversation->messages()->create(['role' => 'assistant', 'content' => $reply]);

        return response()->json(['conversation_id' => $conversation->id, 'reply' => $reply]);
    }

    public function history(Request $request): JsonResponse
    {
        $conversation = ChatConversation::where('user_id', $request->user()->id)
            ->latest()->first();

        return response()->json([
            'conversation_id' => $conversation?->id,
            'messages'        => $conversation?->messages->map(fn ($m) => [
                'role' => $m->role, 'content' => $m->content,
            ]) ?? [],
        ]);
    }
}
