<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload batch: tiap elemen rows[] punya bentuk field yang sama persis dengan
 * payload satu baris LHP Quality (StoreQcRequest, form single-row lama).
 */
class StoreQcBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['admin', 'staff_qc']);
    }

    public function rules(): array
    {
        return [
            'rows' => 'required|array|min:1|max:50',
            'rows.*.tgl_pengecekan' => 'required|date|after_or_equal:2020-01-01|before_or_equal:tomorrow',
            'rows.*.tgl_produksi_part' => 'required|date|after_or_equal:2020-01-01|before_or_equal:tomorrow',
            'rows.*.id_shift' => 'required|exists:master_shift,id',
            'rows.*.id_karyawan_qc' => 'required|exists:master_karyawan,id',
            'rows.*.id_part_proses' => 'required|exists:master_part_proses,id',
            'rows.*.jam_start' => 'required|date_format:H:i',
            'rows.*.jam_finish' => 'required|date_format:H:i',
            'rows.*.qty_ok' => 'required|integer|min:0',
            'rows.*.ng_count' => 'required|integer|min:0',
            'rows.*.ng' => 'nullable|array',
            /* Tanpa `distinct`, sama seperti StoreQcRequest — inspector boleh
             * mencatat jenis NG yang sama dua kali dalam satu sesi. uniq_qc_ng +
             * normalize() menjadikannya satu baris dengan qty dijumlah (lihat
             * PR #123). */
            'rows.*.ng.*.id_jenis_ng' => 'required|integer|exists:master_jenis_ng,id',
            'rows.*.ng.*.qty' => 'required|integer|min:1',
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

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $rows = $this->input('rows');
            if (! is_array($rows)) {
                return;
            }

            foreach ($rows as $i => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $this->periksaTotalQty($validator, (int) $i, $row);
                $this->periksaHitunganNg($validator, (int) $i, $row);
            }
        });
    }

    /**
     * Total qty check (OK + NG) harus > 0. qty_ok saja boleh 0 — baris yang
     * seluruhnya ditolak QC itu kejadian normal, bukan salah ketik.
     */
    private function periksaTotalQty($validator, int $i, array $row): void
    {
        $qtyOk = (int) ($row['qty_ok'] ?? 0);
        $qtyNg = collect($row['ng'] ?? [])->sum(fn ($r) => (int) ($r['qty'] ?? 0));

        if ($qtyOk + $qtyNg === 0) {
            $validator->errors()->add(
                "rows.{$i}.qty_ok",
                'Baris '.($i + 1).': total qty check (OK + NG) harus lebih dari 0.'
            );
        }
    }

    /**
     * ng_count membuktikan Alpine hidup, bukan bahwa baris ng[] benar-benar
     * sampai. Payload ng_count=2 dengan ng[] hilang (body terpotong, request
     * gagal sebagian) lolos validasi rules() di atas lalu syncQc([]) menghapus
     * semua detail NG dan menulis qty_ng = 0 tanpa error. Cocokkan keduanya.
     */
    private function periksaHitunganNg($validator, int $i, array $row): void
    {
        if (! array_key_exists('ng_count', $row)) {
            return;
        }

        $ngCount = (int) ($row['ng_count'] ?? 0);
        $submitted = is_array($row['ng'] ?? null) ? count($row['ng']) : 0;

        if ($ngCount !== $submitted) {
            $validator->errors()->add(
                "rows.{$i}.ng_count",
                'Baris '.($i + 1).": jumlah baris NG yang tercatat ({$ngCount}) tidak sama dengan yang terkirim ({$submitted}). "
                .'Coba simpan ulang.'
            );
        }
    }

    public function messages(): array
    {
        return [
            'rows.required' => 'Minimal harus ada 1 baris untuk disimpan.',
            'rows.min' => 'Minimal harus ada 1 baris untuk disimpan.',
            'rows.max' => 'Maksimal 50 baris per submit.',

            'rows.*.tgl_pengecekan.required' => 'Baris :position: Tanggal Pengecekan wajib diisi.',
            'rows.*.tgl_pengecekan.date' => 'Baris :position: Tanggal Pengecekan tidak valid.',
            'rows.*.tgl_pengecekan.after_or_equal' => 'Baris :position: Tanggal Pengecekan tidak boleh sebelum 2020.',
            'rows.*.tgl_pengecekan.before_or_equal' => 'Baris :position: Tanggal Pengecekan tidak boleh lebih dari besok.',

            'rows.*.tgl_produksi_part.required' => 'Baris :position: Tanggal Produksi Part wajib diisi.',
            'rows.*.tgl_produksi_part.date' => 'Baris :position: Tanggal Produksi Part tidak valid.',
            'rows.*.tgl_produksi_part.after_or_equal' => 'Baris :position: Tanggal Produksi Part tidak boleh sebelum 2020.',
            'rows.*.tgl_produksi_part.before_or_equal' => 'Baris :position: Tanggal Produksi Part tidak boleh lebih dari besok.',

            'rows.*.id_shift.required' => 'Baris :position: Shift wajib dipilih.',
            'rows.*.id_shift.exists' => 'Baris :position: Shift yang dipilih tidak valid.',

            'rows.*.id_karyawan_qc.required' => 'Baris :position: Inspector wajib dipilih.',
            'rows.*.id_karyawan_qc.exists' => 'Baris :position: Inspector yang dipilih tidak valid.',

            'rows.*.id_part_proses.required' => 'Baris :position: Part dan Proses wajib dipilih.',
            'rows.*.id_part_proses.exists' => 'Baris :position: Part/Proses yang dipilih tidak valid.',

            'rows.*.jam_start.required' => 'Baris :position: Jam Start wajib diisi.',
            'rows.*.jam_start.date_format' => 'Baris :position: Jam Start harus berformat JJ:MM.',

            'rows.*.jam_finish.required' => 'Baris :position: Jam Finish wajib diisi.',
            'rows.*.jam_finish.date_format' => 'Baris :position: Jam Finish harus berformat JJ:MM.',

            'rows.*.qty_ok.required' => 'Baris :position: Qty OK wajib diisi.',
            'rows.*.qty_ok.integer' => 'Baris :position: Qty OK harus berupa angka.',

            'rows.*.ng.*.id_jenis_ng.required' => 'Baris :position: pilih jenis NG untuk setiap baris.',
            'rows.*.ng.*.id_jenis_ng.exists' => 'Baris :position: jenis NG yang dipilih tidak valid.',
            'rows.*.ng.*.qty.min' => 'Baris :position: Qty NG minimal 1.',
        ];
    }

    /**
     * Jaring pengaman untuk rule yang tidak ditulis manual di messages() di
     * atas — kalau default Laravel tetap dipakai, setidaknya nama field-nya
     * bahasa Indonesia, bukan path bertitik seperti "rows.0.ng.0.qty".
     */
    public function attributes(): array
    {
        return [
            'rows.*.tgl_pengecekan' => 'Tanggal Pengecekan',
            'rows.*.tgl_produksi_part' => 'Tanggal Produksi Part',
            'rows.*.id_shift' => 'Shift',
            'rows.*.id_karyawan_qc' => 'Inspector',
            'rows.*.id_part_proses' => 'Proses',
            'rows.*.jam_start' => 'Jam Start',
            'rows.*.jam_finish' => 'Jam Finish',
            'rows.*.qty_ok' => 'Qty OK',
            'rows.*.ng.*.id_jenis_ng' => 'Jenis NG',
            'rows.*.ng.*.qty' => 'Qty NG',
        ];
    }
}
