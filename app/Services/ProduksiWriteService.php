<?php

namespace App\Services;

use App\Models\TransaksiProduksi;

/**
 * Menulis SATU baris LHP Produksi beserta rincian NG dan dandori-nya.
 *
 * Beda dari ProduksiService, yang hanya menghitung (durasi, cycle time, target
 * qty) dan tidak pernah menyentuh database.
 *
 * Sengaja TIDAK membuka transaksi sendiri: pemanggil yang menentukan batasnya,
 * supaya satu batch berisi banyak baris bisa dibatalkan utuh.
 */
class ProduksiWriteService
{
    public function __construct(
        private ProduksiService $produksiService,
        private NgDetailService $ngDetailService,
        private DandoriRincianService $dandoriRincianService,
    ) {}

    /**
     * @param  array  $baris  payload satu baris yang sudah tervalidasi
     */
    public function simpan(array $baris): TransaksiProduksi
    {
        $ngRows = $baris['ng'] ?? [];
        $dandoriRows = $baris['dandori'] ?? [];
        $dandoriKaryawan = $baris['dandori_karyawan_id'] ?? $baris['id_karyawan'];

        /* Kunci bantu form, bukan kolom tabel. */
        unset(
            $baris['ng'], $baris['ada_ng'], $baris['ng_count'],
            $baris['dandori'], $baris['ada_dandori'], $baris['dandori_count'],
            $baris['dandori_karyawan_id'],
        );

        $cycleTime = $this->produksiService->calculateActualCycleTime(
            $baris['jam_start'].':00',
            $baris['jam_finish'].':00',
            $baris['qty_aktual']
        );

        $produksi = TransaksiProduksi::create([
            ...$baris,
            'actual_cycle_time' => $cycleTime,
            'created_by' => auth()->id(),
        ]);

        $this->ngDetailService->syncProduksi($produksi, $ngRows);

        /*
         * Baris dandori sengaja TIDAK menyimpan tautan ke baris LHP: satu
         * kombinasi tanggal+shift+mesin+proses bisa punya sampai lima baris
         * LHP, jadi tautan apa pun butuh aturan sewenang-wenang. Ia cuma
         * menyalin field sesi — bentuk barisnya identik dengan yang ditulis
         * form Dandori, sehingga tidak satu pun perhitungan berubah.
         */
        $this->dandoriRincianService->simpan([
            'tanggal' => $baris['tanggal_produksi'],
            'id_shift' => $baris['id_shift'],
            'id_karyawan' => $dandoriKaryawan,
            'id_mesin' => $baris['id_mesin'],
            'id_part_proses' => $baris['id_part_proses'],
        ], $dandoriRows);

        return $produksi;
    }
}
