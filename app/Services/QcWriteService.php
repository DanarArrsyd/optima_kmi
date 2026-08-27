<?php

namespace App\Services;

use App\Models\TransaksiQc;

/**
 * Menulis SATU baris LHP Quality beserta rincian NG-nya.
 *
 * Beda dari QcService, yang hanya menghitung (durasi, cycle time, target qty)
 * dan tidak pernah menyentuh database.
 *
 * Sengaja TIDAK membuka transaksi sendiri: pemanggil yang menentukan batasnya,
 * supaya satu batch berisi banyak baris bisa dibatalkan utuh. Pola yang sama
 * dipakai ProduksiWriteService.
 */
class QcWriteService
{
    public function __construct(
        private QcService $qcService,
        private NgDetailService $ngDetailService,
    ) {}

    /**
     * @param  array  $baris  payload satu baris yang sudah tervalidasi
     */
    public function simpan(array $baris): TransaksiQc
    {
        $ngRows = $baris['ng'] ?? [];

        /* Kunci bantu form, bukan kolom tabel. */
        unset($baris['ng'], $baris['ng_count']);

        $qtyNg = array_sum(array_column($ngRows, 'qty'));

        $cycleTime = $this->qcService->calculateActualCycleTime(
            $baris['jam_start'].':00',
            $baris['jam_finish'].':00',
            (int) $baris['qty_ok'],
            (int) $qtyNg
        );

        $qc = TransaksiQc::create([
            ...$baris,
            /* qty_ng ditulis 0 dulu; syncQc() yang menghitungnya dari rincian,
               supaya hanya ada SATU sumber angka itu. */
            'qty_ng' => 0,
            'actual_cycle_time' => $cycleTime,
            'created_by' => auth()->id(),
        ]);

        $this->ngDetailService->syncQc($qc, $ngRows);

        return $qc;
    }
}
