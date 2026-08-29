<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            /* User TANPA SoftDeletes dan created_by di semua tabel transaksi
             * adalah FK RESTRICT (lihat PR #133) — akun yang sudah menulis
             * data produksi tidak bisa dihapus. Nonaktifkan adalah satu-
             * satunya cara mencabut akses mantan pegawai tanpa menghapus
             * jejak created_by dari riwayat. */
            $table->boolean('is_aktif')->default(true)->after('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_aktif');
        });
    }
};
