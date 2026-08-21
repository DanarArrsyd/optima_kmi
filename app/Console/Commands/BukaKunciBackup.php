<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Klep darurat untuk lock 'backup-berjalan' yang macet.
 *
 * Backup manual (BackupController::jalankan) berjalan sinkron di dalam
 * request web. Kalau request itu dimatikan paksa oleh batas waktu PHP-FPM/
 * LSAPI di shared hosting (mis. admin menutup tab, koneksi lambat) sebelum
 * BackupService::jalankan() selesai, blok `finally` yang melepas lock
 * TIDAK PERNAH sampai jalan — prosesnya dibunuh dari luar PHP, bukan lewat
 * exception yang bisa ditangkap. Lock itu lalu bertahan sampai
 * BackupService::TTL_LOCK penuh (1800 detik) dan menolak backup berikutnya
 * (termasuk jadwal terjadwal 23:00 WIB) dengan BackupSedangBerjalanException,
 * tanpa cara memulihkannya selain menunggu penuh.
 *
 * Perintah ini melepas paksa lock itu lewat SSH tanpa menunggu TTL habis.
 * Dipakai admin saat "Backup sedang berjalan" menetap padahal tidak ada
 * backup yang sungguhan sedang jalan.
 */
class BukaKunciBackup extends Command
{
    protected $signature = 'backup:buka-kunci';

    protected $description = 'Lepas paksa lock backup-berjalan yang macet (backup manual terputus request web)';

    public function handle(): int
    {
        Cache::lock(BackupService::NAMA_LOCK)->forceRelease();

        $this->info('Lock backup-berjalan sudah dilepas paksa.');

        return self::SUCCESS;
    }
}
