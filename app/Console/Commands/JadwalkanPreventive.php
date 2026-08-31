<?php

namespace App\Console\Commands;

use App\Services\PreventiveScheduleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Throwable;

class JadwalkanPreventive extends Command
{
    protected $signature = 'preventive:jadwalkan';

    protected $description = 'Generate jadwal preventive maintenance (mesin & dies) untuk bulan berjalan';

    public function handle(PreventiveScheduleService $svc): int
    {
        $bulan = Carbon::now()->startOfMonth();

        try {
            foreach (['mesin', 'dies'] as $jenis) {
                $schedule = $svc->generate($jenis, $bulan);
                $this->info("Jadwal {$jenis} {$bulan->format('F Y')}: {$schedule->nomor_dokumen}, {$schedule->items()->count()} item.");
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
