<?php

namespace App\Services;

use App\Models\TransaksiRepairProduksi;

/**
 * Menulis SATU baris LHR Produksi. Sengaja TIDAK membuka transaksi sendiri:
 * pemanggil (RepairProduksiController::batch) yang menentukan batasnya.
 */
class RepairProduksiWriteService
{
    public function simpan(array $baris): TransaksiRepairProduksi
    {
        $cycleTime = $this->hitungCycleTime($baris['jam_start'].':00', $baris['jam_finish'].':00', (int) $baris['qty_aktual']);

        return TransaksiRepairProduksi::create([
            ...$baris,
            'actual_cycle_time' => $cycleTime,
            'created_by' => auth()->id(),
        ]);
    }

    /** Cycle time aktual (detik / qty), dipakai saat create MAUPUN update. */
    public function hitungCycleTime(string $jamStart, string $jamFinish, int $qtyAktual): float
    {
        if ($qtyAktual === 0) {
            return 0.0;
        }

        return round($this->durasiDetik($jamStart, $jamFinish) / $qtyAktual, 2);
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
