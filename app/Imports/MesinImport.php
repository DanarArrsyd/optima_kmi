<?php
namespace App\Imports;
use App\Support\SaneDate;

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
            // Hanya set kalau ada nilai yang bisa dibaca. firstOrNew() memuat record lama,
            // jadi menugaskan null di sini akan menghapus tanggal instalasi yang sudah ada
            // hanya karena kolomnya tidak ikut di file Excel — dan tanggal itu adalah titik
            // awal siklus maintenance (lihat MaintenanceDashboardService::cycleStart).
            $tanggalInstalasi = $this->parseDate($row['tanggal_instalasi'] ?? null);
            if ($tanggalInstalasi !== null) {
                $record->tanggal_instalasi = $tanggalInstalasi;
            }
            $record->deleted_at          = null;
            $record->save();
            $this->imported++;
        }
    }

    /**
     * Parse Excel date cell (serial number or string) → Y-m-d, atau null.
     *
     * null di sini berarti "tidak ada nilai yang bisa dipakai" — caller menafsirkannya
     * sebagai jangan-sentuh, bukan hapus. Batas bawahnya 1990, bukan 2020 seperti tabel
     * transaksi: ini tanggal mesin dipasang, dan mesin stamping berumur belasan tahun
     * itu normal di pabrik.
     */
    private function parseDate($raw): ?string
    {
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }
        try {
            $parsed = is_numeric($raw)
                ? \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $raw)->format('Y-m-d')
                : \Illuminate\Support\Carbon::parse(trim((string) $raw))->toDateString();
        } catch (\Throwable $e) {
            return null;
        }

        return SaneDate::isSane($parsed, SaneDate::MIN_INSTALASI) ? $parsed : null;
    }
}
