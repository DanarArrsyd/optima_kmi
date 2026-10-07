<?php

namespace App\Imports;

use App\Imports\Concerns\LewatiBarisContoh;
use App\Models\MasterMesin;
use App\Support\SaneDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class MesinImport implements SkipsEmptyRows, ToCollection, WithHeadingRow
{
    use LewatiBarisContoh;

    /** Baris contoh template — dibaca juga oleh template-nya. Lihat {@see LewatiBarisContoh}. */
    public const CONTOH = [
        ['M001', 'Press 80T #1', 'Line A', 'Progressive', 800000, '2024-01-15'],
        ['M002', 'Press 110T #1', 'Line B', 'Progressive', 1100000, '2023-06-01'],
    ];

    public int $imported = 0;

    public int $skipped = 0;

    public function collection(Collection $rows): void
    {
        foreach ($rows as $row) {
            if ($this->barisContoh($row)) {
                continue;
            }

            $idMesin = trim((string) ($row['id_mesin'] ?? ''));
            $nama = trim((string) ($row['nama_mesin'] ?? ''));
            if ($idMesin === '' || $nama === '') {
                $this->skipped++;

                continue;
            }
            $record = MasterMesin::withTrashed()->firstOrNew(['id_mesin' => $idMesin]);
            $record->nama_mesin = $nama;

            // Kolom yang tidak ada di file / sel kosong tidak boleh menimpa data yang sudah
            // benar — record lama dimuat ulang di sini. Record BARU tetap diisi apa adanya:
            // master_mesin.line & tipe_mesin NOT NULL, jadi tak boleh dilewat.
            $line = trim((string) ($row['line'] ?? ''));
            if ($line !== '' || ! $record->exists) {
                $record->line = $line;
            }

            $tipe = trim((string) ($row['tipe_mesin'] ?? ''));
            if ($tipe !== '' || ! $record->exists) {
                $record->tipe_mesin = $tipe;
            }

            if (is_numeric($row['kapasitas_max_stroke'] ?? null)) {
                $record->kapasitas_max_stroke = (int) $row['kapasitas_max_stroke'];
            } elseif (! $record->exists) {
                $record->kapasitas_max_stroke = null;
            }
            // Hanya set kalau ada nilai yang bisa dibaca. firstOrNew() memuat record lama,
            // jadi menugaskan null di sini akan menghapus tanggal instalasi yang sudah ada
            // hanya karena kolomnya tidak ikut di file Excel — dan tanggal itu adalah titik
            // awal siklus maintenance (lihat MaintenanceDashboardService::cycleStart).
            $tanggalInstalasi = $this->parseDate($row['tanggal_instalasi'] ?? null);
            if ($tanggalInstalasi !== null) {
                $record->tanggal_instalasi = $tanggalInstalasi;
            }
            $record->deleted_at = null;
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
                ? Date::excelToDateTimeObject((float) $raw)->format('Y-m-d')
                : Carbon::parse(trim((string) $raw))->toDateString();
        } catch (\Throwable $e) {
            return null;
        }

        return SaneDate::isSane($parsed, SaneDate::MIN_INSTALASI) ? $parsed : null;
    }
}
