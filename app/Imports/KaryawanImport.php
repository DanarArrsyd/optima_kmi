<?php
namespace App\Imports;

use App\Models\MasterKaryawan;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Illuminate\Support\Collection;

class KaryawanImport implements ToCollection, WithHeadingRow, SkipsEmptyRows
{
    public int $imported = 0;
    public int $skipped  = 0;

    public function collection(Collection $rows): void
    {
        foreach ($rows as $row) {
            $nik  = trim((string) ($row['nik'] ?? ''));
            $nama = trim((string) ($row['nama_karyawan'] ?? ''));
            if ($nik === '' || $nama === '') {
                $this->skipped++;
                continue;
            }
            $record = MasterKaryawan::withTrashed()->firstOrNew(['nama_karyawan' => $nama]);
            $record->nik           = $nik;
            $record->nama_karyawan = $nama;
            $record->departemen    = trim((string) ($row['departemen'] ?? 'Produksi'));
            $record->is_aktif      = true;
            $record->deleted_at    = null;
            $record->save();
            $this->imported++;
        }
    }
}
