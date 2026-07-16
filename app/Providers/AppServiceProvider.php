<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use App\Services\Chatbot\CapabilityRegistry;
use App\Services\Chatbot\Capabilities\ProductionTotalCapability;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Chatbot capabilities yang mendaftarkan diri (self-registering).
     * Nambah modul/fitur baru ke chatbot = tambah satu class di sini —
     * TIDAK perlu edit ChatbotTools, ToolSchemaCatalog, atau system prompt.
     */
    private const CHATBOT_CAPABILITIES = [
        ProductionTotalCapability::class,
        // Migrasi tool lain menyusul: RankingBreakdownMesinCapability::class, dst.
    ];

    public function register(): void
    {
        $this->app->tag(self::CHATBOT_CAPABILITIES, 'chatbot.capability');

        $this->app->singleton(
            CapabilityRegistry::class,
            fn ($app) => new CapabilityRegistry($app->tagged('chatbot.capability')),
        );
    }

    public function boot(): void
    {
        Paginator::useBootstrapFive();
    }
}
