<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBreakdownDiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    public function rules(): array
    {
        return [
            'nomor_urut'                 => ['required', 'integer', 'min:1'],
            'id_part_proses'              => ['required', 'exists:master_part_proses,id'],
            'tanggal_breakdown'          => ['required', 'date'],
            'jam_breakdown'              => ['required'],
            'tanggal_mulai_perbaikan'    => ['required', 'date', 'after_or_equal:tanggal_breakdown'],
            'jam_mulai_perbaikan'        => ['required'],
            'tanggal_selesai_perbaikan'  => ['required', 'date', 'after_or_equal:tanggal_mulai_perbaikan'],
            'jam_selesai_perbaikan'      => ['required'],
            'problem_dies'               => ['required', 'string'],
            'penyebab_breakdown'         => ['required', 'string'],
            'tindakan_perbaikan'         => ['required', 'string'],
            'pic_maintenance'            => ['required', 'string', 'max:255'],
        ];
    }
}
