<?php

namespace App\Imports;

use App\Imports\Concerns\LewatiBarisContoh;
use App\Models\MasterShift;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ShiftImport implements SkipsEmptyRows, ToCollection, WithHeadingRow
{
    use LewatiBarisContoh;

    /** Baris contoh template — dibaca juga oleh template-nya. Lihat {@see LewatiBarisContoh}. */
    public const CONTOH = [
        ['Shift Pagi', '07:00', '15:00'],
        ['Shift Siang', '15:00', '22:00'],
        ['Shift Malam', '22:00', '06:00'],
    ];

    public int $imported = 0;

    public int $skipped = 0;

    public function collection(Collection $rows): void
    {
        foreach ($rows as $row) {
            if ($this->barisContoh($row)) {
                continue;
            }

            $nama = trim((string) ($row['nama_shift'] ?? ''));
            if ($nama === '') {
                $this->skipped++;

                continue;
            }
            $record = MasterShift::withTrashed()->firstOrNew(['nama_shift' => $nama]);
            $record->jam_start = trim((string) ($row['jam_start'] ?? '00:00'));
            $record->jam_finish = trim((string) ($row['jam_finish'] ?? '00:00'));
            $record->deleted_at = null;
            $record->save();
            $this->imported++;
        }
    }
}
