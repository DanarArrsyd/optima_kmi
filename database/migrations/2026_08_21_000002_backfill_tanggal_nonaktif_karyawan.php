<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mengisi tanggal_nonaktif karyawan yang sudah terlanjur nonaktif sebelum kolom
 * ini ada, memakai tanggal transaksi terakhirnya — orang berhenti bekerja ya
 * berhenti menyetor LHP.
 *
 * Tiga max() terpisah lalu dibandingkan di PHP, BUKAN GREATEST() di SQL:
 * GREATEST tidak ada di SQLite dan akan membuat migrasi ini mustahil diuji.
 * Jumlah karyawan nonaktif puluhan, jadi tidak ada yang ditukar selain SQL yang
 * lebih pintar dari yang dibutuhkan.
 *
 * Yang tanggalnya SUDAH terisi tidak ditimpa, dan karyawan nonaktif tanpa satu
 * pun transaksi sengaja dibiarkan NULL — NULL berarti "selalu tampil", jauh
 * lebih murah daripada operator yang hilang diam-diam dari dropdown.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('master_karyawan', 'tanggal_nonaktif')) {
            return;
        }

        $sumber = [
            ['transaksi_produksi', 'tanggal_produksi', 'id_karyawan'],
            ['transaksi_qc', 'tgl_pengecekan', 'id_karyawan_qc'],
            ['transaksi_dandori_rincian', 'tanggal', 'id_karyawan'],
        ];

        DB::table('master_karyawan')
            ->where('is_aktif', false)
            ->whereNull('tanggal_nonaktif')
            ->orderBy('id')
            ->each(function ($karyawan) use ($sumber) {
                $terakhir = null;

                foreach ($sumber as [$tabel, $kolomTanggal, $kolomKaryawan]) {
                    if (! Schema::hasTable($tabel)) {
                        continue;
                    }

                    $maks = DB::table($tabel)
                        ->where($kolomKaryawan, $karyawan->id)
                        ->max($kolomTanggal);

                    if ($maks !== null && ($terakhir === null || $maks > $terakhir)) {
                        $terakhir = $maks;
                    }
                }

                if ($terakhir !== null) {
                    DB::table('master_karyawan')
                        ->where('id', $karyawan->id)
                        ->update(['tanggal_nonaktif' => substr((string) $terakhir, 0, 10)]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('master_karyawan', 'tanggal_nonaktif')) {
            DB::table('master_karyawan')->update(['tanggal_nonaktif' => null]);
        }
    }
};
