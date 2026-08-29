<?php

namespace App\Services;

use App\Models\TransaksiBreakdownMesin;

/**
 * Menulis SATU baris Breakdown Mesin.
 *
 * Pemeriksaan tabrakan No LKM TIDAK di sini — itu urusan
 * StoreBreakdownMesinBatchRequest, supaya seluruh batch ditolak sebelum satu
 * baris pun tertulis. Sengaja TIDAK membuka transaksi sendiri: pemanggil yang
 * menentukan batasnya, supaya satu batch berisi banyak baris bisa dibatalkan
 * utuh (pola sama dengan ProduksiWriteService).
 */
class BreakdownMesinWriteService
{
    public function __construct(private BreakdownMesinService $svc) {}

    /**
     * @param  array  $baris  payload satu baris yang sudah tervalidasi
     */
    public function simpan(array $baris): TransaksiBreakdownMesin
    {
        $noLkm = $this->svc->generateNoLkm((int) $baris['nomor_urut'], $baris['tanggal_breakdown']);
        $derived = $this->svc->computeDerived($baris);

        return TransaksiBreakdownMesin::create([
            'no_lkm' => $noLkm,
            'id_mesin' => $baris['id_mesin'],
            'tanggal_breakdown' => $baris['tanggal_breakdown'],
            'jam_breakdown' => $baris['jam_breakdown'],
            'tanggal_mulai_perbaikan' => $baris['tanggal_mulai_perbaikan'],
            'jam_mulai_perbaikan' => $baris['jam_mulai_perbaikan'],
            'tanggal_selesai_perbaikan' => $baris['tanggal_selesai_perbaikan'],
            'jam_selesai_perbaikan' => $baris['jam_selesai_perbaikan'],
            'problem_mesin' => $baris['problem_mesin'],
            'penyebab_breakdown' => $baris['penyebab_breakdown'],
            'tindakan_perbaikan' => $baris['tindakan_perbaikan'],
            'pic_maintenance' => $baris['pic_maintenance'],
            ...$derived,
        ]);
    }
}
