<?php

namespace App\Services;

class CerebrasChatbotService extends OpenAiCompatibleChatbotService
{
    protected function endpoint(): string
    {
        return 'https://api.cerebras.ai/v1/chat/completions';
    }

    protected function apiKey(): ?string
    {
        return config('services.cerebras.key');
    }

    protected function model(): ?string
    {
        return config('services.cerebras.model');
    }

    protected function providerLabel(): string
    {
        return 'Cerebras';
    }
}
