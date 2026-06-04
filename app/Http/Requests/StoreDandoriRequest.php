<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class StoreDandoriRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['admin', 'staff_produksi']);
    }

    public function rules(): array
    {
        return [
            'tanggal'                  => 'required|date',
            'id_shift'                 => 'required|exists:master_shift,id',
            'id_karyawan'              => 'required|exists:master_karyawan,id',
            'id_mesin'                 => 'required|exists:master_mesin,id',
            'id_part_proses'           => 'required|exists:master_part_proses,id',
            'rincian'                  => 'required|array|min:1',
            'rincian.*.id_losstime'    => 'required|exists:master_losstime,id',
            'rincian.*.actual_menit'   => 'required|numeric|min:0.01',
        ];
    }

    public function messages(): array
    {
        return [
            'rincian.required'                  => 'Minimal harus ada 1 aktivitas loss time.',
            'rincian.min'                       => 'Minimal harus ada 1 aktivitas loss time.',
            'rincian.*.id_losstime.required'    => 'Pilih jenis loss time.',
            'rincian.*.actual_menit.required'   => 'Masukkan waktu aktual (menit).',
            'rincian.*.actual_menit.min'        => 'Waktu aktual harus lebih dari 0.',
        ];
    }
}
