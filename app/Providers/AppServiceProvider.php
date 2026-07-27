<?php

namespace App\Providers;

use App\Services\Chatbot\Capabilities;
use App\Services\Chatbot\CapabilityRegistry;
use App\Support\SafeExcelValueBinder;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;
use PhpOffice\PhpSpreadsheet\Cell\Cell;

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
        Capabilities\OeeMesinCapability::class,
        Capabilities\RekapNgCapability::class,
        Capabilities\CustomerQualityCapability::class,
        Capabilities\TrendCapability::class,
        Capabilities\RootCauseCapability::class,
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

        // Semua export Excel: string yang diawali "=" dsb ditulis sebagai teks,
        // bukan formula. Lihat SafeExcelValueBinder.
        Cell::setValueBinder(new SafeExcelValueBinder);
    }
}
