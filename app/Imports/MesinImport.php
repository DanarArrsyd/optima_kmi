<?php
namespace App\Imports;

use App\Models\MasterMesin;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Illuminate\Support\Collection;

class MesinImport implements ToCollection, WithHeadingRow, SkipsEmptyRows
{
    public int $imported = 0;
    public int $skipped  = 0;

    public function collection(Collection $rows): void
    {
        foreach ($rows as $row) {
            $idMesin = trim((string) ($row['id_mesin'] ?? ''));
            $nama    = trim((string) ($row['nama_mesin'] ?? ''));
            if ($idMesin === '' || $nama === '') {
                $this->skipped++;
                continue;
            }
            $record = MasterMesin::withTrashed()->firstOrNew(['id_mesin' => $idMesin]);
            $record->nama_mesin          = $nama;
            $record->line                = trim((string) ($row['line'] ?? ''));
            $record->tipe_mesin          = trim((string) ($row['tipe_mesin'] ?? ''));
            $record->kapasitas_max_stroke = is_numeric($row['kapasitas_max_stroke'] ?? null)
                                                ? (int) $row['kapasitas_max_stroke'] : null;
            $record->deleted_at          = null;
            $record->save();
            $this->imported++;
        }
    }
}
