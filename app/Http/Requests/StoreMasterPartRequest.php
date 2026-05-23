<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMasterPartRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()->hasRole('admin'); }
    public function rules(): array
    {
        $id = $this->route('part')?->id;
        return [
            'part_no'   => [
                'required', 'string', 'max:50',
                Rule::unique('master_part', 'part_no')->ignore($id)->whereNull('deleted_at'),
            ],
            'part_name' => 'required|string|max:200',
        ];
    }
}
