<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class StoreMasterShiftRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()->hasRole('admin'); }
    public function rules(): array
    {
        return [
            'nama_shift' => 'required|string|max:50',
            'jam_start'  => 'required|date_format:H:i',
            'jam_finish' => 'required|date_format:H:i',
        ];
    }
}
