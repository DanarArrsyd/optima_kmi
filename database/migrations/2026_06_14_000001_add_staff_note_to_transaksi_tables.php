<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('transaksi_produksi', function (Blueprint $table) {
            $table->text('staff_note')->nullable()->after('anomaly_reviewed_by');
            $table->timestamp('staff_note_at')->nullable()->after('staff_note');
            $table->foreignId('staff_note_by')->nullable()->after('staff_note_at')->constrained('users')->nullOnDelete();
        });
        Schema::table('transaksi_qc', function (Blueprint $table) {
            $table->text('staff_note')->nullable()->after('updated_by');
            $table->timestamp('staff_note_at')->nullable()->after('staff_note');
            $table->foreignId('staff_note_by')->nullable()->after('staff_note_at')->constrained('users')->nullOnDelete();
        });
        Schema::table('transaksi_dandori_rincian', function (Blueprint $table) {
            $table->text('staff_note')->nullable()->after('created_by');
            $table->timestamp('staff_note_at')->nullable()->after('staff_note');
            $table->foreignId('staff_note_by')->nullable()->after('staff_note_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['transaksi_produksi', 'transaksi_qc', 'transaksi_dandori_rincian'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['staff_note_by']);
                $t->dropColumn(['staff_note', 'staff_note_at', 'staff_note_by']);
            });
        }
    }
};
