<?php

namespace App\Imports;

use App\Imports\Concerns\LewatiBarisContoh;
use App\Models\MasterLosstime;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class LosstimeImport implements SkipsEmptyRows, ToCollection, WithHeadingRow
{
    use LewatiBarisContoh;

    /** Baris contoh template — dibaca juga oleh template-nya. Lihat {@see LewatiBarisContoh}. */
    public const CONTOH = [
        ['BK', 'Breakdown Mesin', 30, 15],
        ['MT', 'Maintenance', 60, 30],
        ['ST', 'Setup / Dandori', 15, 10],
    ];

    public int $imported = 0;

    public int $skipped = 0;

    public function collection(Collection $rows): void
    {
        foreach ($rows as $row) {
            if ($this->barisContoh($row)) {
                continue;
            }

            $kode = trim((string) ($row['kode'] ?? ''));
            $jenis = trim((string) ($row['jenis_losstime'] ?? ''));
            if ($kode === '' || $jenis === '') {
                $this->skipped++;

                continue;
            }
            $record = MasterLosstime::withTrashed()->firstOrNew(['kode' => $kode]);
            $record->jenis_losstime = $jenis;
            $record->std_menit_default = is_numeric($row['std_menit_default'] ?? null)
                                                ? (float) $row['std_menit_default'] : 0;
            $record->std_menit_progressive = is_numeric($row['std_menit_progressive'] ?? null)
                                                ? (float) $row['std_menit_progressive'] : 0;
            $record->deleted_at = null;
            $record->save();
            $this->imported++;
        }
    }
}
