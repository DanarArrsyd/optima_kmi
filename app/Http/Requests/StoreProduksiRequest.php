<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProduksiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['admin', 'staff_produksi']);
    }

    public function rules(): array
    {
        return [
            'tanggal_produksi' => 'required|date|after_or_equal:2020-01-01|before_or_equal:tomorrow',
            'id_shift' => 'required|exists:master_shift,id',
            'id_karyawan' => 'required|exists:master_karyawan,id',
            'id_mesin' => 'required|exists:master_mesin,id',
            'id_part_proses' => 'required|exists:master_part_proses,id',
            'jam_start' => 'required|date_format:H:i',
            'jam_finish' => 'required|date_format:H:i',
            'qty_aktual' => 'required|integer|min:1',
            'ada_ng' => 'nullable|boolean',
            'ng_count' => 'required|integer|min:0',
            'ng' => 'array|required_if:ada_ng,1',
            'ng.*.id_jenis_ng' => 'required|integer|exists:master_jenis_ng,id|distinct',
            'ng.*.qty' => 'required|integer|min:1',
            'ada_dandori' => 'nullable|boolean',
            'dandori_count' => 'nullable|integer|min:0',
            'dandori' => 'array|required_if:ada_dandori,1',
            'dandori.*.id_losstime' => 'required|integer|exists:master_losstime,id|distinct',
            'dandori.*.actual_menit' => 'required|numeric|min:0.01',
            'dandori_karyawan_id' => 'nullable|integer|exists:master_karyawan,id',
        ];
    }

    protected function prepareForValidation(): void
    {
        /* Kalau ada_ng tidak dicentang, buang seluruh baris NG. */
        if (! $this->boolean('ada_ng')) {
            $this->merge(['ng' => []]);
        }

        /* Kalau ada_dandori tidak dicentang, buang seluruh baris dandori. */
        if (! $this->boolean('ada_dandori')) {
            $this->merge(['dandori' => []]);
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            /*
             * ng_count membuktikan Alpine hidup, bukan bahwa baris ng[] benar-benar
             * sampai. Payload ng_count=2 dengan ng[] hilang lolos aturan di atas lalu
             * sync([]) menghapus detail NG dan menulis qty_ng = 0 tanpa error.
             *
             * Dicocokkan tanpa syarat ada_ng: Alpine's ngRows() di partial ng-rows
             * sekarang mengosongkan rows saat checkbox dilepas (lihat @change di
             * create/edit/index.blade.php), jadi un-tick genuine selalu mengirim
             * ng_count=0 yang konsisten dengan ng=[] hasil prepareForValidation().
             * Request dengan ada_ng absen + ng absen tapi ng_count stale bukan lagi
             * un-tick yang sah — itu payload rusak/terpotong dan harus ditolak.
             */
            $ngCount = (int) $this->input('ng_count', 0);
            $submitted = is_array($this->input('ng')) ? count($this->input('ng')) : 0;

            if ($ngCount !== $submitted) {
                $validator->errors()->add(
                    'ng_count',
                    "Jumlah baris NG yang tercatat ({$ngCount}) tidak sama dengan yang terkirim ({$submitted}). "
                    .'Coba simpan ulang.'
                );
            }

            /*
             * Sama seperti ng_count: hitungan ini membuktikan Alpine hidup,
             * bukan bahwa baris dandori[] benar-benar sampai. Payload dengan
             * dandori_count=2 tapi dandori[] hilang akan lolos aturan di atas
             * dan menyimpan NOL baris dandori tanpa satu pun error — operator
             * mengira sudah tercatat.
             *
             * Dilewati kalau dandori_count tidak dikirim sama sekali: request
             * seperti itu memang tidak menyebut dandori.
             */
            if ($this->has('dandori_count')) {
                $dandoriCount = (int) $this->input('dandori_count', 0);
                $dandoriTerkirim = is_array($this->input('dandori')) ? count($this->input('dandori')) : 0;

                if ($dandoriCount !== $dandoriTerkirim) {
                    $validator->errors()->add(
                        'dandori_count',
                        "Jumlah baris Dandori yang tercatat ({$dandoriCount}) tidak sama dengan yang terkirim ({$dandoriTerkirim}). "
                        .'Coba simpan ulang.'
                    );
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'ng.required_if' => 'Centang "ada NG" berarti minimal satu jenis NG harus diisi.',
            'ng.*.id_jenis_ng.distinct' => 'Jenis NG yang sama tidak boleh diisi dua kali dalam satu sesi.',
            'ng.*.id_jenis_ng.required' => 'Pilih jenis NG untuk setiap baris.',
            'ng.*.qty.min' => 'Qty NG minimal 1.',
        ];
    }
}
