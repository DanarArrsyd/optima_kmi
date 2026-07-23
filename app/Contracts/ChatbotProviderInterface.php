<?php

namespace App\Contracts;

interface ChatbotProviderInterface
{
    /**
     * Attempt to answer `$userMessage` using tool-calling against real OPTIMA
     * data. `$history` is an in-memory list of prior turns
     * (['role' => 'user'|'assistant', 'content' => string]) — no persistence.
     * Returns null on total provider failure so the caller can fall back.
     */
    public function ask(array $history, string $userMessage): ?string;
}
