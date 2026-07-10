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
            $record->tanggal_instalasi   = $this->parseDate($row['tanggal_instalasi'] ?? null);
            $record->deleted_at          = null;
            $record->save();
            $this->imported++;
        }
    }

    /** Parse Excel date cell (serial number or string) → Y-m-d, atau null. */
    private function parseDate($raw): ?string
    {
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }
        try {
            if (is_numeric($raw)) {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $raw)->format('Y-m-d');
            }
            return \Illuminate\Support\Carbon::parse(trim((string) $raw))->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
