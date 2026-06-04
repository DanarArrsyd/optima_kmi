<?php
namespace App\Imports;

use App\Models\MasterShift;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Illuminate\Support\Collection;

class ShiftImport implements ToCollection, WithHeadingRow, SkipsEmptyRows
{
    public int $imported = 0;
    public int $skipped  = 0;

    public function collection(Collection $rows): void
    {
        foreach ($rows as $row) {
            $nama = trim((string) ($row['nama_shift'] ?? ''));
            if ($nama === '') {
                $this->skipped++;
                continue;
            }
            $record = MasterShift::withTrashed()->firstOrNew(['nama_shift' => $nama]);
            $record->jam_start  = trim((string) ($row['jam_start']  ?? '00:00'));
            $record->jam_finish = trim((string) ($row['jam_finish'] ?? '00:00'));
            $record->deleted_at = null;
            $record->save();
            $this->imported++;
        }
    }
}
