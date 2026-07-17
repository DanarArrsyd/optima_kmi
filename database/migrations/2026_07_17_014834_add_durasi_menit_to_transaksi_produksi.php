<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Persist waktu operasi (durasi_menit) tiap LHP produksi.
 *
 * Sebelumnya waktu kerja cuma accessor runtime (parse jam_start/jam_finish), jadi
 * tak bisa di-SUM di SQL — mahal buat agregat (Control Stroke running time, OEE,
 * total produksi). Kolom ini menyimpan hasilnya supaya agregat jadi SUM() murah.
 *
 * TAHAN-BANTING (hosting kadang telat/gagal migrasi):
 *  - Penambahan kolom idempotent (skip kalau sudah ada) → aman kalau migrasi
 *    diulang setelah gagal sebagian.
 *  - Backfill = SATU statement SQL (bukan loop PHP per-baris yang bisa timeout di
 *    tabel besar) dan BEST-EFFORT (dibungkus try-catch): kalau gagal, migrasi TETAP
 *    sukses. Baris yang belum ke-backfill (durasi_menit NULL) tetap benar karena
 *    accessor waktu_kerja_jam jatuh ke hitung live, dan terisi saat baris disimpan
 *    ulang. Formula SQL meniru aturan overnight accessor (finish ≤ start → +1 hari).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('transaksi_produksi', 'durasi_menit')) {
            Schema::table('transaksi_produksi', function (Blueprint $table) {
                $table->unsignedInteger('durasi_menit')->nullable()->after('qty_ng');
            });
        }

        // Backfill best-effort (MySQL). Di driver tanpa TIME_TO_SEC (mis. sqlite test)
        // statement gagal → ditelan; baris tetap NULL & benar via fallback accessor.
        try {
            DB::statement(<<<'SQL'
                UPDATE transaksi_produksi
                SET durasi_menit = FLOOR(
                    (CASE
                        WHEN TIME_TO_SEC(jam_finish) <= TIME_TO_SEC(jam_start)
                            THEN TIME_TO_SEC(jam_finish) + 86400 - TIME_TO_SEC(jam_start)
                        ELSE TIME_TO_SEC(jam_finish) - TIME_TO_SEC(jam_start)
                    END) / 60
                )
                WHERE durasi_menit IS NULL
                  AND jam_start IS NOT NULL AND jam_finish IS NOT NULL
            SQL);
        } catch (\Throwable $e) {
            Log::warning('Backfill durasi_menit dilewati (best-effort).', ['error' => $e->getMessage()]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('transaksi_produksi', 'durasi_menit')) {
            Schema::table('transaksi_produksi', function (Blueprint $table) {
                $table->dropColumn('durasi_menit');
            });
        }
    }
};
