<?php

namespace App\Imports;

use App\Imports\Concerns\LewatiBarisContoh;
use App\Imports\Concerns\ParsesExcelDateTime;
use App\Models\MasterShift;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ShiftImport implements SkipsEmptyRows, ToCollection, WithHeadingRow
{
    use LewatiBarisContoh;
    use ParsesExcelDateTime;

    /** Judul kolom template impor — dibaca juga oleh template & ekspor halaman, supaya
     *  ekspor → sunting di Excel → impor balik selalu cocok. */
    public const KOLOM = ['Nama Shift', 'Jam Start (Senin-Kamis)', 'Jam Finish (Senin-Kamis)'];

    /** Baris contoh template — dibaca juga oleh template-nya. Lihat {@see LewatiBarisContoh}. */
    public const CONTOH = [
        ['Shift Pagi', '07:00', '15:00'],
        ['Shift Siang', '15:00', '22:00'],
        ['Shift Malam', '22:00', '06:00'],
    ];

    public int $imported = 0;

    public int $skipped = 0;

    /** @var list<string> */
    public array $errors = [];

    public function collection(Collection $rows): void
    {
        foreach ($rows as $i => $row) {
            if ($this->barisContoh($row)) {
                continue;
            }

            $nama = trim((string) ($row['nama_shift'] ?? ''));
            if ($nama === '') {
                $this->skipped++;

                continue;
            }

            /* Judul template "Jam Start (Senin-Kamis)" di-slug jadi
             * jam_start_senin_kamis. Dulu yang dibaca $row['jam_start'] dengan
             * default '00:00' — tiap shift lewat template tersimpan 00:00–00:00
             * tanpa galat, dan Availability (penyebutnya membaca master_shift
             * LIVE) rusak retroaktif. Kunci lama tetap diterima untuk berkas
             * lama; sel kosong/tak valid TIDAK PERNAH ditulis jadi 00:00. Sel
             * jam yang diketik di Excel berupa pecahan hari, bukan teks. */
            $mulai = $this->parseTime($row['jam_start_senin_kamis'] ?? $row['jam_start'] ?? null);
            $selesai = $this->parseTime($row['jam_finish_senin_kamis'] ?? $row['jam_finish'] ?? null);
            if ($mulai === null || $selesai === null) {
                $this->skipped++;
                $this->errors[] = 'Baris '.($i + 2).": jam Shift \"{$nama}\" kosong atau bukan format HH:MM — baris dilewati.";

                continue;
            }

            $record = MasterShift::withTrashed()->firstOrNew(['nama_shift' => $nama]);
            $record->jam_start = $mulai;
            $record->jam_finish = $selesai;
            $record->deleted_at = null;
            $record->save();
            $this->imported++;
        }
    }
}
