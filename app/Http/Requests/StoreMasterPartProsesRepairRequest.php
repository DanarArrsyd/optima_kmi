<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMasterPartProsesRepairRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        $id = $this->route('prosesRepair')?->id;

        return [
            'id_part' => 'required|exists:master_part,id',
            'id_proses' => [
                'required', 'string', 'max:50',
                Rule::unique('master_part_proses_repair', 'id_proses')
                    ->where('id_part', $this->input('id_part'))
                    ->ignore($id)
                    ->whereNull('deleted_at'),
            ],
            'nama_proses' => 'required|string|max:100',
            'sph_standar_repair' => 'nullable|numeric|min:0',
        ];
    }
}
