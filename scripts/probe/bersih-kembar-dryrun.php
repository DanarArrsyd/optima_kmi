<?php

/**
 * Dry-run READ-ONLY optima:bersih-kembar di prod SEBELUM perintahnya
 * ter-deploy. Workflow debug-log mode `bersihdry` mengganti __LAYANAN__ dengan
 * isi app/Services/PembersihKembar.php (base64) dari branch yang dijalankan,
 * jadi rencana yang dicetak = rencana yang nanti dieksekusi. Hanya memanggil
 * rencana() — nol tulis.
 */
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (! class_exists(App\Services\PembersihKembar::class, false)) {
    eval(base64_decode('__LAYANAN__'));
}

$rencana = (new App\Services\PembersihKembar)->rencana(['breakdown_dies' => [149]]);

foreach ($rencana as $modul => $r) {
    printf("=== %s: hapus %d, ragu (tidak dihapus) %d%s\n", $modul, count($r['hapus']), count($r['ragu']),
        $r['paksa'] ? ', simpan paksa #'.implode(', #', $r['paksa']) : '');
    foreach ($r['ragu'] as $ket) {
        echo "  RAGU  {$ket}\n";
    }
    foreach ($r['hapus'] as $ket) {
        echo "  hapus {$ket}\n";
    }
}
echo 'TOTAL hapus: ', array_sum(array_map(fn ($r) => count($r['hapus']), $rencana)), "\n";
