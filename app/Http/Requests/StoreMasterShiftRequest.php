<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMasterShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            'nama_shift' => 'required|string|max:50',
            // Senin–Kamis
            'jam_start' => 'required|date_format:H:i',
            'jam_finish' => 'required|date_format:H:i',
            // Jumat (opsional)
            'jam_start_jumat' => 'nullable|date_format:H:i',
            'jam_finish_jumat' => 'nullable|date_format:H:i|required_with:jam_start_jumat',
            // Sabtu (opsional)
            'jam_start_sabtu' => 'nullable|date_format:H:i',
            'jam_finish_sabtu' => 'nullable|date_format:H:i|required_with:jam_start_sabtu',
            // Istirahat rows
            'istirahat' => 'nullable|array',
            'istirahat.*' => 'array',
            'istirahat.*.*.jam_start' => 'nullable|date_format:H:i',
            'istirahat.*.*.jam_finish' => 'nullable|date_format:H:i',
        ];
    }

    public function messages(): array
    {
        return [
            'jam_start.required' => 'Jam start Senin–Kamis wajib diisi.',
            'jam_finish.required' => 'Jam finish Senin–Kamis wajib diisi.',
            'jam_finish_jumat.required_with' => 'Jam finish Jumat wajib diisi jika jam start diisi.',
            'jam_finish_sabtu.required_with' => 'Jam finish Sabtu wajib diisi jika jam start diisi.',
        ];
    }
}
