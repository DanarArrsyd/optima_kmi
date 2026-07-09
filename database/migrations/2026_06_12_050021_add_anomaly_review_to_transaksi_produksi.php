<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaksi_produksi', function (Blueprint $table) {
            $table->timestamp('anomaly_reviewed_at')->nullable()->after('updated_by');
            $table->foreignId('anomaly_reviewed_by')->nullable()->constrained('users')->nullOnDelete()->after('anomaly_reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('transaksi_produksi', function (Blueprint $table) {
            $table->dropForeign(['anomaly_reviewed_by']);
            $table->dropColumn(['anomaly_reviewed_at', 'anomaly_reviewed_by']);
        });
    }
};
