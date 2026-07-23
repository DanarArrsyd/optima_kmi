<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreQcRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['admin', 'staff_qc']);
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
            'qty_ng' => 'required|integer|min:0',
            'id_jenis_ng' => 'nullable|exists:master_jenis_ng,id',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $qtyOk = (int) $this->input('qty_ok', 0);
            $qtyNg = (int) $this->input('qty_ng', 0);
            if ($qtyOk + $qtyNg === 0) {
                $validator->errors()->add('qty_ok', 'Total qty check (OK + NG) harus lebih dari 0.');
            }
        });
    }
}
