<?php

namespace App\Services;

class GroqChatbotService extends OpenAiCompatibleChatbotService
{
    protected function endpoint(): string
    {
        return 'https://api.groq.com/openai/v1/chat/completions';
    }

    protected function apiKey(): ?string
    {
        return config('services.groq.key');
    }

    protected function model(): ?string
    {
        return config('services.groq.model');
    }

    protected function providerLabel(): string
    {
        return 'Groq';
    }
}
