<?php

namespace App\Imports;

use App\Models\MasterKaryawan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class KaryawanImport implements SkipsEmptyRows, ToCollection, WithHeadingRow
{
    public int $imported = 0;

    public int $skipped = 0;

    public function collection(Collection $rows): void
    {
        // Dicek sekali di luar loop, bukan per baris — kode bisa live sebelum
        // migrasi menyusul, dan import tidak boleh 500 di jendela itu.
        $adaKolomTanggal = Schema::hasColumn('master_karyawan', 'tanggal_nonaktif');

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
            if ($adaKolomTanggal) {
                $record->tanggal_nonaktif = null;   // aktif tidak boleh menyimpan tanggal nonaktif
            }
            $record->deleted_at = null;
            $record->save();
            $this->imported++;
        }
    }
}
