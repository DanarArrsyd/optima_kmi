<?php

namespace App\Http\Requests;

use App\Models\TransaksiProduksi;
use App\Services\NgDetailService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProduksiRequest extends FormRequest
{
    /** Named bag — supaya error edit tidak bercampur dengan error create form di halaman index */
    protected $errorBag = 'updateProduksi';

    public function authorize(): bool
    {
        return $this->user()->hasRole(['admin']);
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
        ];
    }

    protected function prepareForValidation(): void
    {
        /* Kalau ada_ng tidak dicentang, buang seluruh baris NG. */
        if (! $this->boolean('ada_ng')) {
            $this->merge(['ng' => []]);
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            /*
             * ng_count membuktikan Alpine hidup, bukan bahwa baris ng[] benar-benar
             * sampai. Payload ng_count=2 dengan ng[] hilang lolos aturan di atas lalu
             * sync([]) menghapus detail NG dan menulis qty_ng = 0 tanpa error —
             * termasuk pada baris yang sudah punya detail (guard legacy di bawah
             * tidak menjangkau kasus ini karena hanya berlaku saat ngDetails()
             * masih kosong).
             *
             * Hanya dicocokkan saat ada_ng dicentang: partial ng-rows selalu
             * dirender (disembunyikan lewat x-show, bukan dihapus dari DOM), jadi
             * ng_count bisa membawa sisa hitungan baris lama dari sebelum checkbox
             * dilepas. Saat ada_ng dilepas, prepareForValidation() sengaja
             * mengosongkan ng — itu perilaku aplikasi yang disengaja (user bilang
             * "tidak ada NG"), bukan indikasi request terpotong, jadi tidak boleh
             * ditolak oleh pencocokan ini.
             */
            if ($this->boolean('ada_ng')) {
                $ngCount = (int) $this->input('ng_count', 0);
                $submitted = is_array($this->input('ng')) ? count($this->input('ng')) : 0;

                if ($ngCount !== $submitted) {
                    $validator->errors()->add(
                        'ng_count',
                        "Jumlah baris NG yang tercatat ({$ngCount}) tidak sama dengan yang terkirim ({$submitted}). "
                        .'Coba simpan ulang.'
                    );
                }
            }

            $produksi = $this->route('produksi');

            if (! $produksi instanceof TransaksiProduksi) {
                return;
            }

            /* Tabel detail belum ada di jendela antara rsync dan migrate. */
            if (! NgDetailService::available()) {
                return;
            }

            /*
             * Baris gaya lama: qty_ng terisi tapi nol baris detail — data yang
             * di-skip migrasi karena id_jenis_ng NULL. Menyimpannya dengan ng
             * kosong akan menulis qty_ng = 0 dan menghapus angka NG yang pernah
             * tercatat, tanpa error. Paksa klasifikasi dulu.
             */
            $legacy = (int) $produksi->qty_ng > 0 && $produksi->ngDetails()->count() === 0;

            if ($legacy && empty($this->input('ng'))) {
                $validator->errors()->add(
                    'ng',
                    'Baris ini punya '.(int) $produksi->qty_ng.' NG dari data lama yang belum diklasifikasi. '
                    .'Pilih jenis NG-nya sebelum menyimpan.'
                );
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
