<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['transaksi_produksi', 'transaksi_qc', 'transaksi_dandori_rincian'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->timestamp('staff_note_resolved_at')->nullable()->after('staff_note_by');
                $t->foreignId('staff_note_resolved_by')->nullable()->after('staff_note_resolved_at')
                  ->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['transaksi_produksi', 'transaksi_qc', 'transaksi_dandori_rincian'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign([$t->getTable() . '_staff_note_resolved_by_foreign'] ?? ['staff_note_resolved_by']);
                $t->dropColumn(['staff_note_resolved_at', 'staff_note_resolved_by']);
            });
        }
    }
};
