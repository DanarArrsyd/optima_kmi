<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use App\Services\Chatbot\CapabilityRegistry;
use App\Services\Chatbot\Capabilities;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Chatbot capabilities yang mendaftarkan diri (self-registering).
     * Nambah modul/fitur baru ke chatbot = tambah satu class di sini —
     * TIDAK perlu edit ChatbotTools, ToolSchemaCatalog, atau system prompt.
     */
    private const CHATBOT_CAPABILITIES = [
        Capabilities\MtbfMttrDiesCapability::class,
        Capabilities\MtbfMttrMesinCapability::class,
        Capabilities\RekapNgCapability::class,
        Capabilities\ProductionTotalCapability::class,
        Capabilities\BandingkanProduksiCapability::class,
        Capabilities\OperatorProduksiCapability::class,
        Capabilities\RankingBreakdownMesinCapability::class,
        Capabilities\RankingBreakdownDiesCapability::class,
        Capabilities\TopProblemDiesCapability::class,
        Capabilities\TopProblemMesinCapability::class,
        Capabilities\TotalBreakdownDiesCapability::class,
        Capabilities\TotalBreakdownMesinCapability::class,
        Capabilities\RekapDandoriCapability::class,
        Capabilities\DescribeSchemaCapability::class,
        Capabilities\QueryDatabaseCapability::class,
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
