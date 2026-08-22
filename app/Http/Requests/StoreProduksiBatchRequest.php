<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload batch: rows[] berisi baris yang bentuknya sama persis dengan payload
 * satu baris pada StoreProduksiRequest.
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
}
