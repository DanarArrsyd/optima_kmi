<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreQcRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['admin', 'staff_qc']);
    }

    public function rules(): array
    {
        return [
            'tgl_pengecekan' => 'required|date|after_or_equal:2020-01-01|before_or_equal:tomorrow',
            'tgl_produksi_part' => 'required|date|after_or_equal:2020-01-01|before_or_equal:tomorrow',
            'id_shift' => 'required|exists:master_shift,id',
            'id_karyawan_qc' => 'required|exists:master_karyawan,id',
            'id_part_proses' => 'required|exists:master_part_proses,id',
            'jam_start' => 'required|date_format:H:i',
            'jam_finish' => 'required|date_format:H:i',
            'qty_ok' => 'required|integer|min:0',
            'ng_count' => 'required|integer|min:0',
            'ng' => 'array',
            /* Tanpa `distinct`, sama seperti jalur produksi: inspector boleh
             * mencatat jenis NG yang sama dua kali dalam satu sesi, mis. ketemu
             * penyok di dua waktu berbeda selama pengecekan itu.
             *
             * Tersimpan sebagai SATU baris dengan qty dijumlah: transaksi_qc_ng
             * punya indeks unik uniq_qc_ng (id_transaksi_qc, id_jenis_ng), jadi
             * dua baris jenis sama tidak bisa berdiri sendiri di sana, dan
             * NgDetailService::normalize() sudah menjumlahkannya sejak dulu.
             * Yang dicabut cuma blokirnya, bukan bentuk penyimpanannya. */
            'ng.*.id_jenis_ng' => 'required|integer|exists:master_jenis_ng,id',
            'ng.*.qty' => 'required|integer|min:1',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $qtyOk = (int) $this->input('qty_ok', 0);
            $qtyNg = collect($this->input('ng', []))->sum(fn ($r) => (int) ($r['qty'] ?? 0));

            if ($qtyOk + $qtyNg === 0) {
                $validator->errors()->add('qty_ok', 'Total qty check (OK + NG) harus lebih dari 0.');
            }

            /*
             * ng_count membuktikan Alpine hidup, bukan bahwa baris ng[] benar-benar
             * sampai. Payload ng_count=2 dengan ng[] hilang (body terpotong, request
             * gagal sebagian) lolos validasi di atas lalu sync([]) menghapus semua
             * detail NG dan menulis qty_ng = 0 tanpa error. Cocokkan keduanya.
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
        });
    }

    public function messages(): array
    {
        return [
            'ng.*.id_jenis_ng.required' => 'Pilih jenis NG untuk setiap baris.',
            'ng.*.qty.min' => 'Qty NG minimal 1.',
        ];
    }
}
