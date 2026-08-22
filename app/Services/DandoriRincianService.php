<?php

namespace App\Services;

use App\Models\MasterLosstime;
use App\Models\MasterPartProses;
use App\Models\TransaksiDandoriRincian;

/**
 * Satu-satunya tempat yang menentukan std_menit dan over_menit sebuah baris
 * dandori. Dipakai dua jalur input — form Dandori dan form LHP Produksi.
 *
 * Sengaja satu definisi: dua jalur yang menghitungnya sendiri-sendiri pasti
 * menyimpang, dan repo ini sudah membuktikannya berkali-kali pada komponen
 * yang disalin.
 */
class DandoriRincianService
{
    /**
     * @param  array  $sesi  tanggal, id_shift, id_karyawan, id_mesin, id_part_proses
     * @param  array  $rincian  [['id_losstime' => int, 'actual_menit' => float], ...]
     * @return int jumlah baris yang ditulis
     */
    public function simpan(array $sesi, array $rincian): int
    {
        if ($rincian === []) {
            return 0;
        }

        $partProses = MasterPartProses::findOrFail($sesi['id_part_proses']);
        $isProgressive = strtoupper(trim($partProses->nama_proses)) === 'PROGRESSIVE';

        $losstimeMap = MasterLosstime::whereIn(
            'id',
            collect($rincian)->pluck('id_losstime')->unique()
        )->get()->keyBy('id');

        foreach ($rincian as $item) {
            $losstime = $losstimeMap->get($item['id_losstime']);

            $stdMenit = $isProgressive
                ? ($losstime->std_menit_progressive ?? $losstime->std_menit_default ?? 0)
                : ($losstime->std_menit_default ?? 0);

            $actualMenit = (float) $item['actual_menit'];

            TransaksiDandoriRincian::create([
                'tanggal' => $sesi['tanggal'],
                'id_shift' => $sesi['id_shift'],
                'id_karyawan' => $sesi['id_karyawan'],
                'id_mesin' => $sesi['id_mesin'],
                'id_part_proses' => $sesi['id_part_proses'],
                'id_losstime' => $item['id_losstime'],
                'actual_menit' => $actualMenit,
                'std_menit' => $stdMenit,
                'over_menit' => max(0, $actualMenit - $stdMenit),
                'created_by' => auth()->id(),
            ]);
        }

        return count($rincian);
    }
}
