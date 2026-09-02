<?php

namespace App\Console\Commands;

use App\Services\PreventiveScheduleService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class JadwalkanPreventive extends Command
{
    protected $signature = 'preventive:jadwalkan';

    protected $description = 'Generate jadwal preventive maintenance (mesin & dies) untuk bulan berjalan';

    public function handle(PreventiveScheduleService $svc): int
    {
        $bulan = Carbon::now()->timezone('Asia/Jakarta')->startOfMonth();

        try {
            foreach (['mesin', 'dies'] as $jenis) {
                $schedule = $svc->generate($jenis, $bulan);
                $terjadwal = $schedule->items()->whereNotNull('tanggal_rencana')->count();
                $backlog = $schedule->items()->whereNull('tanggal_rencana')->count();
                $this->info("Jadwal {$jenis} {$bulan->format('F Y')}: {$schedule->nomor_dokumen}, {$terjadwal} item terjadwal, {$backlog} backlog.");
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            // Kode keluar bukan nol supaya workflow-nya MERAH — pola sama JalankanBackup.
            Log::error('Generate jadwal preventive gagal: '.$e->getMessage());
            $this->error('Generate jadwal preventive gagal. Periksa storage/logs/laravel.log.');

            return self::FAILURE;
        }
    }
}
