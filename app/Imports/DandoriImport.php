<?php
namespace App\Imports;
use App\Support\SaneDate;

use App\Models\MasterKaryawan;
use App\Models\MasterLosstime;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiDandoriRincian;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithStartRow;

class DandoriImport implements ToCollection, WithHeadingRow, WithStartRow
{
    public int   $importedCount = 0;
    public array $errors        = [];

    /** Mulai baca data dari row 3 — skip row 2 (petunjuk) */
    public function startRow(): int { return 3; }

    public function collection(Collection $rows): void
    {
        foreach ($rows as $i => $row) {
            $rowNum = $i + 3; // row 3 = first data row

            /* Skip completely empty rows */
            $tanggalRaw = trim((string) ($row['tanggal'] ?? ''));
            if ($tanggalRaw === '') {
                continue;
            }

            try {
                /* ── Tanggal ───────────────────────────── */
                $tanggal = $this->parseDate($row['tanggal']);
                if (!$tanggal) {
                    $this->errors[] = "Baris {$rowNum}: Format tanggal tidak valid (gunakan YYYY-MM-DD atau DD/MM/YYYY).";
                    continue;
                }
                // Tahun 4-digit apa pun lolos parseDate — "0026" (salah ketik "2026") tersimpan
                // diam-diam lalu hilang dari laporan VCB yang memfilter per rentang tanggal.
                // Batas atas besok, bukan hari ini: app timezone UTC, pabrik WIB (UTC+7).
                if (!SaneDate::isSane($tanggal)) {
                    $this->errors[] = "Baris {$rowNum}: Tanggal \"{$tanggal}\" di luar rentang wajar — periksa tahunnya.";
                    continue;
                }

                /* ── Shift ─────────────────────────────── */
                $namaShift = trim((string) ($row['nama_shift'] ?? ''));
                $shift = MasterShift::whereRaw('LOWER(TRIM(nama_shift)) = ?', [strtolower($namaShift)])->first();
                if (!$shift) {
                    $this->errors[] = "Baris {$rowNum}: Shift \"{$namaShift}\" tidak ditemukan di master Shift.";
                    continue;
                }

                /* ── Operator ──────────────────────────── */
                $namaOp   = trim((string) ($row['nama_operator'] ?? ''));
                $karyawan = MasterKaryawan::where('departemen', 'Produksi')
                    ->whereRaw('LOWER(TRIM(nama_karyawan)) = ?', [strtolower($namaOp)])
                    ->first();
                if (!$karyawan) {
                    $this->errors[] = "Baris {$rowNum}: Operator \"{$namaOp}\" tidak ditemukan (periksa departemen = Produksi).";
                    continue;
                }

                /* ── Mesin ─────────────────────────────── */
                $idMesin = trim((string) ($row['id_mesin'] ?? ''));
                $mesin   = MasterMesin::whereRaw('LOWER(TRIM(id_mesin)) = ?', [strtolower($idMesin)])->first();
                if (!$mesin) {
                    $this->errors[] = "Baris {$rowNum}: Mesin \"{$idMesin}\" tidak ditemukan di master Mesin.";
                    continue;
                }

                /* ── Part ──────────────────────────────── */
                $partNo = trim((string) ($row['part_no'] ?? ''));
                $part   = MasterPart::whereRaw('LOWER(TRIM(part_no)) = ?', [strtolower($partNo)])->first();
                if (!$part) {
                    $this->errors[] = "Baris {$rowNum}: Part No \"{$partNo}\" tidak ditemukan di master Part.";
                    continue;
                }

                /* ── Proses ────────────────────────────── */
                $idProses   = trim((string) ($row['id_proses'] ?? ''));
                $partProses = MasterPartProses::where('id_part', $part->id)
                    ->where('id_proses', $idProses)
                    ->first();
                if (!$partProses) {
                    $this->errors[] = "Baris {$rowNum}: Proses \"{$idProses}\" untuk Part No \"{$partNo}\" tidak ditemukan.";
                    continue;
                }

                $isProgressive = strtoupper(trim($partProses->nama_proses)) === 'PROGRESSIVE';

                /* ── Loss Time — cari by kode dulu, fallback by nama jenis ── */
                $kodeLt   = trim((string) ($row['kode_loss_time'] ?? ''));
                $losstime = MasterLosstime::whereRaw('LOWER(TRIM(kode)) = ?', [strtolower($kodeLt)])->first()
                         ?? MasterLosstime::whereRaw('LOWER(TRIM(jenis_losstime)) = ?', [strtolower($kodeLt)])->first();
                if (!$losstime) {
                    $this->errors[] = "Baris {$rowNum}: Kode loss time \"{$kodeLt}\" tidak ditemukan di master Loss Time (coba pakai kode singkat, contoh: SD).";
                    continue;
                }

                /* ── Actual Menit ──────────────────────── */
                $actualMenit = (float) ($row['actual_menit'] ?? 0);
                if ($actualMenit < 0) {
                    $this->errors[] = "Baris {$rowNum}: Actual Menit tidak boleh negatif.";
                    continue;
                }

                /* ── Hitung Std & Over ─────────────────── */
                $stdMenit = $isProgressive
                    ? ($losstime->std_menit_progressive ?: ($losstime->std_menit_default ?: 0))
                    : ($losstime->std_menit_default ?: 0);

                $overMenit = max(0, $actualMenit - $stdMenit);

                /* ── Simpan (upsert by natural key — aman re-import) ── */
                $record = TransaksiDandoriRincian::withTrashed()->firstOrNew([
                    'tanggal'        => $tanggal,
                    'id_shift'       => $shift->id,
                    'id_karyawan'    => $karyawan->id,
                    'id_mesin'       => $mesin->id,
                    'id_part_proses' => $partProses->id,
                    'id_losstime'    => $losstime->id,
                ]);
                $record->actual_menit = $actualMenit;
                $record->std_menit    = $stdMenit;
                $record->over_menit   = $overMenit;
                $record->deleted_at   = null;
                if (!$record->exists) {
                    $record->created_by = auth()->id();
                }
                $record->save();

                $this->importedCount++;

            } catch (\Throwable $e) {
                $this->errors[] = "Baris {$rowNum}: Error tidak terduga — " . $e->getMessage();
            }
        }
    }

    /* ── Helpers ───────────────────────────────────────────────────── */

    private function parseDate(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') return null;

        if (is_numeric($value)) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value)
                    ->format('Y-m-d');
            } catch (\Throwable) {}
        }

        if ($value instanceof \Carbon\Carbon || $value instanceof \DateTime) {
            return $value->format('Y-m-d');
        }

        $str = trim((string) $value);
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'Y/m/d'] as $fmt) {
            $d = \DateTime::createFromFormat($fmt, $str);
            if ($d && $d->format($fmt) === $str) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }
}
