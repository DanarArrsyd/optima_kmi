<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('transaksi_produksi', function (Blueprint $table) {
            $table->unsignedInteger('qty_ng')->nullable()->default(null)->after('qty_aktual');
            $table->foreignId('id_jenis_ng')->nullable()->after('qty_ng')
                  ->constrained('master_jenis_ng')->nullOnDelete();
        });
        Schema::table('transaksi_qc', function (Blueprint $table) {
            $table->foreignId('id_jenis_ng')->nullable()->after('qty_ng')
                  ->constrained('master_jenis_ng')->nullOnDelete();
        });
    }
    public function down(): void {
        Schema::table('transaksi_produksi', function (Blueprint $table) {
            $table->dropForeign(['id_jenis_ng']);
            $table->dropColumn(['qty_ng', 'id_jenis_ng']);
        });
        Schema::table('transaksi_qc', function (Blueprint $table) {
            $table->dropForeign(['id_jenis_ng']);
            $table->dropColumn('id_jenis_ng');
        });
    }
};
