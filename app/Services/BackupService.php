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
        $nama = 'optima-'.$waktu->format('Y-m-d_Hi').'.zip';

        $kerja = storage_path('app/backup-sementara-'.uniqid());
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
            File::move($zipSementara, Storage::disk('local')->path(self::DIREKTORI.'/'.$nama));
        } finally {
            File::deleteDirectory($kerja);
        }

        return $nama;
    }

    private function rakitZip(string $tujuan, string $berkasDump, string $berkasManifest): void
    {
        $zip = new ZipArchive;
        if ($zip->open($tujuan, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Tidak bisa membuat berkas ZIP.');
        }

        $zip->addFile($berkasDump, 'database.sql');
        $zip->addFile($berkasManifest, 'manifest.json');

        // Salin berkas unggahan, KECUALI direktori backup itu sendiri — kalau
        // ikut, tiap arsip memuat seluruh pendahulunya.
        $disk = Storage::disk('local');
        foreach ($disk->allFiles() as $relatif) {
            if (str_starts_with($relatif, self::DIREKTORI.'/')) {
                continue;
            }
            $zip->addFile($disk->path($relatif), 'berkas/'.$relatif);
        }

        $zip->close();
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
