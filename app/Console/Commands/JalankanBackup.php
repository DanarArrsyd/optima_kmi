<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class JalankanBackup extends Command
{
    protected $signature = 'backup:jalankan';

    protected $description = 'Membuat satu arsip backup (dump database + berkas unggahan)';

    public function handle(BackupService $backup): int
    {
        try {
            $nama = $backup->jalankan('terjadwal', null);
            $this->info("Backup selesai: {$nama}");

            return self::SUCCESS;
        } catch (Throwable $e) {
            // Kode keluar bukan nol supaya workflow-nya MERAH, bukan hijau
            // dengan pesan galat yang tidak dibaca siapa pun.
            //
            // Pesan aslinya bisa memuat detail infrastruktur server (mis. host
            // & port dari galat koneksi mysqldump). Itu masuk log, BUKAN ke
            // keluaran yang mengalir lewat SSH ke log run GitHub Actions —
            // siapa pun dengan akses repo bisa membacanya di sana.
            Log::error('Backup terjadwal gagal: '.$e->getMessage());

            $this->error('Backup gagal. Periksa storage/logs/laravel.log untuk sebabnya.');

            return self::FAILURE;
        }
    }
}
