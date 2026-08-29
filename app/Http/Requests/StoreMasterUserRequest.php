<?php

namespace App\Http\Requests;

use App\Models\User;
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
            'roles' => 'required|array|min:1',
            'roles.*' => ['required', Rule::in(array_keys(User::ROLES))],
            'id_karyawan' => 'nullable|exists:master_karyawan,id',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            /* Guest itu jaminan read-only — DenyGuestWrite mengunci SEMUA
             * tulis begitu isGuest() true. Kalau guest boleh nyambi role
             * kerja, jaminan itu bocor: bukan lewat DenyGuestWrite (dia
             * tetap benar mengunci), tapi lewat setiap pengecekan yang
             * membaca role KERJA-nya dan mengizinkan tulis di jalur lain
             * (mis. hasRole('staff_produksi') di CheckRole route group)
             * tanpa tahu user yang sama juga guest. Dicegah di akarnya:
             * guest tidak boleh digabung sama sekali. */
            $roles = (array) $this->input('roles', []);

            if (in_array('guest', $roles, true) && count($roles) > 1) {
                $validator->errors()->add(
                    'roles',
                    'Guest (Read-Only) tidak boleh digabung dengan role lain — pilih guest sendirian, atau lepas guest dan pilih role kerja.'
                );
            }
        });
    }
}
