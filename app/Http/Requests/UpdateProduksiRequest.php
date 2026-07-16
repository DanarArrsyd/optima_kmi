<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProduksiRequest extends FormRequest
{
    /** Named bag — supaya error edit tidak bercampur dengan error create form di halaman index */
    protected $errorBag = 'updateProduksi';

    public function authorize(): bool
    {
        return $this->user()->hasRole(['admin']);
    }

    public function rules(): array
    {
        return [
            'tanggal_produksi' => 'required|date|after_or_equal:2020-01-01|before_or_equal:tomorrow',
            'id_shift'         => 'required|exists:master_shift,id',
            'id_karyawan'      => 'required|exists:master_karyawan,id',
            'id_mesin'         => 'required|exists:master_mesin,id',
            'id_part_proses'   => 'required|exists:master_part_proses,id',
            'jam_start'        => 'required|date_format:H:i',
            'jam_finish'       => 'required|date_format:H:i',
            'qty_aktual'       => 'required|integer|min:1',
            'ada_ng'           => 'nullable|boolean',
            'qty_ng'           => 'nullable|integer|min:1|required_if:ada_ng,1',
            'id_jenis_ng'      => 'nullable|exists:master_jenis_ng,id|required_if:ada_ng,1',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (!$this->boolean('ada_ng')) {
            $this->merge(['qty_ng' => null, 'id_jenis_ng' => null]);
        }
    }
}
