<?php
namespace App\Imports;

use App\Models\MasterLosstime;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Illuminate\Support\Collection;

class LosstimeImport implements ToCollection, WithHeadingRow, SkipsEmptyRows
{
    public int $imported = 0;
    public int $skipped  = 0;

    public function collection(Collection $rows): void
    {
        foreach ($rows as $row) {
            $kode  = trim((string) ($row['kode'] ?? ''));
            $jenis = trim((string) ($row['jenis_losstime'] ?? ''));
            if ($kode === '' || $jenis === '') {
                $this->skipped++;
                continue;
            }
            $record = MasterLosstime::withTrashed()->firstOrNew(['kode' => $kode]);
            $record->jenis_losstime        = $jenis;
            $record->std_menit_default     = is_numeric($row['std_menit_default'] ?? null)
                                                ? (float) $row['std_menit_default'] : 0;
            $record->std_menit_progressive = is_numeric($row['std_menit_progressive'] ?? null)
                                                ? (float) $row['std_menit_progressive'] : 0;
            $record->deleted_at            = null;
            $record->save();
            $this->imported++;
        }
    }
}
