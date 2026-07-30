<?php

namespace App\Http\Requests;

use App\Services\NgListService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Filter bersama dua halaman Daftar NG dan kedua export-nya, supaya layar dan
 * Excel tidak pernah menyaring dengan aturan berbeda.
 *
 * Semua filter OPSIONAL dengan sengaja. Endpoint `data` di dashboard NG memakai
 * `required|date`, dan meniru itu di sini membuat halaman balas 422 saat dibuka
 * lewat menu (tanpa query param sama sekali).
 */
class NgListFilterRequest extends FormRequest
{
    /** Otorisasi ditangani middleware `role:` di route, bukan di sini. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'jenis_ng_id' => 'nullable|integer|exists:master_jenis_ng,id',
            'q' => 'nullable|string|max:100',
            'karyawan_id' => 'nullable|integer|exists:master_karyawan,id',
        ];
    }

    public function messages(): array
    {
        return [
            'date_to.after_or_equal' => 'Tanggal akhir tidak boleh lebih awal dari tanggal mulai.',
            'jenis_ng_id.exists' => 'Jenis NG yang dipilih tidak ada.',
            'karyawan_id.exists' => 'Karyawan yang dipilih tidak ada.',
        ];
    }

    /**
     * Filter siap pakai untuk {@see NgListService}, dengan rentang
     * default 12 bulan terakhir — sama dengan dashboard NG.
     *
     * `first day of -11 months` dipakai dengan sengaja: `-11 months` biasa meluap
     * pada tanggal 29–31 (31 Maret − 11 bulan = 1 Mei) dan diam-diam memotong
     * rentang jadi 11 bulan.
     *
     * @return array{date_from: string, date_to: string, jenis_ng_id: int|null,
     *               q: string|null, karyawan_id: int|null}
     */
    public function filter(): array
    {
        return [
            'date_from' => $this->input('date_from') ?: now()->modify('first day of -11 months')->format('Y-m-d'),
            'date_to' => $this->input('date_to') ?: now()->format('Y-m-d'),
            'jenis_ng_id' => $this->filled('jenis_ng_id') ? (int) $this->input('jenis_ng_id') : null,
            'q' => $this->filled('q') ? trim((string) $this->input('q')) : null,
            'karyawan_id' => $this->filled('karyawan_id') ? (int) $this->input('karyawan_id') : null,
        ];
    }
}
