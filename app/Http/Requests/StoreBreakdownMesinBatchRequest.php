<?php

namespace App\Http\Requests;

use App\Models\TransaksiBreakdownMesin;
use App\Services\BreakdownMesinService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload batch: tiap elemen rows[] punya bentuk field yang sama persis
 * dengan payload satu baris Breakdown Mesin (StoreBreakdownMesinRequest,
 * masih hidup untuk route `maintenance.breakdown.store` sampai dipensiunkan
 * di task terpisah).
 */
class StoreBreakdownMesinBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['admin', 'staff_maintenance', 'manager'], true);
    }

    public function rules(): array
    {
        return [
            'rows' => 'required|array|min:1|max:50',
            'rows.*.nomor_urut' => 'required|integer|min:1',
            'rows.*.id_mesin' => 'required|exists:master_mesin,id',
            // Batas atas `tomorrow`, bukan `today`: app timezone UTC, pabrik WIB (UTC+7).
            'rows.*.tanggal_breakdown' => 'required|date|after_or_equal:2020-01-01|before_or_equal:tomorrow',
            'rows.*.jam_breakdown' => 'required',
            'rows.*.tanggal_mulai_perbaikan' => 'required|date|after_or_equal:2020-01-01|before_or_equal:tomorrow|after_or_equal:rows.*.tanggal_breakdown',
            'rows.*.jam_mulai_perbaikan' => 'required',
            'rows.*.tanggal_selesai_perbaikan' => 'required|date|after_or_equal:2020-01-01|before_or_equal:tomorrow|after_or_equal:rows.*.tanggal_mulai_perbaikan',
            'rows.*.jam_selesai_perbaikan' => 'required',
            'rows.*.problem_mesin' => 'required|string',
            'rows.*.penyebab_breakdown' => 'required|string',
            'rows.*.tindakan_perbaikan' => 'required|string',
            'rows.*.pic_maintenance' => 'required|string|max:255',
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

    /**
     * Satu pemeriksa tabrakan No LKM yang menutup KEDUA arah dalam satu
     * lintasan: kembar antar baris dalam draft yang sama, dan bentrok dengan
     * data yang sudah ada di DB (termasuk baris yang sudah soft-deleted —
     * withTrashed() supaya No LKM lama tidak bisa dipakai ulang dan riwayat
     * jadi ambigu).
     *
     * Kunci peta adalah No LKM yang SUDAH DIRAKIT (nomor + prefix + bulan
     * romawi + tahun), bukan nomor_urut telanjang: nomor 5 di bulan VIII dan
     * nomor 5 di bulan IX adalah dua No LKM sah yang berbeda.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $rows = $this->input('rows');
            if (! is_array($rows)) {
                return;
            }

            $svc = app(BreakdownMesinService::class);
            $dilihat = []; // No LKM => indeks baris yang pertama memakainya

            foreach ($rows as $i => $row) {
                if (! is_array($row)) {
                    continue;
                }

                // Baris yang field-nya belum lolos aturan dasar tidak bisa
                // dirakit jadi No LKM; melewatinya mencegah galat kedua yang
                // membingungkan di baris yang sudah punya galat sendiri.
                if (! isset($row['nomor_urut'], $row['tanggal_breakdown'])) {
                    continue;
                }

                // isset() di atas cuma membuktikan field ADA, bukan VALID.
                // Rule 'date' berjalan lebih dulu tapi callback after() ini
                // tetap dipanggil apapun hasilnya — Carbon::parse() pada
                // tanggal yang sudah gagal rule 'date' (mis. "bukan-tanggal")
                // melempar exception dan menjatuhkan seluruh request jadi
                // HTTP 500, bukan galat validasi yang bisa dibaca operator.
                // Guard ini BUKAN redundan dengan isset() di atas — jangan
                // dihapus sebagai "pemeriksaan ganda".
                if ($validator->errors()->has("rows.{$i}.tanggal_breakdown")) {
                    continue;
                }

                $noLkm = $svc->generateNoLkm((int) $row['nomor_urut'], (string) $row['tanggal_breakdown']);

                if (isset($dilihat[$noLkm])) {
                    // Tabrakan ANTAR BARIS. Sebut nomor baris lawannya —
                    // operator sedang menatap sampai 50 baris dan harus tahu
                    // yang mana.
                    $validator->errors()->add(
                        "rows.{$i}.nomor_urut",
                        'Baris '.($i + 1).": No LKM {$noLkm} sama dengan baris ".($dilihat[$noLkm] + 1).'.'
                    );

                    continue;
                }

                $dilihat[$noLkm] = $i;

                if (TransaksiBreakdownMesin::withTrashed()->where('no_lkm', $noLkm)->exists()) {
                    $validator->errors()->add(
                        "rows.{$i}.nomor_urut",
                        'Baris '.($i + 1).": No LKM {$noLkm} sudah ada, gunakan nomor lain."
                    );
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'rows.required' => 'Minimal harus ada 1 baris untuk disimpan.',
            'rows.min' => 'Minimal harus ada 1 baris untuk disimpan.',
            'rows.max' => 'Maksimal 50 baris per submit.',

            'rows.*.nomor_urut.required' => 'Baris :position: Nomor Urut wajib diisi.',
            'rows.*.nomor_urut.integer' => 'Baris :position: Nomor Urut harus berupa angka.',
            'rows.*.nomor_urut.min' => 'Baris :position: Nomor Urut minimal 1.',

            'rows.*.id_mesin.required' => 'Baris :position: Mesin wajib dipilih.',
            'rows.*.id_mesin.exists' => 'Baris :position: Mesin yang dipilih tidak valid.',

            'rows.*.tanggal_breakdown.required' => 'Baris :position: Tanggal Breakdown wajib diisi.',
            'rows.*.tanggal_breakdown.date' => 'Baris :position: Tanggal Breakdown tidak valid.',
            'rows.*.tanggal_breakdown.after_or_equal' => 'Baris :position: Tanggal Breakdown tidak masuk akal — periksa tahunnya (contoh salah ketik: 0026 untuk 2026).',
            'rows.*.tanggal_breakdown.before_or_equal' => 'Baris :position: Tanggal Breakdown tidak boleh di masa depan.',

            'rows.*.jam_breakdown.required' => 'Baris :position: Jam Breakdown wajib diisi.',

            'rows.*.tanggal_mulai_perbaikan.required' => 'Baris :position: Tanggal Mulai Perbaikan wajib diisi.',
            'rows.*.tanggal_mulai_perbaikan.date' => 'Baris :position: Tanggal Mulai Perbaikan tidak valid.',
            'rows.*.tanggal_mulai_perbaikan.after_or_equal' => 'Baris :position: Tanggal Mulai Perbaikan tidak masuk akal atau lebih awal dari Tanggal Breakdown.',
            'rows.*.tanggal_mulai_perbaikan.before_or_equal' => 'Baris :position: Tanggal Mulai Perbaikan tidak boleh di masa depan.',

            'rows.*.jam_mulai_perbaikan.required' => 'Baris :position: Jam Mulai Perbaikan wajib diisi.',

            'rows.*.tanggal_selesai_perbaikan.required' => 'Baris :position: Tanggal Selesai Perbaikan wajib diisi.',
            'rows.*.tanggal_selesai_perbaikan.date' => 'Baris :position: Tanggal Selesai Perbaikan tidak valid.',
            'rows.*.tanggal_selesai_perbaikan.after_or_equal' => 'Baris :position: Tanggal Selesai Perbaikan tidak masuk akal atau lebih awal dari Tanggal Mulai Perbaikan.',
            'rows.*.tanggal_selesai_perbaikan.before_or_equal' => 'Baris :position: Tanggal Selesai Perbaikan tidak boleh di masa depan.',

            'rows.*.jam_selesai_perbaikan.required' => 'Baris :position: Jam Selesai Perbaikan wajib diisi.',

            'rows.*.problem_mesin.required' => 'Baris :position: Problem wajib diisi.',
            'rows.*.penyebab_breakdown.required' => 'Baris :position: Penyebab wajib diisi.',
            'rows.*.tindakan_perbaikan.required' => 'Baris :position: Tindakan wajib diisi.',

            'rows.*.pic_maintenance.required' => 'Baris :position: PIC wajib diisi.',
            'rows.*.pic_maintenance.max' => 'Baris :position: PIC maksimal 255 karakter.',
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
            'rows.*.nomor_urut' => 'Nomor Urut',
            'rows.*.id_mesin' => 'Mesin',
            'rows.*.tanggal_breakdown' => 'Tanggal Breakdown',
            'rows.*.jam_breakdown' => 'Jam Breakdown',
            'rows.*.tanggal_mulai_perbaikan' => 'Tanggal Mulai Perbaikan',
            'rows.*.jam_mulai_perbaikan' => 'Jam Mulai Perbaikan',
            'rows.*.tanggal_selesai_perbaikan' => 'Tanggal Selesai Perbaikan',
            'rows.*.jam_selesai_perbaikan' => 'Jam Selesai Perbaikan',
            'rows.*.problem_mesin' => 'Problem',
            'rows.*.penyebab_breakdown' => 'Penyebab',
            'rows.*.tindakan_perbaikan' => 'Tindakan',
            'rows.*.pic_maintenance' => 'PIC',
        ];
    }
}
