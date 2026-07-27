<?php

namespace App\Models\Concerns;

use App\Services\NgDetailService;

/**
 * Ringkasan jenis NG satu sesi sebagai satu string: "DNT (5); CRK (2)".
 *
 * Dipakai empat tempat — dua export Excel dan dua daftar index. Ditaruh di satu
 * accessor supaya format ekspor dan format layar tidak bisa berbeda diam-diam.
 *
 * Baris lama yang punya qty_ng tapi nol baris detail menghasilkan string kosong.
 * Itu benar: jenisnya memang tidak pernah tercatat. Angka qty_ng-nya tetap
 * ditampilkan di kolomnya sendiri, jadi tidak ada yang hilang.
 */
trait HasNgSummary
{
    public function getNgRingkasAttribute(): string
    {
        /*
         * Relasi sudah di-eager-load di semua pemanggil. Kalau belum, pastikan
         * tabelnya ada sebelum lazy-load — kode dan skema tidak dijamin tiba
         * bersamaan di produksi, dan daftar index tidak boleh 500 karenanya.
         */
        if (! $this->relationLoaded('ngDetails') && ! NgDetailService::available()) {
            return '';
        }

        return $this->ngDetails
            ->map(fn ($d) => ($d->jenisNg?->kode ?? '?').' ('.$d->qty.')')
            ->implode('; ');
    }
}
