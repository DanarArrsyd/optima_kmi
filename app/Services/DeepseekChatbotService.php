<?php
namespace App\Services;

class DeepseekChatbotService extends OpenAiCompatibleChatbotService
{
    protected function endpoint(): string
    {
        return 'https://api.deepseek.com/chat/completions';
    }

    protected function apiKey(): ?string
    {
        return config('services.deepseek.key');
    }

    protected function model(): ?string
    {
        return config('services.deepseek.model');
    }

    protected function providerLabel(): string
    {
        return 'DeepSeek';
    }
}
