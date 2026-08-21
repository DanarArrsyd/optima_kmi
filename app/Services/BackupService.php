<?php

namespace App\Services;

use App\Contracts\PembuatDump;
use App\Exceptions\BackupSedangBerjalanException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class BackupService
{
    public const DIREKTORI = 'backups';

    /** Pola nama arsip. Dipakai juga BackupController — satu definisi saja. */
    public const POLA_NAMA = '/^optima-\d{4}-\d{2}-\d{2}_\d{6}\.zip$/';

    // Induk direktori kerja sementara — path tetap (bukan bagian uniqid-nya)
    // supaya bisa diperiksa deterministik oleh test tanpa menebak nama persis.
    public const DIREKTORI_KERJA = 'app/backup-sementara';

    /** Nama & TTL lock, dipegang untuk SELURUH pemanggil (tombol manual & jadwal). */
    private const NAMA_LOCK = 'backup-berjalan';

    private const TTL_LOCK = 600;

    /**
     * Ambang bawah ukuran dump. mysqldump yang menulis header lalu berhenti
     * (koneksi putus di tengah, hak akses tabel dicabut sebagian) tetap bisa
     * keluar dengan kode 0 — arsipnya jadi ZIP yang ukurannya masuk akal tapi
     * isinya nyaris kosong. Ini bukan validasi isi dump, cuma penjaga waras.
     */
    private const UKURAN_DUMP_MINIMAL = 1024;

    public function __construct(private PembuatDump $pembuatDump) {}

    /**
     * Merakit satu arsip. Mengembalikan nama berkasnya.
     *
     * Seluruh pekerjaan dilakukan di direktori sementara lebih dulu; ZIP baru
     * dipindahkan ke tujuan setelah utuh. Kalau ada langkah yang gagal, yang
     * tersisa nol berkas — bukan arsip separuh jadi yang terlihat sah di daftar.
     *
     * Lock diambil DI SINI (bukan di controller) supaya berlaku untuk SEMUA
     * pemanggil — tombol manual (BackupController) maupun jadwal terjadwal
     * (backup:jalankan lewat SSH). Kalau lock ditaruh cuma di satu sisi, dua
     * mysqldump bisa jalan bersamaan dan, kalau nama arsipnya jatuh di detik
     * yang sama, File::move() salah satu menimpa arsip yang lain diam-diam.
     */
    public function jalankan(string $sumber, ?int $userId = null): string
    {
        $kunci = Cache::lock(self::NAMA_LOCK, self::TTL_LOCK);
        if (! $kunci->get()) {
            throw new BackupSedangBerjalanException;
        }

        try {
            $waktu = now('Asia/Jakarta');
            // Detik disertakan supaya pemicu manual yang jatuh di menit yang sama
            // dengan jadwal otomatis tidak menimpa arsip yang sudah ada lewat rename().
            $nama = 'optima-'.$waktu->format('Y-m-d_His').'.zip';

            $kerja = storage_path(self::DIREKTORI_KERJA.'/'.uniqid());
            File::ensureDirectoryExists($kerja);

            try {
                $berkasDump = $kerja.'/database.sql';
                $this->pembuatDump->keBerkas($berkasDump);

                $ukuranDump = filesize($berkasDump);
                if ($ukuranDump === false || $ukuranDump < self::UKURAN_DUMP_MINIMAL) {
                    // Bukan cuma "kecil" — ini "mysqldump keluar sukses tapi
                    // dumpnya nyaris kosong", persis kasus yang terlihat sah di
                    // kartu umur & ukuran ZIP tapi tidak me-restore apa-apa.
                    throw new RuntimeException(
                        'Dump database janggal kecil ('.($ukuranDump === false ? 0 : $ukuranDump).' byte) — kemungkinan mysqldump gagal diam-diam.'
                    );
                }

                $manifest = [
                    'waktu_wib' => $waktu->format('Y-m-d H:i:s'),
                    'waktu_utc' => now()->format('Y-m-d H:i:s'),
                    'sumber' => $sumber,
                    'dipicu_oleh' => $userId,
                    'jumlah_tabel' => $this->jumlahTabel(),
                    'ukuran_dump' => $ukuranDump,
                ];
                File::put($kerja.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

                $zipSementara = $kerja.'/arsip.zip';
                $this->rakitZip($zipSementara, $berkasDump, $kerja.'/manifest.json');

                Storage::disk('local')->makeDirectory(self::DIREKTORI);
                $tujuan = Storage::disk('local')->path(self::DIREKTORI.'/'.$nama);
                if (! File::move($zipSementara, $tujuan)) {
                    throw new RuntimeException("Gagal memindahkan arsip ke {$tujuan}.");
                }
            } finally {
                File::deleteDirectory($kerja);
            }

            // Kegagalan memangkas arsip lama TIDAK boleh membuat backup yang
            // baru saja berhasil dilaporkan sebagai gagal — arsipnya sudah
            // utuh di backups/, operator cukup diberi tahu lewat log, bukan
            // disuruh mengulang backup yang sebenarnya sudah sukses.
            try {
                $this->pangkas();
            } catch (\Throwable $e) {
                Log::error('Pemangkasan arsip backup lama gagal (arsip baru tetap tersimpan): '.$e->getMessage());
            }

            return $nama;
        } finally {
            $kunci->release();
        }
    }

    /**
     * Daftar arsip, terbaru dulu. Nama berkas memuat cap waktunya, jadi urutan
     * nama = urutan waktu; tidak perlu membuka satu pun ZIP untuk mengurutkan.
     */
    public function daftar(): array
    {
        $disk = Storage::disk('local');
        $berkas = $disk->files(self::DIREKTORI);
        rsort($berkas);

        return array_map(function (string $path) use ($disk) {
            $nama = basename($path);
            $manifest = $this->bacaManifest($disk->path($path));

            return [
                'nama' => $nama,
                'ukuran' => $disk->size($path),
                'waktu_wib' => $manifest['waktu_wib'] ?? null,
                'sumber' => $manifest['sumber'] ?? null,
                'dipicu_oleh' => $manifest['dipicu_oleh'] ?? null,
                'jumlah_tabel' => $manifest['jumlah_tabel'] ?? null,
            ];
        }, $berkas);
    }

    /** Buang arsip tertua, sisakan yang $simpan terbaru. Kembalikan jumlah yang dibuang. */
    public function pangkas(int $simpan = 30): int
    {
        $disk = Storage::disk('local');
        $berkas = $disk->files(self::DIREKTORI);
        rsort($berkas);

        $buang = array_slice($berkas, $simpan);
        foreach ($buang as $path) {
            $disk->delete($path);
        }

        return count($buang);
    }

    /**
     * Validasi ditaruh DI SINI, bukan hanya di lapisan HTTP pemanggilnya:
     * normalizer path Flysystem cuma menolak keluar dari root disk, bukan
     * keluar dari subdirektori backups/ — '../checksheet/rahasia.pdf'
     * menormalisasi jadi 'checksheet/rahasia.pdf' dan diam-diam menghapus
     * berkas nyata (disk private yang sama dipakai checksheet PDF Preventive
     * Dies). Route handler di masa depan tidak boleh diandalkan untuk hati-hati.
     */
    public function hapus(string $nama): void
    {
        if (! preg_match(self::POLA_NAMA, $nama)) {
            throw new \InvalidArgumentException("Nama arsip tidak valid: {$nama}");
        }

        Storage::disk('local')->delete(self::DIREKTORI.'/'.$nama);
    }

    /** Umur arsip terbaru dalam jam, atau null kalau belum ada satu pun. */
    public function umurJamTerakhir(): ?float
    {
        $disk = Storage::disk('local');
        $berkas = $disk->files(self::DIREKTORI);
        if ($berkas === []) {
            return null;
        }
        rsort($berkas);

        return (time() - $disk->lastModified($berkas[0])) / 3600;
    }

    /** Arsip yang manifestnya tak terbaca tetap ditampilkan — menyembunyikannya menyembunyikan masalah. */
    private function bacaManifest(string $pathAbsolut): array
    {
        $zip = new ZipArchive;
        if ($zip->open($pathAbsolut) !== true) {
            return [];
        }
        $isi = $zip->getFromName('manifest.json');
        $zip->close();

        return is_string($isi) ? (json_decode($isi, true) ?: []) : [];
    }

    private function rakitZip(string $tujuan, string $berkasDump, string $berkasManifest): void
    {
        $zip = new ZipArchive;
        if ($zip->open($tujuan, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Tidak bisa membuat berkas ZIP.');
        }

        if (! $zip->addFile($berkasDump, 'database.sql')) {
            throw new RuntimeException('Gagal menambahkan database.sql ke arsip.');
        }
        if (! $zip->addFile($berkasManifest, 'manifest.json')) {
            throw new RuntimeException('Gagal menambahkan manifest.json ke arsip.');
        }

        // Salin berkas unggahan, KECUALI direktori backup itu sendiri — kalau
        // ikut, tiap arsip memuat seluruh pendahulunya.
        $disk = Storage::disk('local');
        foreach ($disk->allFiles() as $relatif) {
            if (str_starts_with($relatif, self::DIREKTORI.'/')) {
                continue;
            }

            // Pengecekan ini cuma menangkap berkas yang SUDAH lenyap sebelum
            // addFile() dipanggil (mis. terhapus di antara allFiles() dan
            // baris ini). libzip malas: addFile() cuma mendaftarkan path,
            // isinya baru dibaca saat close() — kalau berkas lenyap SESUDAH
            // baris ini (jendela yang jauh lebih lebar, karena banyak berkas
            // didaftarkan sebelum satu close() di akhir), close() di bawah
            // yang menangkapnya, bukan pengecekan ini.
            if (! $zip->addFile($disk->path($relatif), 'berkas/'.$relatif)) {
                throw new RuntimeException("Gagal menambahkan berkas unggahan ke arsip: {$relatif}");
            }
        }

        // Menangkap berkas yang lenyap/tak terbaca SESUDAH addFile() di atas
        // mendaftarkannya — libzip baru benar-benar membaca isinya di sini,
        // bukan saat addFile() dipanggil. Jangan ditelan diam.
        if (! $zip->close()) {
            throw new RuntimeException('Gagal menutup arsip ZIP.');
        }
    }

    private function jumlahTabel(): int
    {
        try {
            return count(DB::connection()->getSchemaBuilder()->getTableListing());
        } catch (\Throwable) {
            return 0;
        }
    }
}
