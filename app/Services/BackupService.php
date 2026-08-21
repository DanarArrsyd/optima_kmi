<?php

namespace App\Services;

use App\Contracts\PembuatDump;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class BackupService
{
    public const DIREKTORI = 'backups';

    // Induk direktori kerja sementara — path tetap (bukan bagian uniqid-nya)
    // supaya bisa diperiksa deterministik oleh test tanpa menebak nama persis.
    public const DIREKTORI_KERJA = 'app/backup-sementara';

    public function __construct(private PembuatDump $pembuatDump) {}

    /**
     * Merakit satu arsip. Mengembalikan nama berkasnya.
     *
     * Seluruh pekerjaan dilakukan di direktori sementara lebih dulu; ZIP baru
     * dipindahkan ke tujuan setelah utuh. Kalau ada langkah yang gagal, yang
     * tersisa nol berkas — bukan arsip separuh jadi yang terlihat sah di daftar.
     */
    public function jalankan(string $sumber, ?int $userId = null): string
    {
        $waktu = now('Asia/Jakarta');
        // Detik disertakan supaya pemicu manual yang jatuh di menit yang sama
        // dengan jadwal otomatis tidak menimpa arsip yang sudah ada lewat rename().
        $nama = 'optima-'.$waktu->format('Y-m-d_His').'.zip';

        $kerja = storage_path(self::DIREKTORI_KERJA.'/'.uniqid());
        File::ensureDirectoryExists($kerja);

        try {
            $berkasDump = $kerja.'/database.sql';
            $this->pembuatDump->keBerkas($berkasDump);

            $manifest = [
                'waktu_wib' => $waktu->format('Y-m-d H:i:s'),
                'waktu_utc' => now()->format('Y-m-d H:i:s'),
                'sumber' => $sumber,
                'dipicu_oleh' => $userId,
                'jumlah_tabel' => $this->jumlahTabel(),
                'ukuran_dump' => filesize($berkasDump),
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

        $this->pangkas();

        return $nama;
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

    public function hapus(string $nama): void
    {
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

            // Berkas bisa lenyap/tak terbaca di antara allFiles() dan addFile()
            // ini (dihapus proses lain saat backup berjalan) — jangan ditelan diam.
            if (! $zip->addFile($disk->path($relatif), 'berkas/'.$relatif)) {
                throw new RuntimeException("Gagal menambahkan berkas unggahan ke arsip: {$relatif}");
            }
        }

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
