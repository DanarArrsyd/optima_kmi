<?php

namespace App\Http\Controllers\Concerns;

use App\Services\PenjagaKembar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Peringatan duplikat untuk store batch. Batch yang memuat baris kembar
 * dikembalikan ke halaman batch (draft tetap utuh, baris kembar ditandai
 * merah), lengkap dengan tombol "Tetap simpan" yang mengirim ulang batch yang
 * sama dengan izinkan_kembar=1 — lihat partials/peringatan-kembar.
 */
trait MenjagaKembar
{
    /**
     * @param  array<int, array{0: string, 1: callable|null}>  $pemeriksaan  [modul, pemecah baris]
     */
    protected function tolakKalauKembar(Request $request, array $rows, array $pemeriksaan): ?RedirectResponse
    {
        if ($request->boolean('izinkan_kembar')) {
            return null;
        }

        $galat = [];
        foreach ($pemeriksaan as [$modul, $pecah]) {
            $galat += app(PenjagaKembar::class)->periksaBatch($modul, $rows, $pecah);
        }

        if ($galat === []) {
            return null;
        }
        ksort($galat, SORT_NATURAL);

        return back()->withErrors($galat)->with('kembar_bisa_dilewati', count($galat));
    }
}
