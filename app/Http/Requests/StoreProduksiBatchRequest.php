<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload batch: tiap elemen rows[] punya bentuk field yang sama persis dengan
 * payload satu baris LHP Produksi versi lama (form single-row yang sudah dicabut).
 */
class StoreProduksiBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rows' => 'required|array|min:1|max:50',
            'rows.*.tanggal_produksi' => 'required|date|after_or_equal:2020-01-01|before_or_equal:tomorrow',
            'rows.*.id_shift' => 'required|exists:master_shift,id',
            'rows.*.id_karyawan' => 'required|exists:master_karyawan,id',
            'rows.*.id_mesin' => 'required|exists:master_mesin,id',
            'rows.*.id_part_proses' => 'required|exists:master_part_proses,id',
            'rows.*.jam_start' => 'required|date_format:H:i',
            'rows.*.jam_finish' => 'required|date_format:H:i',
            'rows.*.qty_aktual' => 'required|integer|min:1',
            'rows.*.ada_ng' => 'nullable|boolean',
            'rows.*.ng_count' => 'required|integer|min:0',
            'rows.*.ng' => 'nullable|array',
            'rows.*.ng.*.id_jenis_ng' => 'required|integer|exists:master_jenis_ng,id|distinct',
            'rows.*.ng.*.qty' => 'required|integer|min:1',
            'rows.*.ada_dandori' => 'nullable|boolean',
            'rows.*.dandori_count' => 'nullable|integer|min:0',
            'rows.*.dandori' => 'nullable|array',
            'rows.*.dandori.*.id_losstime' => 'required|integer|exists:master_losstime,id|distinct',
            'rows.*.dandori.*.actual_menit' => 'required|numeric|min:0.01',
            'rows.*.dandori_karyawan_id' => 'nullable|integer|exists:master_karyawan,id',
        ];
    }

    /**
     * Kalau ada_ng / ada_dandori tidak dicentang, buang rincian yang
     * terlanjur terkirim, supaya validated()['rows'] tidak pernah membawa baris rincian yatim ke
     * ProduksiWriteService. ng_count/dandori_count ikut dinolkan supaya
     * periksaHitungan() tidak berteriak atas rincian yang baru saja
     * dikosongkan di sini.
     */
    protected function prepareForValidation(): void
    {
        /* 50 baris (batas rows.max di rules()) × field flat (13 pokok +
         * hingga 4 NG + 4 dandori) bisa melewati max_input_vars bawaan PHP
         * (1000) — dikonfirmasi 1000 di mesin ini, dan hosting produksi
         * kemungkinan sama. PHP memotong $_POST diam-diam kalau itu
         * terjadi, cuma menulis warning ke log. create.blade.php sekarang
         * mengirim rows sebagai satu field JSON (rows_json) supaya jumlah
         * baris tidak lagi terikat batas variabel input. Kondisional: kalau
         * 'rows' sudah terkirim langsung (payload lama, dipakai test yang
         * ada), biarkan seperti semula tanpa disentuh.
         */
        if ($this->has('rows_json') && ! $this->has('rows')) {
            $decoded = json_decode((string) $this->input('rows_json'), true);
            // JSON rusak → gabungkan array kosong supaya aturan 'rows' yang
            // sudah ada (required|array|min:1) menolaknya dengan pesan
            // normal, bukan meledak di sini.
            $this->merge(['rows' => is_array($decoded) ? $decoded : []]);
        }

        $rows = $this->input('rows');
        if (! is_array($rows)) {
            return;
        }

        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }

            if (! filter_var($row['ada_ng'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $rows[$i]['ng'] = [];
                $rows[$i]['ng_count'] = 0;
            }

            if (! filter_var($row['ada_dandori'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $rows[$i]['dandori'] = [];
                $rows[$i]['dandori_count'] = 0;
            }
        }

        $this->merge(['rows' => $rows]);
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
                $this->periksaHitungan($validator, (int) $i, $row, 'ng');
                $this->periksaHitungan($validator, (int) $i, $row, 'dandori');
                $this->periksaCentang($validator, (int) $i, $row, 'ng', 'ada_ng');
                $this->periksaCentang($validator, (int) $i, $row, 'dandori', 'ada_dandori');
                $this->periksaDurasiNol($validator, (int) $i, $row);
            }

            $bentrok = $this->cariBentrok($rows);
            if ($bentrok !== null) {
                [$a, $b] = $bentrok;
                $validator->errors()->add(
                    "rows.{$b}.jam_start",
                    'Jam baris '.($b + 1).' tumpang tindih dengan baris '.($a + 1)
                    .' pada mesin dan tanggal yang sama.'
                );
            }
        });
    }

    /**
     * Hitungan yang dikirim membuktikan Alpine hidup, bukan bahwa baris
     * rinciannya benar-benar sampai. Payload dengan ng_count=2 tapi ng[]
     * hilang akan lolos aturan di atas lalu menyimpan NOL baris rincian tanpa
     * satu pun error — operator mengira sudah tercatat.
     */
    private function periksaHitungan($validator, int $i, array $row, string $kunci): void
    {
        $kunciHitungan = $kunci.'_count';

        if (! array_key_exists($kunciHitungan, $row)) {
            return;
        }

        $diminta = (int) ($row[$kunciHitungan] ?? 0);
        $terkirim = is_array($row[$kunci] ?? null) ? count($row[$kunci]) : 0;

        if ($diminta !== $terkirim) {
            $validator->errors()->add(
                "rows.{$i}.{$kunciHitungan}",
                'Baris '.($i + 1).": jumlah rincian yang tercatat ({$diminta}) tidak sama dengan yang terkirim ({$terkirim}). "
                .'Coba simpan ulang.'
            );
        }
    }

    /**
     * Centang menyala tapi rinciannya kosong berarti operator mengira sesuatu
     * tercatat padahal tidak. Form satu baris menolaknya lewat required_if;
     * versi batch menolaknya di sini supaya perilakunya sama.
     */
    private function periksaCentang($validator, int $i, array $row, string $kunci, string $kunciCentang): void
    {
        $menyala = filter_var($row[$kunciCentang] ?? false, FILTER_VALIDATE_BOOLEAN);
        $terkirim = is_array($row[$kunci] ?? null) ? count($row[$kunci]) : 0;

        if ($menyala && $terkirim === 0) {
            $validator->errors()->add(
                "rows.{$i}.{$kunci}",
                'Baris '.($i + 1).': centangnya menyala tapi tidak ada satu pun rincian terkirim.'
            );
        }
    }

    /**
     * jam_start sama dengan jam_finish itu ambigu: cariBentrok() membaca
     * selesai<=mulai sebagai lewat tengah malam lalu meregangkannya jadi 24
     * jam penuh, padahal baris durasi nol tidak berarti apa-apa buat LHP.
     * Ditolak di sini SEBAGAI TAMBAHAN, bukan pengganti, pemeriksaan
     * cariBentrok() — baris durasi nol tetap ikut diperiksa bentrok seperti
     * baris lain, jadi bisa saja menarik dua galat sekaligus ("jam mulai
     * sama dengan selesai" dan "tumpang tindih"). Itu tidak masalah: baris
     * begitu tetap ditolak juga tanpa aturan ini, dan UI di client sudah
     * mencegahnya sampai ke draft lebih dulu (lihat simpanKeDraft() di
     * create.blade.php). Aturan ini murni jaring pengaman di server.
     */
    private function periksaDurasiNol($validator, int $i, array $row): void
    {
        if (empty($row['jam_start']) || empty($row['jam_finish'])) {
            return;
        }

        if ($row['jam_start'] === $row['jam_finish']) {
            $validator->errors()->add(
                "rows.{$i}.jam_finish",
                'Baris '.($i + 1).': jam mulai dan jam selesai tidak boleh sama.'
            );
        }
    }

    /**
     * Mencari sepasang baris pada mesin dan tanggal yang sama yang jamnya
     * beririsan. Jam finish lebih kecil dari start berarti melewati tengah
     * malam — rentangnya dilanjutkan ke hari berikutnya, sama seperti
     * ProduksiService::calculateDurationSeconds() menghitung durasi.
     *
     * Hanya antar baris DALAM satu batch. Data yang sudah tersimpan tidak
     * diperiksa: baris lama yang tumpang tindih sudah ada di prod, dan
     * menolaknya akan mengunci operator dari mengoreksi datanya sendiri.
     *
     * @return array{0:int,1:int}|null indeks pasangan pertama yang bentrok
     */
    private function cariBentrok(array $rows): ?array
    {
        $rentang = [];

        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach (['tanggal_produksi', 'jam_start', 'jam_finish', 'id_mesin'] as $wajib) {
                if (empty($row[$wajib])) {
                    continue 2;
                }
            }

            $mulai = strtotime($row['tanggal_produksi'].' '.$row['jam_start']);
            $selesai = strtotime($row['tanggal_produksi'].' '.$row['jam_finish']);
            if ($mulai === false || $selesai === false) {
                continue;
            }
            if ($selesai <= $mulai) {
                $selesai += 86400;
            }

            $rentang[(int) $i] = ['mesin' => $row['id_mesin'], 'mulai' => $mulai, 'selesai' => $selesai];
        }

        foreach ($rentang as $i => $a) {
            foreach ($rentang as $j => $b) {
                if ($j <= $i || $a['mesin'] != $b['mesin']) {
                    continue;
                }
                if ($a['mulai'] < $b['selesai'] && $b['mulai'] < $a['selesai']) {
                    return [$i, $j];
                }
            }
        }

        return null;
    }

    /**
     * Tanpa ini, penolakan rule-level (bukan pemeriksaan tangan di
     * withValidator()) sampai ke operator dalam bahasa Inggris dengan path
     * bertitik mentah — "The rows.0.qty_aktual field must be at least 1."
     * :position diisi Laravel dari segmen numerik pertama pada nama atribut
     * (rows.N....), jadi hasilnya sama gaya "Baris N: ..." dengan pesan
     * tangan di periksaHitungan()/periksaCentang()/periksaDurasiNol().
     */
    public function messages(): array
    {
        return [
            'rows.required' => 'Minimal harus ada 1 baris untuk disimpan.',
            'rows.min' => 'Minimal harus ada 1 baris untuk disimpan.',
            'rows.max' => 'Maksimal 50 baris per submit.',

            'rows.*.tanggal_produksi.required' => 'Baris :position: Tanggal Produksi wajib diisi.',
            'rows.*.tanggal_produksi.date' => 'Baris :position: Tanggal Produksi tidak valid.',
            'rows.*.tanggal_produksi.after_or_equal' => 'Baris :position: Tanggal Produksi tidak boleh sebelum 2020.',
            'rows.*.tanggal_produksi.before_or_equal' => 'Baris :position: Tanggal Produksi tidak boleh lebih dari besok.',

            'rows.*.id_shift.required' => 'Baris :position: Shift wajib dipilih.',
            'rows.*.id_shift.exists' => 'Baris :position: Shift yang dipilih tidak valid.',

            'rows.*.id_karyawan.required' => 'Baris :position: Operator wajib dipilih.',
            'rows.*.id_karyawan.exists' => 'Baris :position: Operator yang dipilih tidak valid.',

            'rows.*.id_mesin.required' => 'Baris :position: Mesin wajib dipilih.',
            'rows.*.id_mesin.exists' => 'Baris :position: Mesin yang dipilih tidak valid.',

            'rows.*.id_part_proses.required' => 'Baris :position: Part dan Proses wajib dipilih.',
            'rows.*.id_part_proses.exists' => 'Baris :position: Part/Proses yang dipilih tidak valid.',

            'rows.*.jam_start.required' => 'Baris :position: Jam Start wajib diisi.',
            'rows.*.jam_start.date_format' => 'Baris :position: Jam Start harus berformat JJ:MM.',

            'rows.*.jam_finish.required' => 'Baris :position: Jam Finish wajib diisi.',
            'rows.*.jam_finish.date_format' => 'Baris :position: Jam Finish harus berformat JJ:MM.',

            'rows.*.qty_aktual.required' => 'Baris :position: Qty OK wajib diisi.',
            'rows.*.qty_aktual.integer' => 'Baris :position: Qty OK harus berupa angka.',
            'rows.*.qty_aktual.min' => 'Baris :position: Qty OK minimal 1.',

            'rows.*.ng.*.id_jenis_ng.required' => 'Baris :position: pilih jenis NG untuk setiap baris.',
            'rows.*.ng.*.id_jenis_ng.exists' => 'Baris :position: jenis NG yang dipilih tidak valid.',
            'rows.*.ng.*.id_jenis_ng.distinct' => 'Baris :position: jenis NG yang sama tidak boleh diisi dua kali.',

            'rows.*.dandori.*.id_losstime.required' => 'Baris :position: pilih jenis loss time untuk setiap baris dandori.',
            'rows.*.dandori.*.id_losstime.exists' => 'Baris :position: jenis loss time yang dipilih tidak valid.',
            'rows.*.dandori.*.id_losstime.distinct' => 'Baris :position: jenis loss time yang sama tidak boleh diisi dua kali.',
        ];
    }

    /**
     * Jaring pengaman untuk rule yang tidak ditulis manual di messages() di
     * atas (mis. dandori.*.actual_menit, ng.*.qty) — kalau default Laravel
     * tetap dipakai, setidaknya nama field-nya bahasa Indonesia, bukan path
     * bertitik seperti "rows.0.ng.0.qty".
     */
    public function attributes(): array
    {
        return [
            'rows.*.tanggal_produksi' => 'Tanggal Produksi',
            'rows.*.id_shift' => 'Shift',
            'rows.*.id_karyawan' => 'Operator',
            'rows.*.id_mesin' => 'Mesin',
            'rows.*.id_part_proses' => 'Proses',
            'rows.*.jam_start' => 'Jam Start',
            'rows.*.jam_finish' => 'Jam Finish',
            'rows.*.qty_aktual' => 'Qty OK',
            'rows.*.ng.*.id_jenis_ng' => 'Jenis NG',
            'rows.*.ng.*.qty' => 'Qty NG',
            'rows.*.dandori.*.id_losstime' => 'Jenis Loss Time',
            'rows.*.dandori.*.actual_menit' => 'Aktual Dandori (menit)',
            'rows.*.dandori_karyawan_id' => 'Operator Dandori',
        ];
    }
}
