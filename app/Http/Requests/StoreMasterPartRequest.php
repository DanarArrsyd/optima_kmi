<?php

namespace App\Http\Requests;

use App\Models\MasterPart;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
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

            // Proses opsional, diisi langsung di halaman Tambah Part alih-alih
            // wajib lompat ke halaman edit dulu. `route('part')` null di create,
            // jadi nol konflik dengan validasi unique id_proses per-part di
            // StoreMasterPartProsesRequest (part-nya belum ada saat ini divalidasi).
            'proses' => 'nullable|array',
            'proses.*.id_proses' => 'required|string|max:10|distinct',
            'proses.*.nama_proses' => 'required|string|max:100',
            'proses.*.sph_standar_produksi' => 'required|numeric|min:0',
            'proses.*.sph_standar_qc' => 'required|numeric|min:0',
            'proses.*.std_trial_qc_menit' => 'required|integer|min:0',
            'proses.*.std_setting_dies_menit' => 'required|integer|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'proses.*.id_proses.distinct' => 'ID Proses tidak boleh sama antar baris.',
        ];
    }

    /**
     * Kalau gagalnya gara-gara part_no+part_name PERSIS sama kayak part yang
     * sudah ada (bukan konflik LH/RH), user hampir pasti bukan mau bikin part
     * baru — dia mau menambah proses ke part yang sudah ada itu, tapi form
     * Tambah Part tidak punya jalan ke sana. Dulu berhenti di error "sudah
     * dipakai" tanpa arah; sekarang langsung diantar ke halaman edit part
     * tersebut. Hanya berlaku saat create (route('part') kosong) — saat edit,
     * konflik part_no.unique berarti bentrok dengan part LAIN yang sungguhan,
     * tetap harus jadi error biasa.
     */
    protected function failedValidation(Validator $validator)
    {
        if (! $this->route('part') && $validator->errors()->has('part_no')) {
            $existing = MasterPart::where('part_no', $this->input('part_no'))
                ->where('part_name', $this->input('part_name'))
                ->whereNull('deleted_at')
                ->first();

            if ($existing) {
                throw new HttpResponseException(
                    redirect()->route('master.part.edit', $existing)->with(
                        'success',
                        "Part \"{$existing->part_no}\" — \"{$existing->part_name}\" sudah ada. Tambahkan proses barunya di sini."
                    )
                );
            }
        }

        parent::failedValidation($validator);
    }
}
