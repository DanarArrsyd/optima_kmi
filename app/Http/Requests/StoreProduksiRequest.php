<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProduksiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['admin', 'staff_produksi']);
    }

    public function rules(): array
    {
        return [
            'tanggal_produksi' => 'required|date|after_or_equal:2020-01-01|before_or_equal:tomorrow',
            'id_shift' => 'required|exists:master_shift,id',
            'id_karyawan' => 'required|exists:master_karyawan,id',
            'id_mesin' => 'required|exists:master_mesin,id',
            'id_part_proses' => 'required|exists:master_part_proses,id',
            'jam_start' => 'required|date_format:H:i',
            'jam_finish' => 'required|date_format:H:i',
            'qty_aktual' => 'required|integer|min:1',
            'ada_ng' => 'nullable|boolean',
            'ng' => 'array|required_if:ada_ng,1',
            'ng.*.id_jenis_ng' => 'required|integer|exists:master_jenis_ng,id|distinct',
            'ng.*.qty' => 'required|integer|min:1',
        ];
    }

    protected function prepareForValidation(): void
    {
        /* Kalau ada_ng tidak dicentang, buang seluruh baris NG. */
        if (! $this->boolean('ada_ng')) {
            $this->merge(['ng' => []]);
        }
    }

    public function messages(): array
    {
        return [
            'ng.required_if' => 'Centang "ada NG" berarti minimal satu jenis NG harus diisi.',
            'ng.*.id_jenis_ng.distinct' => 'Jenis NG yang sama tidak boleh diisi dua kali dalam satu sesi.',
            'ng.*.id_jenis_ng.required' => 'Pilih jenis NG untuk setiap baris.',
            'ng.*.qty.min' => 'Qty NG minimal 1.',
        ];
    }
}
