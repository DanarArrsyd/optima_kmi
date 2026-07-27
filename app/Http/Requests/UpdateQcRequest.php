<?php

namespace App\Http\Requests;

use App\Models\TransaksiQc;
use App\Services\NgDetailService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateQcRequest extends FormRequest
{
    protected $errorBag = 'updateQc';

    public function authorize(): bool
    {
        return $this->user()->hasRole(['admin']);
    }

    public function rules(): array
    {
        return [
            'tgl_pengecekan' => 'required|date|after_or_equal:2020-01-01|before_or_equal:tomorrow',
            'tgl_produksi_part' => 'required|date|after_or_equal:2020-01-01|before_or_equal:tomorrow',
            'id_shift' => 'required|exists:master_shift,id',
            'id_karyawan_qc' => 'required|exists:master_karyawan,id',
            'id_part_proses' => 'required|exists:master_part_proses,id',
            'jam_start' => 'required|date_format:H:i',
            'jam_finish' => 'required|date_format:H:i',
            'qty_ok' => 'required|integer|min:0',
            'ng_count' => 'required|integer|min:0',
            'ng' => 'array',
            'ng.*.id_jenis_ng' => 'required|integer|exists:master_jenis_ng,id|distinct',
            'ng.*.qty' => 'required|integer|min:1',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $qtyOk = (int) $this->input('qty_ok', 0);
            $qtyNg = collect($this->input('ng', []))->sum(fn ($r) => (int) ($r['qty'] ?? 0));

            if ($qtyOk + $qtyNg === 0) {
                $validator->errors()->add('qty_ok', 'Total qty check (OK + NG) harus lebih dari 0.');
            }

            $qc = $this->route('qc');

            if (! $qc instanceof TransaksiQc) {
                return;
            }

            /* Tabel detail belum ada di jendela antara rsync dan migrate. */
            if (! NgDetailService::available()) {
                return;
            }

            /*
             * Baris gaya lama: qty_ng terisi tapi nol baris detail. Menyimpan
             * dengan ng kosong akan menulis qty_ng = 0 dan menghapus angka NG
             * yang pernah tercatat, tanpa error. Paksa klasifikasi dulu.
             */
            $legacy = (int) $qc->qty_ng > 0 && $qc->ngDetails()->count() === 0;

            if ($legacy && empty($this->input('ng'))) {
                $validator->errors()->add(
                    'ng',
                    'Baris ini punya '.(int) $qc->qty_ng.' NG dari data lama yang belum diklasifikasi. '
                    .'Pilih jenis NG-nya sebelum menyimpan.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'ng.*.id_jenis_ng.distinct' => 'Jenis NG yang sama tidak boleh diisi dua kali dalam satu sesi.',
            'ng.*.id_jenis_ng.required' => 'Pilih jenis NG untuk setiap baris.',
            'ng.*.qty.min' => 'Qty NG minimal 1.',
        ];
    }
}
