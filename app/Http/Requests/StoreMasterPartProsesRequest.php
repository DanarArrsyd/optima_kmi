<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMasterPartProsesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        $partId = $this->route('part')?->id;

        return [
            'id_proses' => [
                'required', 'string', 'max:10',
                Rule::unique('master_part_proses', 'id_proses')->where('id_part', $partId),
            ],
            'nama_proses' => 'required|string|max:100',
            'sph_standar_produksi' => 'required|numeric|min:0',
            'sph_standar_qc' => 'required|numeric|min:0',
            'std_trial_qc_menit' => 'required|integer|min:0',
            'std_setting_dies_menit' => 'required|integer|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'id_proses.unique' => 'ID Proses ini sudah ada untuk part ini. Gunakan ID yang berbeda.',
            'std_setting_dies_menit.min' => 'Std Setting Dies harus ≥ 0.',
            'std_trial_qc_menit.min' => 'Std Trial QC harus ≥ 0.',
        ];
    }
}
