<?php

use App\Models\TransaksiProduksi;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persist waktu operasi (durasi_menit) tiap LHP produksi.
 *
 * Sebelumnya waktu kerja cuma accessor runtime (parse jam_start/jam_finish),
 * jadi tak bisa di-SUM di SQL — mahal buat agregat (Control Stroke running time,
 * OEE, total produksi). Kolom ini menyimpan hasil hitungan supaya agregat jadi
 * SUM() murah. Diisi otomatis oleh model saving hook; migrasi ini backfill data
 * lama pakai HELPER YANG SAMA (TransaksiProduksi::hitungDurasiMenit) supaya tak
 * ada drift dengan logika accessor (termasuk aturan shift lewat tengah malam).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaksi_produksi', function (Blueprint $table) {
            $table->unsignedInteger('durasi_menit')->nullable()->after('qty_ng');
        });

        // Backfill SEMUA baris (termasuk soft-deleted) tanpa menyentuh timestamp.
        // Per-baris supaya reuse helper PHP (aturan overnight konsisten dgn accessor).
        DB::table('transaksi_produksi')
            ->select('id', 'jam_start', 'jam_finish')
            ->orderBy('id')
            ->chunk(500, function ($rows) {
                foreach ($rows as $r) {
                    DB::table('transaksi_produksi')->where('id', $r->id)->update([
                        'durasi_menit' => TransaksiProduksi::hitungDurasiMenit($r->jam_start, $r->jam_finish),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('transaksi_produksi', function (Blueprint $table) {
            $table->dropColumn('durasi_menit');
        });
    }
};
