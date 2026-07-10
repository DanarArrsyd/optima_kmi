<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMasterKaryawanRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()->hasRole('admin'); }

    public function rules(): array
    {
        $id = $this->route('karyawan')?->id;
        return [
            'nik'           => 'required|string|max:50',
            'nama_karyawan' => 'required|string|max:100',
            'departemen'    => 'required|in:Produksi,QC,Maintenance',
        ];
    }
}
