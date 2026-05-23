<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class StoreMasterPartProsesRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()->hasRole('admin'); }
    public function rules(): array
    {
        return [
            'id_proses'                   => 'required|string|max:10',
            'nama_proses'                 => 'required|string|max:100',
            'sph_standar_produksi'        => 'required|numeric|min:0',
            'cycle_time_standar_produksi' => 'required|numeric|min:0',
            'sph_standar_qc'              => 'required|numeric|min:0',
            'cycle_time_standar_qc'       => 'required|numeric|min:0',
            'std_dandori_menit'           => 'required|integer|min:1',
        ];
    }
}
