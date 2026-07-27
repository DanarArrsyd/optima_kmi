<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Memindahkan NG gaya lama (satu jenis per baris) ke tabel detail.
 *
 * Baris dengan qty_ng > 0 tapi id_jenis_ng NULL SENGAJA di-skip: tidak ada baris
 * master karangan yang dibuat. Konsekuensinya SUM(detail) < qty_ng untuk data
 * historis; selisihnya dihitung saat query di Tahap 2 dan ditampilkan sebagai
 * "Tanpa jenis". Jumlah yang di-skip dicetak agar angkanya diketahui sekali di
 * awal, bukan menjadi selisih tak terlihat.
 *
 * qty_ng induk TIDAK disentuh sama sekali. Nilai lamanya adalah angka paling
 * benar yang pernah dimiliki sistem untuk baris tersebut.
 *
 * DB::table() mengabaikan soft delete secara alami, jadi induk yang ter-soft-
 * delete ikut termigrasi tanpa perlu withTrashed().
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->migrateTable('transaksi_produksi', 'transaksi_produksi_ng', 'id_transaksi_produksi');
        $this->migrateTable('transaksi_qc', 'transaksi_qc_ng', 'id_transaksi_qc');
    }

    public function down(): void
    {
        // Sengaja no-op. Baris detail hasil migrasi tidak bisa dibedakan dari
        // hasil input manual, jadi rollback otomatis akan menghapus data yang
        // sah. Untuk membatalkan, drop tabel detail lewat migrasi pembuatnya.
    }

    private function migrateTable(string $parent, string $detail, string $foreignKey): void
    {
        $migrated = 0;

        DB::table($parent)
            ->select('id', 'qty_ng', 'id_jenis_ng')
            ->where('qty_ng', '>', 0)
            ->whereNotNull('id_jenis_ng')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($detail, $foreignKey, &$migrated) {
                $now = now();
                $payload = [];

                foreach ($rows as $row) {
                    $payload[] = [
                        $foreignKey => $row->id,
                        'id_jenis_ng' => $row->id_jenis_ng,
                        'qty' => (int) $row->qty_ng,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($payload !== []) {
                    // insertOrIgnore: UNIQUE (induk, jenis_ng) membuat migrasi
                    // aman dijalankan ulang tanpa menggandakan baris.
                    DB::table($detail)->insertOrIgnore($payload);
                    $migrated += count($payload);
                }
            });

        $skipped = DB::table($parent)
            ->where('qty_ng', '>', 0)
            ->whereNull('id_jenis_ng')
            ->count();

        $ringkas = "  → {$parent}: {$migrated} baris NG dimigrasi, {$skipped} di-skip (qty_ng > 0 tanpa jenis NG)";

        /*
         * Angka ini penting justru saat deploy — supaya jumlah yang di-skip
         * diketahui sekali di awal, bukan jadi selisih tak terlihat. Tapi saat
         * test, migrasi jalan di setiap boot dan menulis ke stdout milik
         * printer test, jadi jangan cetak di sana.
         */
        if (! app()->runningUnitTests()) {
            echo $ringkas."\n";
        }

        Log::info($ringkas);
    }
};
