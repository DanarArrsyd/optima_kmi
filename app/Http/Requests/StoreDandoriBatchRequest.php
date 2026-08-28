<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload batch: tiap elemen rows[] adalah satu SESI dandori, dan tiap sesi
 * punya array rincian[] (satu sesi bisa mencatat beberapa loss time). Bentuk
 * field satu sesi sama persis dengan payload single-row lama, yang sudah
 * dipensiunkan bersama laci penulisnya.
 */
class StoreDandoriBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['admin', 'staff_produksi']);
    }

    public function rules(): array
    {
        return [
            'rows' => 'required|array|min:1|max:50',
            // Batas atas `tomorrow`, bukan `today`: app timezone UTC, pabrik WIB (UTC+7).
            'rows.*.tanggal' => 'required|date|after_or_equal:2020-01-01|before_or_equal:tomorrow',
            'rows.*.id_shift' => 'required|exists:master_shift,id',
            'rows.*.id_karyawan' => 'required|exists:master_karyawan,id',
            'rows.*.id_mesin' => 'required|exists:master_mesin,id',
            'rows.*.id_part_proses' => 'required|exists:master_part_proses,id',
            'rows.*.rincian' => 'required|array|min:1',
            /* SENGAJA tanpa `distinct`: satu sesi bisa punya beberapa
             * pergantian dies, dan tiap pergantian memunculkan jenis loss
             * time yang sama lagi — kejadian normal di lantai produksi,
             * bukan salah ketik. Halaman Dandori memang tidak pernah
             * melarangnya (lihat PR #118). */
            'rows.*.rincian.*.id_losstime' => 'required|exists:master_losstime,id',
            'rows.*.rincian.*.actual_menit' => 'required|numeric|min:0.01',
            // std_menit dan over_menit SENGAJA tidak divalidasi di sini: kedua
            // nilai itu tidak pernah datang dari browser, DandoriRincianService
            // yang menghitungnya dari MasterLosstime. Kalau payload memuatnya,
            // ia diabaikan di controller.
        ];
    }

    /**
     * Ubah string kosong jadi null, rekursif.
     *
     * Laravel punya middleware bawaan yang melakukan ini untuk field POST
     * biasa, dan seluruh kode di belakangnya bergantung pada itu. Isi di
     * dalam satu string JSON (rows_json) tidak terlihat oleh middleware itu,
     * jadi '' lolos utuh sampai ke INSERT dan MySQL menolaknya. Fungsi ini
     * mengembalikan perlakuan yang sama untuk payload JSON.
     */
    private function kosongJadiNull(array $data): array
    {
        foreach ($data as $kunci => $nilai) {
            if (is_array($nilai)) {
                $data[$kunci] = $this->kosongJadiNull($nilai);
            } elseif ($nilai === '') {
                $data[$kunci] = null;
            }
        }

        return $data;
    }

    protected function prepareForValidation(): void
    {
        /* 50 baris (batas rows.max di rules()) × field flat bisa melewati
         * max_input_vars bawaan PHP (1000). PHP memotong $_POST diam-diam
         * kalau itu terjadi, cuma menulis warning ke log. Halaman batch
         * mengirim rows sebagai satu field JSON (rows_json) supaya jumlah
         * baris tidak lagi terikat batas variabel input. Kondisional: kalau
         * 'rows' sudah terkirim langsung (dipakai kebanyakan test), biarkan
         * seperti semula tanpa disentuh.
         */
        if ($this->has('rows_json') && ! $this->has('rows')) {
            $decoded = json_decode((string) $this->input('rows_json'), true);
            // JSON rusak → gabungkan array kosong supaya aturan 'rows' yang
            // sudah ada (required|array|min:1) menolaknya dengan pesan
            // normal, bukan meledak di sini.
            $this->merge(['rows' => is_array($decoded) ? $this->kosongJadiNull($decoded) : []]);
        }
    }

    /* Tidak ada withValidator()/after() di sini seperti StoreQcBatchRequest:
     * QC butuh pemeriksa lintas-field per baris (total qty, hitungan NG) yang
     * tidak bisa diekspresikan lewat rules(). Dandori tidak punya aturan
     * bisnis semacam itu — rules() di atas sudah cukup. */

    public function messages(): array
    {
        return [
            'rows.required' => 'Minimal harus ada 1 baris untuk disimpan.',
            'rows.min' => 'Minimal harus ada 1 baris untuk disimpan.',
            'rows.max' => 'Maksimal 50 baris per submit.',

            'rows.*.tanggal.required' => 'Baris :position: Tanggal wajib diisi.',
            'rows.*.tanggal.date' => 'Baris :position: Tanggal tidak valid.',
            'rows.*.tanggal.after_or_equal' => 'Baris :position: Tanggal tidak masuk akal — periksa tahunnya (contoh salah ketik: 0026 untuk 2026).',
            'rows.*.tanggal.before_or_equal' => 'Baris :position: Tanggal tidak boleh di masa depan.',

            'rows.*.id_shift.required' => 'Baris :position: Shift wajib dipilih.',
            'rows.*.id_shift.exists' => 'Baris :position: Shift yang dipilih tidak valid.',

            'rows.*.id_karyawan.required' => 'Baris :position: Operator wajib dipilih.',
            'rows.*.id_karyawan.exists' => 'Baris :position: Operator yang dipilih tidak valid.',

            'rows.*.id_mesin.required' => 'Baris :position: Mesin wajib dipilih.',
            'rows.*.id_mesin.exists' => 'Baris :position: Mesin yang dipilih tidak valid.',

            'rows.*.id_part_proses.required' => 'Baris :position: Part dan Proses wajib dipilih.',
            'rows.*.id_part_proses.exists' => 'Baris :position: Part/Proses yang dipilih tidak valid.',

            'rows.*.rincian.required' => 'Baris :position: Minimal harus ada 1 aktivitas loss time.',
            'rows.*.rincian.min' => 'Baris :position: Minimal harus ada 1 aktivitas loss time.',

            'rows.*.rincian.*.id_losstime.required' => 'Baris :position: Pilih jenis loss time.',
            'rows.*.rincian.*.id_losstime.exists' => 'Baris :position: Jenis loss time yang dipilih tidak valid.',

            'rows.*.rincian.*.actual_menit.required' => 'Baris :position: Masukkan waktu aktual (menit).',
            'rows.*.rincian.*.actual_menit.numeric' => 'Baris :position: Waktu aktual harus berupa angka.',
            'rows.*.rincian.*.actual_menit.min' => 'Baris :position: Waktu aktual harus lebih dari 0.',
        ];
    }

    /**
     * Jaring pengaman untuk rule yang tidak ditulis manual di messages() di
     * atas — kalau default Laravel tetap dipakai, setidaknya nama field-nya
     * bahasa Indonesia, bukan path bertitik seperti "rows.0.id_mesin".
     */
    public function attributes(): array
    {
        return [
            'rows.*.tanggal' => 'Tanggal',
            'rows.*.id_shift' => 'Shift',
            'rows.*.id_karyawan' => 'Operator',
            'rows.*.id_mesin' => 'Mesin',
            'rows.*.id_part_proses' => 'Proses',
            'rows.*.rincian.*.id_losstime' => 'Jenis Loss Time',
            'rows.*.rincian.*.actual_menit' => 'Waktu Aktual',
        ];
    }
}
