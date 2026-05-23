<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMasterLosstimeRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()->hasRole('admin'); }
    public function rules(): array
    {
        $id = $this->route('losstime')?->id;
        return [
            'kode'                  => [
                'required', 'string', 'max:10',
                Rule::unique('master_losstime', 'kode')->ignore($id)->whereNull('deleted_at'),
            ],
            'jenis_losstime'        => 'required|string|max:100',
            'std_menit_default'     => 'nullable|integer|min:0',
            'std_menit_progressive' => 'nullable|integer|min:0',
        ];
    }
}
