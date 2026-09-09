<?php

namespace App\Services;

use App\Models\TransaksiRepair;

/**
 * Menulis SATU baris LHR. Sengaja TIDAK membuka transaksi sendiri: pemanggil
 * (RepairController::batch) yang menentukan batasnya, supaya satu batch berisi
 * banyak baris bisa dibatalkan utuh.
 */
class RepairWriteService
{
    public function simpan(array $baris): TransaksiRepair
    {
        $durasiDetik = $this->durasiDetik($baris['jam_start'].':00', $baris['jam_finish'].':00');
        $cycleTime = (int) $baris['qty_aktual'] > 0 ? round($durasiDetik / $baris['qty_aktual'], 2) : 0.0;

        return TransaksiRepair::create([
            ...$baris,
            'actual_cycle_time' => $cycleTime,
            'created_by' => auth()->id(),
        ]);
    }

    /** Durasi dalam detik, rentang lewat tengah malam ditangani (selesai <= mulai berarti +1 hari). */
    private function durasiDetik(string $jamStart, string $jamFinish): int
    {
        $mulai = strtotime("2000-01-01 {$jamStart}");
        $selesai = strtotime("2000-01-01 {$jamFinish}");
        if ($selesai <= $mulai) {
            $selesai += 86400;
        }

        return $selesai - $mulai;
    }
}
