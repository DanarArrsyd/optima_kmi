<?php

namespace App\Imports\Concerns;

/**
 * Melewati baris contoh template yang belum diubah pengguna.
 *
 * Template import memuat baris contoh yang mirip data asli (M001, "Shift
 * Malam 22:00–06:00", Part BT-091A-3). Pengguna yang mengisi di bawahnya tanpa
 * menghapus contoh dulu ikut mengimpor contoh itu ke tabel master/transaksi —
 * dan shift palsu langsung menggeser Availability historis.
 *
 * Kelas pemakai WAJIB mendefinisikan `public const CONTOH` (daftar baris).
 * Template membaca konstanta yang SAMA, jadi keduanya tidak bisa melenceng.
 * Baris yang sudah ditimpa data asli tidak cocok lagi dan tetap diimpor —
 * melewati baris berdasarkan nomornya akan membuang data asli tanpa tanda.
 */
trait LewatiBarisContoh
{
    /** @param  iterable<mixed>  $baris  satu baris, urut sesuai kolom template */
    protected function barisContoh(iterable $baris): bool
    {
        $nilai = array_values(is_array($baris) ? $baris : iterator_to_array($baris));

        foreach (static::CONTOH as $contoh) {
            if ($this->cocokContoh($nilai, $contoh)) {
                return true;
            }
        }

        return false;
    }

    private function cocokContoh(array $nilai, array $contoh): bool
    {
        foreach (array_values($contoh) as $i => $sel) {
            if ($this->normalSel($nilai[$i] ?? null) !== $this->normalSel($sel)) {
                return false;
            }
        }

        return true;
    }

    private function normalSel(mixed $sel): string
    {
        return mb_strtolower(trim((string) $sel));
    }
}
