<?php

/**
 * Probe READ-ONLY: berapa baris transaksi kembar yang sudah ada di prod.
 *
 * Dipakai sebelum membangun peringatan duplikat saat input. Hanya SELECT.
 * Dijalankan workflow debug-log mode `kembar`: file ini di-stream lewat stdin
 * ke `php` di server, jadi tidak perlu ikut ter-deploy.
 *
 * Per modul dua tingkat kunci:
 *  - kunci : natural key usulan (kejadian yang sama)
 *  - ketat : kunci + angka hasil (qty / menit) — kembar persis
 * Jarak created_at baris pertama-terakhir tiap grup memisahkan klik ganda
 * (<=1 menit) dari kasus "admin lupa sudah input" (>60 menit).
 */
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$modul = [
    'LHP Produksi' => ['transaksi_produksi', 'tanggal_produksi',
        ['tanggal_produksi', 'id_shift', 'id_karyawan', 'id_mesin', 'id_part_proses', 'jam_start', 'jam_finish'],
        ['qty_aktual', 'qty_ng']],
    'LHP Quality' => ['transaksi_qc', 'tgl_pengecekan',
        ['tgl_pengecekan', 'tgl_produksi_part', 'id_shift', 'id_karyawan_qc', 'id_part_proses', 'jam_start', 'jam_finish'],
        ['qty_ok', 'qty_ng']],
    'Dandori' => ['transaksi_dandori_rincian', 'tanggal',
        ['tanggal', 'id_shift', 'id_karyawan', 'id_mesin', 'id_part_proses', 'id_losstime'],
        ['actual_menit']],
    'Breakdown Mesin' => ['transaksi_breakdown_mesin', 'tanggal_breakdown',
        ['id_mesin', 'tanggal_breakdown', 'jam_breakdown'],
        ['tanggal_selesai_perbaikan', 'jam_selesai_perbaikan']],
    'Breakdown Dies' => ['transaksi_breakdown_dies', 'tanggal_breakdown',
        ['id_part_proses', 'tanggal_breakdown', 'jam_breakdown'],
        ['tanggal_selesai_perbaikan', 'jam_selesai_perbaikan']],
    'LHR Repair' => ['transaksi_repair', 'tanggal_repair',
        ['tanggal_repair', 'id_shift', 'id_karyawan', 'id_part_proses_repair', 'jam_start', 'jam_finish'],
        ['qty_aktual']],
    'LHR Repair Produksi' => ['transaksi_repair_produksi', 'tanggal_repair_produksi',
        ['tanggal_repair_produksi', 'id_shift', 'id_karyawan', 'id_part_proses_repair_produksi', 'jam_start', 'jam_finish'],
        ['qty_aktual']],
];

$q = fn (string $sql) => DB::select($sql);

foreach ($modul as $nama => [$tabel, $kolTgl, $kunci, $hasil]) {
    echo "=== {$nama} ({$tabel}) ===", PHP_EOL;
    if (! Illuminate\Support\Facades\Schema::hasTable($tabel)) {
        echo "  tabel tidak ada", PHP_EOL, PHP_EOL;

        continue;
    }
    $ada = Illuminate\Support\Facades\Schema::getColumnListing($tabel);
    $hilang = array_diff(array_merge($kunci, $hasil), $ada);
    if ($hilang) {
        echo '  kolom tidak ada: ', implode(', ', $hilang), PHP_EOL, PHP_EOL;

        continue;
    }

    $k = implode(',', $kunci);
    $kh = implode(',', array_merge($kunci, $hasil));
    $aktif = "deleted_at IS NULL";
    $penulis = in_array('created_by', $ada) ? 'COUNT(DISTINCT created_by)' : '1';

    $total = $q("SELECT COUNT(*) n, MIN({$kolTgl}) awal, MAX({$kolTgl}) akhir FROM {$tabel} WHERE {$aktif}")[0];
    echo "  baris aktif         : {$total->n}  ({$total->awal} s/d {$total->akhir})", PHP_EOL;

    foreach (['kunci' => $k, 'ketat' => $kh] as $label => $grup) {
        foreach (['semua' => '', 'sejak 2026-06' => " AND {$kolTgl} >= '2026-06-01'"] as $era => $saring) {
            $r = $q("SELECT COUNT(*) grup, COALESCE(SUM(n-1),0) lebih,
                SUM(CASE WHEN jarak <= 1 THEN n-1 ELSE 0 END) klik,
                SUM(CASE WHEN jarak > 1 AND jarak <= 60 THEN n-1 ELSE 0 END) sejam,
                SUM(CASE WHEN jarak > 60 THEN n-1 ELSE 0 END) lupa,
                SUM(CASE WHEN penulis > 1 THEN n-1 ELSE 0 END) beda_penulis
                FROM (SELECT COUNT(*) n, TIMESTAMPDIFF(MINUTE, MIN(created_at), MAX(created_at)) jarak,
                    {$penulis} penulis
                    FROM {$tabel} WHERE {$aktif}{$saring} GROUP BY {$grup} HAVING n > 1) x")[0];
            printf("  %-5s %-13s: %5d grup, %5d baris lebih | <=1mnt %d, 1-60mnt %d, >60mnt %d | penulis beda %d%s",
                $label, $era, $r->grup, $r->lebih, $r->klik, $r->sejam, $r->lupa, $r->beda_penulis, PHP_EOL);
        }
    }

    echo '  5 grup kunci terbaru:', PHP_EOL;
    foreach ($q("SELECT {$k}, COUNT(*) n, COUNT(DISTINCT CONCAT_WS('|',".implode(',', $hasil).")) varian,
            MIN(created_at) pertama, MAX(created_at) terakhir
            FROM {$tabel} WHERE {$aktif} GROUP BY {$k} HAVING n > 1 ORDER BY MAX(created_at) DESC LIMIT 5") as $x) {
        $x = (array) $x;
        $isi = implode(' ', array_map(fn ($c) => $x[$c], $kunci));
        echo "    {$isi}  x{$x['n']}  varian_hasil={$x['varian']}  {$x['pertama']} -> {$x['terakhir']} UTC", PHP_EOL;
    }
    echo PHP_EOL;
}
