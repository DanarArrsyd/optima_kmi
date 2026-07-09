<?php
/**
 * fix-storage.php — buat ulang folder storage/framework yang hilang
 * (penyebab error "Please provide a valid cache path.")
 *
 * CARA PAKAI:
 *   1. Upload file ini ke folder public_html/ (sejajar index.php)
 *   2. Buka di browser: https://optima.kencomanufactur.co.id/fix-storage.php
 *   3. Setelah semua OK, HAPUS file ini dari hosting.
 */

header('Content-Type: text/plain; charset=utf-8');

// public/ ada di dalam public_html, root Laravel = satu level di atas.
// Di hosting Hostinger biasanya isi public/ ditaruh langsung di public_html,
// jadi coba dua kemungkinan lokasi storage.
$candidates = [
    __DIR__ . '/../storage',   // struktur Laravel normal (public/ di dalam root)
    __DIR__ . '/storage',      // kalau isi public/ ditaruh langsung di public_html
];

$storage = null;
foreach ($candidates as $c) {
    if (is_dir($c)) { $storage = realpath($c); break; }
}

if ($storage === null) {
    // Tidak ketemu — pakai kandidat pertama & coba buat.
    $storage = $candidates[0];
    echo "PERINGATAN: folder storage tidak ditemukan, mencoba membuat di: $storage\n\n";
}

echo "Root storage: $storage\n";
echo str_repeat('=', 50) . "\n\n";

$dirs = [
    'app/public',
    'framework/cache/data',
    'framework/sessions',
    'framework/testing',
    'framework/views',
    'logs',
];

$allOk = true;
foreach ($dirs as $dir) {
    $path = $storage . '/' . $dir;
    if (is_dir($path)) {
        echo "[ ADA ]   $dir\n";
    } else {
        if (@mkdir($path, 0755, true)) {
            echo "[ BUAT ]  $dir  -> OK\n";
        } else {
            echo "[ GAGAL ] $dir  -> tidak bisa dibuat (cek permission!)\n";
            $allOk = false;
        }
    }
    // Tambahkan .gitignore agar tidak kosong (opsional)
    $gi = $path . '/.gitignore';
    if (is_dir($path) && !file_exists($gi)) {
        @file_put_contents($gi, "*\n!.gitignore\n");
    }
}

echo "\n" . str_repeat('=', 50) . "\n";
echo $allOk
    ? "SELESAI — semua folder siap. Refresh halaman OPTIMA.\n"
    : "ADA YANG GAGAL — buat folder manual via FileManager (permission 755).\n";
echo "\nJANGAN LUPA HAPUS file fix-storage.php ini setelah selesai!\n";
