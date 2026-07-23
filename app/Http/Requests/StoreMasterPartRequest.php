<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMasterPartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        $id = $this->route('part')?->id;

        return [
            'part_no' => [
                'required', 'string', 'max:50',
                // Duplikat hanya jika part_no DAN part_name sama-sama identik.
                // Satu part_no boleh punya beberapa varian part_name (LH/RH dll).
                // Abaikan baris soft-deleted agar bisa dibuat ulang.
                Rule::unique('master_part', 'part_no')
                    ->where(fn ($q) => $q
                        ->where('part_name', $this->input('part_name'))
                        ->whereNull('deleted_at'))
                    ->ignore($id),
            ],
            'customer' => 'nullable|string|max:100',
            'part_name' => 'required|string|max:200',
            'tanggal_instalasi' => 'nullable|date|after_or_equal:1990-01-01|before_or_equal:tomorrow',
        ];
    }
}
