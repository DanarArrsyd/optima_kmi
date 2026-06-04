<?php
namespace App\Imports;
use App\Models\MasterJenisNg;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Illuminate\Support\Collection;

class JenisNgImport implements ToCollection, WithHeadingRow, SkipsEmptyRows
{
    public int $imported = 0;
    public int $skipped  = 0;

    public function collection(Collection $rows): void
    {
        foreach ($rows as $row) {
            $kode  = trim((string) ($row['kode']    ?? ''));
            $nama  = trim((string) ($row['nama_ng']  ?? ''));
            if ($kode === '' || $nama === '') { $this->skipped++; continue; }

            $record = MasterJenisNg::withTrashed()->firstOrNew(['kode' => $kode]);
            $record->nama_ng    = $nama;
            $record->deskripsi  = trim((string) ($row['deskripsi'] ?? '')) ?: null;
            $record->deleted_at = null;
            $record->save();
            $this->imported++;
        }
    }
}
