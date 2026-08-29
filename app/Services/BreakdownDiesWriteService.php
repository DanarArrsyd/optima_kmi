<?php

namespace App\Services;

use App\Models\TransaksiBreakdownDies;

/**
 * Menulis SATU baris Breakdown Dies.
 *
 * Pemeriksaan tabrakan No LKD TIDAK di sini — itu urusan
 * StoreBreakdownDiesBatchRequest, supaya seluruh batch ditolak sebelum satu
 * baris pun tertulis. Sengaja TIDAK membuka transaksi sendiri: pemanggil yang
 * menentukan batasnya, supaya satu batch berisi banyak baris bisa dibatalkan
 * utuh (pola sama dengan BreakdownMesinWriteService).
 */
class BreakdownDiesWriteService
{
    public function __construct(private BreakdownDiesService $svc) {}

    /**
     * @param  array  $baris  payload satu baris yang sudah tervalidasi
     */
    public function simpan(array $baris): TransaksiBreakdownDies
    {
        $noLkd = $this->svc->generateNoLkd((int) $baris['nomor_urut'], $baris['tanggal_breakdown']);
        $derived = $this->svc->computeDerived($baris);

        return TransaksiBreakdownDies::create([
            'no_lkd' => $noLkd,
            'id_part_proses' => $baris['id_part_proses'],
            'tanggal_breakdown' => $baris['tanggal_breakdown'],
            'jam_breakdown' => $baris['jam_breakdown'],
            'tanggal_mulai_perbaikan' => $baris['tanggal_mulai_perbaikan'],
            'jam_mulai_perbaikan' => $baris['jam_mulai_perbaikan'],
            'tanggal_selesai_perbaikan' => $baris['tanggal_selesai_perbaikan'],
            'jam_selesai_perbaikan' => $baris['jam_selesai_perbaikan'],
            'problem_dies' => $baris['problem_dies'],
            'penyebab_breakdown' => $baris['penyebab_breakdown'],
            'tindakan_perbaikan' => $baris['tindakan_perbaikan'],
            'pic_maintenance' => $baris['pic_maintenance'],
            ...$derived,
        ]);
    }
}
