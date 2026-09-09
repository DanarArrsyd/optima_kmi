<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRepairRequest extends FormRequest
{
    protected $errorBag = 'updateRepair';

    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            'tanggal_repair' => 'required|date|after_or_equal:2020-01-01|before_or_equal:tomorrow',
            'id_shift' => 'required|exists:master_shift,id',
            'id_karyawan' => 'required|exists:master_karyawan,id',
            'id_part_proses_repair' => 'required|exists:master_part_proses_repair,id',
            'jam_start' => 'required|date_format:H:i',
            'jam_finish' => 'required|date_format:H:i',
            'qty_aktual' => 'required|integer|min:1',
        ];
    }
}
