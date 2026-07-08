<?php
namespace App\Contracts;

use App\Models\ChatConversation;

interface ChatbotProviderInterface
{
    /**
     * Attempt to answer `$userMessage` using tool-calling against real OPTIMA
     * data. Returns null on total provider failure (network error, non-2xx
     * response, malformed tool call, or exhausted iteration budget) so the
     * caller can fall back to another provider.
     */
    public function ask(ChatConversation $conversation, string $userMessage): ?string;
}
