<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
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
            $this->error('Backup gagal: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
