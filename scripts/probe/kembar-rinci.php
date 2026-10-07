<?php

/**
 * Probe READ-ONLY: rincian baris kembar per modul, untuk direview SEBELUM
 * dibersihkan. Hanya SELECT. Di-stream lewat stdin oleh debug-log mode
 * `kembarrinci`.
 *
 * Kunci = PenjagaKembar::KUNCI (definisi yang sama dengan peringatan input).
 * Tiap grup: baris id TERKECIL = DISIMPAN, sisanya kandidat hapus, dengan kelas:
 *   PERSIS — angka hasil (qty/NG/menit/selesai) sama dengan yang disimpan
 *   BEDA   — angka hasil berbeda: perlu keputusan manual mana yang benar
 * Bendera: SEDETIK (dibuat di detik yang sama = satu request), NOTE (punya
 * staff note), REVIEW (anomali sudah direview), ORANG (penginput beda).
 */
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\PenjagaKembar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$hasil = [
    'produksi' => ['qty_aktual', 'qty_ng', 'ng'],
    'qc' => ['qty_ok', 'qty_ng'],
    'dandori' => ['actual_menit', 'std_menit'],
    'breakdown_mesin' => ['tanggal_selesai_perbaikan', 'jam_selesai_perbaikan', 'problem_mesin'],
    'breakdown_dies' => ['tanggal_selesai_perbaikan', 'jam_selesai_perbaikan', 'problem_dies'],
    'repair' => ['qty_aktual'],
    'repair_produksi' => ['qty_aktual'],
];

$nama = fn (string $tabel, string $kolom) => collect(DB::table($tabel)->get())->pluck($kolom, 'id');
$shift = $nama('master_shift', 'nama_shift');
$orang = $nama('master_karyawan', 'nama_karyawan');
$mesin = $nama('master_mesin', 'id_mesin');
$user = $nama('users', 'name');
$lt = $nama('master_losstime', 'kode');
$pp = collect(DB::select('SELECT pp.id, CONCAT(p.part_no, "/", pp.id_proses) t FROM master_part_proses pp JOIN master_part p ON p.id = pp.id_part'))->pluck('t', 'id');
$ngSig = collect(DB::select('SELECT id_transaksi_produksi i, GROUP_CONCAT(CONCAT(id_jenis_ng, "x", qty) ORDER BY id_jenis_ng) s FROM transaksi_produksi_ng GROUP BY id_transaksi_produksi'))->pluck('s', 'i');

$label = function (string $kol, $v) use ($shift, $orang, $mesin, $pp, $lt) {
    return match (true) {
        $kol === 'id_shift' => $shift[$v] ?? $v,
        in_array($kol, ['id_karyawan', 'id_karyawan_qc'], true) => $orang[$v] ?? $v,
        $kol === 'id_mesin' => $mesin[$v] ?? $v,
        $kol === 'id_part_proses' => $pp[$v] ?? $v,
        $kol === 'id_losstime' => $lt[$v] ?? $v,
        default => is_string($v) && preg_match('/^\d\d:\d\d:\d\d$/', $v) ? substr($v, 0, 5) : $v,
    };
};

$total = [];
foreach (PenjagaKembar::KUNCI as $modul => [$model, $kunci]) {
    $tabel = (new $model)->getTable();
    if (! Schema::hasTable($tabel)) {
        continue;
    }
    $kol = array_keys($kunci);
    $grup = implode(',', $kol);
    $rows = DB::select("SELECT t.* FROM {$tabel} t JOIN (SELECT {$grup} FROM {$tabel} WHERE deleted_at IS NULL GROUP BY {$grup} HAVING COUNT(*) > 1) g USING ({$grup}) WHERE t.deleted_at IS NULL ORDER BY ".implode(',', array_map(fn ($c) => "t.{$c}", $kol)).', t.id');
    if (! $rows) {
        echo "=== {$modul}: 0 grup ===", PHP_EOL, PHP_EOL;

        continue;
    }

    $kelompok = [];
    foreach ($rows as $r) {
        $r = (array) $r;
        $kelompok[implode('|', array_map(fn ($c) => $r[$c], $kol))][] = $r;
    }

    $sig = function (array $r) use ($modul, $hasil, $ngSig) {
        return implode('|', array_map(fn ($c) => $c === 'ng' ? ($ngSig[$r['id']] ?? '') : (string) ($r[$c] ?? ''), $hasil[$modul]));
    };

    $baris = [];
    $hit = ['PERSIS' => 0, 'BEDA' => 0];
    $flag = ['SEDETIK' => 0, 'NOTE' => 0, 'REVIEW' => 0, 'ORANG' => 0];
    $perHari = [];
    foreach ($kelompok as $isi) {
        $simpan = array_shift($isi);
        foreach ($isi as $h) {
            $kelas = $sig($h) === $sig($simpan) ? 'PERSIS' : 'BEDA';
            $hit[$kelas]++;
            $f = [];
            if (($h['created_at'] ?? null) === ($simpan['created_at'] ?? null)) {
                $f[] = 'SEDETIK';
            }
            if (! empty($h['staff_note']) || ! empty($simpan['staff_note'])) {
                $f[] = 'NOTE';
            }
            if (! empty($h['anomaly_reviewed_at'])) {
                $f[] = 'REVIEW';
            }
            if (isset($h['created_by']) && $h['created_by'] != $simpan['created_by']) {
                $f[] = 'ORANG';
            }
            foreach ($f as $x) {
                $flag[$x]++;
            }
            $hari = substr((string) ($h['created_at'] ?? '-'), 0, 10);
            $perHari[$hari] = ($perHari[$hari] ?? 0) + 1;

            $jarak = isset($h['created_at'], $simpan['created_at'])
                ? (int) round((strtotime($h['created_at']) - strtotime($simpan['created_at'])) / 60) : '-';
            $baris[] = sprintf('  hapus #%-6s simpan #%-6s %-6s +%5s mnt  %-22s %s | hasil %s -> %s | oleh %s -> %s',
                $h['id'], $simpan['id'], $kelas, $jarak, implode(',', $f),
                implode(' ', array_map(fn ($c) => $label($c, $h[$c]), $kol)),
                $sig($simpan), $sig($h),
                $user[$simpan['created_by'] ?? 0] ?? '-', $user[$h['created_by'] ?? 0] ?? '-');
        }
    }

    $n = $hit['PERSIS'] + $hit['BEDA'];
    $total[$modul] = $hit;
    echo "=== {$modul} ({$tabel}): ", count($kelompok), " grup, {$n} kandidat hapus ===", PHP_EOL;
    echo "  PERSIS {$hit['PERSIS']}  BEDA {$hit['BEDA']}  | ",
        implode('  ', array_map(fn ($k, $v) => "{$k} {$v}", array_keys($flag), $flag)), PHP_EOL;
    ksort($perHari);
    echo '  per tanggal input (UTC): ', implode('  ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($perHari), $perHari)), PHP_EOL;
    echo implode(PHP_EOL, $baris), PHP_EOL, PHP_EOL;
}

echo '=== TOTAL ===', PHP_EOL;
foreach ($total as $m => $h) {
    echo "  {$m}: PERSIS {$h['PERSIS']}  BEDA {$h['BEDA']}", PHP_EOL;
}
