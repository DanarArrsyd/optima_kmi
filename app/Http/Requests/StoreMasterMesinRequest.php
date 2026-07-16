<?php
namespace App\Http\Requests;
use App\Models\MasterMesin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMasterMesinRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()->hasRole('admin'); }
    public function rules(): array
    {
        $id = $this->route('mesin')?->id;
        return [
            'id_mesin'             => [
                'required', 'string', 'max:20',
                Rule::unique('master_mesin', 'id_mesin')->ignore($id)->whereNull('deleted_at'),
            ],
            'nama_mesin'           => 'required|string|max:100',
            'line'                 => 'required|string|max:10',
            'tipe_mesin'           => ['required', Rule::in(MasterMesin::TIPE_MESIN)],
            'kapasitas_max_stroke' => 'nullable|integer|min:0',
            'tanggal_instalasi'    => 'nullable|date|after_or_equal:1990-01-01|before_or_equal:tomorrow',
        ];
    }
}
