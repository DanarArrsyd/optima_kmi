<?php

namespace App\Imports;

use App\Models\MasterKaryawan;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class KaryawanImport implements SkipsEmptyRows, ToCollection, WithHeadingRow
{
    public int $imported = 0;

    public int $skipped = 0;

    public function collection(Collection $rows): void
    {
        foreach ($rows as $row) {
            $nik = trim((string) ($row['nik'] ?? ''));
            $nama = trim((string) ($row['nama_karyawan'] ?? ''));
            if ($nik === '' || $nama === '') {
                $this->skipped++;

                continue;
            }
            $record = MasterKaryawan::withTrashed()->firstOrNew(['nama_karyawan' => $nama]);
            $record->nik = $nik;
            $record->nama_karyawan = $nama;
            $record->departemen = trim((string) ($row['departemen'] ?? 'Produksi'));
            $record->is_aktif = true;
            $record->tanggal_nonaktif = null;   // aktif tidak boleh menyimpan tanggal nonaktif
            $record->deleted_at = null;
            $record->save();
            $this->imported++;
        }
    }
}
