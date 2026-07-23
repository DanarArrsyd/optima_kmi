<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreMasterUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        $id = $this->route('user')?->id;

        return [
            'name' => 'required|string|max:100',
            'email' => [
                'required', 'email',
                Rule::unique('users', 'email')->ignore($id),
            ],
            'password' => $id
                ? ['nullable', 'string', Password::min(8)]
                : ['required', 'string', Password::min(8)],
            'role' => 'required|in:admin,staff_produksi,staff_qc,manager,staff_maintenance',
            'id_karyawan' => 'nullable|exists:master_karyawan,id',
        ];
    }
}
