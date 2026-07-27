<?php

namespace App\Imports;

use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Services\NgDetailService;
use App\Services\ProduksiService;
use App\Support\SaneDate;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class ProduksiImport implements ToCollection, WithHeadingRow
{
    public int $importedCount = 0;

    public array $errors = [];

    private ProduksiService $service;

    public function __construct()
    {
        $this->service = app(ProduksiService::class);
    }

    public function collection(Collection $rows): void
    {
        foreach ($rows as $i => $row) {
            $rowNum = $i + 2; // row 2 = first data row (row 1 = header)

            /* Skip completely empty rows */
            $tanggalRaw = trim((string) ($row['tanggal_produksi'] ?? ''));
            if ($tanggalRaw === '' || $tanggalRaw === null) {
                continue;
            }

            try {
                /* ── Tanggal ───────────────────────────────── */
                $tanggal = $this->parseDate($row['tanggal_produksi']);
                if (! $tanggal) {
                    $this->errors[] = "Baris {$rowNum}: Format tanggal produksi tidak valid (gunakan YYYY-MM-DD atau DD/MM/YYYY).";

                    continue;
                }

                /* ── Shift ─────────────────────────────────── */
                $namaShift = trim((string) ($row['nama_shift'] ?? ''));
                $shift = MasterShift::whereRaw('LOWER(TRIM(nama_shift)) = ?', [strtolower($namaShift)])->first();
                if (! $shift) {
                    $this->errors[] = "Baris {$rowNum}: Shift \"{$namaShift}\" tidak ditemukan di master Shift.";

                    continue;
                }

                /* ── Operator ──────────────────────────────── */
                $namaOp = trim((string) ($row['nama_operator'] ?? ''));
                $karyawan = MasterKaryawan::where('departemen', 'Produksi')
                    ->whereRaw('LOWER(TRIM(nama_karyawan)) = ?', [strtolower($namaOp)])
                    ->first();
                if (! $karyawan) {
                    $this->errors[] = "Baris {$rowNum}: Operator \"{$namaOp}\" tidak ditemukan (periksa departemen = Produksi).";

                    continue;
                }

                /* ── Mesin ─────────────────────────────────── */
                $idMesin = trim((string) ($row['id_mesin'] ?? ''));
                $mesin = MasterMesin::whereRaw('LOWER(TRIM(id_mesin)) = ?', [strtolower($idMesin)])->first();
                if (! $mesin) {
                    $this->errors[] = "Baris {$rowNum}: Mesin \"{$idMesin}\" tidak ditemukan di master Mesin.";

                    continue;
                }

                /* ── Part ──────────────────────────────────── */
                $partNo = trim((string) ($row['part_no'] ?? ''));
                $part = MasterPart::whereRaw('LOWER(TRIM(part_no)) = ?', [strtolower($partNo)])->first();
                if (! $part) {
                    $this->errors[] = "Baris {$rowNum}: Part No \"{$partNo}\" tidak ditemukan di master Part.";

                    continue;
                }

                /* ── Proses ────────────────────────────────── */
                $idProses = trim((string) ($row['id_proses'] ?? ''));
                $partProses = MasterPartProses::where('id_part', $part->id)
                    ->where('id_proses', $idProses)
                    ->first();
                if (! $partProses) {
                    $this->errors[] = "Baris {$rowNum}: Proses \"{$idProses}\" untuk Part No \"{$partNo}\" tidak ditemukan.";

                    continue;
                }

                /* ── Jam ───────────────────────────────────── */
                $jamStart = $this->parseTime($row['jam_start'] ?? null);
                $jamFinish = $this->parseTime($row['jam_finish'] ?? null);
                if (! $jamStart || ! $jamFinish) {
                    $this->errors[] = "Baris {$rowNum}: Format jam tidak valid (gunakan HH:MM, contoh: 08:00).";

                    continue;
                }

                /* ── Qty ───────────────────────────────────── */
                $qtyAktual = (int) ($row['qty_aktual'] ?? 0);
                if ($qtyAktual < 1) {
                    $this->errors[] = "Baris {$rowNum}: Qty Aktual harus minimal 1.";

                    continue;
                }

                /* ── NG (opsional) ─────────────────────────── */
                $qtyNg = null;
                $idJenisNg = null;
                $qtyNgRaw = trim((string) ($row['qty_ng'] ?? ''));

                if ($qtyNgRaw !== '' && (int) $qtyNgRaw > 0) {
                    $qtyNg = (int) $qtyNgRaw;

                    $kodeNg = trim((string) ($row['kode_jenis_ng'] ?? ''));

                    if ($kodeNg === '') {
                        $this->errors[] = "Baris {$rowNum}: Qty NG diisi ({$qtyNg}) tapi Kode Jenis NG kosong. "
                            .'Isi kode jenis NG-nya — NG tanpa jenis tidak bisa disimpan.';

                        continue;
                    }

                    $jenisNg = MasterJenisNg::whereRaw('LOWER(TRIM(kode)) = ?', [strtolower($kodeNg)])->first();

                    if (! $jenisNg) {
                        $this->errors[] = "Baris {$rowNum}: Jenis NG \"{$kodeNg}\" tidak ditemukan di master Jenis NG.";

                        continue;
                    }

                    $idJenisNg = $jenisNg->id;
                }

                /* ── Cycle time ────────────────────────────── */
                $cycleTime = $this->service->calculateActualCycleTime(
                    $jamStart.':00',
                    $jamFinish.':00',
                    $qtyAktual
                );

                $produksi = TransaksiProduksi::create([
                    'tanggal_produksi' => $tanggal,
                    'id_shift' => $shift->id,
                    'id_karyawan' => $karyawan->id,
                    'id_mesin' => $mesin->id,
                    'id_part_proses' => $partProses->id,
                    'jam_start' => $jamStart,
                    'jam_finish' => $jamFinish,
                    'qty_aktual' => $qtyAktual,
                    'actual_cycle_time' => $cycleTime,
                    'created_by' => auth()->id(),
                ]);

                app(NgDetailService::class)->syncProduksi(
                    $produksi,
                    ($qtyNg > 0 && $idJenisNg)
                        ? [['id_jenis_ng' => $idJenisNg, 'qty' => $qtyNg]]
                        : []
                );

                $this->importedCount++;

            } catch (\Throwable $e) {
                $this->errors[] = "Baris {$rowNum}: Error tidak terduga — ".$e->getMessage();
            }
        }
    }

    /* ── Helpers ─────────────────────────────────────────────────────── */

    /**
     * Tanggal di luar rentang wajar diperlakukan tak terbaca — tahun 4-digit apa pun lolos
     * parsing, jadi "0026-06-21" (salah ketik "2026") akan tersimpan diam-diam dan hilang
     * dari laporan yang memfilter per rentang tanggal.
     */
    private function parseDate(mixed $value): ?string
    {
        $parsed = $this->parseDateUnbounded($value);

        return SaneDate::isSane($parsed) ? $parsed : null;
    }

    private function parseDateUnbounded(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        /* Excel serial number */
        if (is_numeric($value)) {
            try {
                return Date::excelToDateTimeObject((float) $value)
                    ->format('Y-m-d');
            } catch (\Throwable) {
            }
        }

        /* Carbon / DateTime object from Maatwebsite */
        if ($value instanceof Carbon || $value instanceof \DateTime) {
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

    private function parseTime(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        /* Excel fractional day (e.g. 0.333... = 08:00) */
        if (is_numeric($value) && (float) $value < 1) {
            $secs = (int) round((float) $value * 86400);
            $h = intdiv($secs, 3600);
            $m = intdiv($secs % 3600, 60);

            return sprintf('%02d:%02d', $h, $m);
        }

        $str = trim((string) $value);
        if (preg_match('/^(\d{1,2}):(\d{2})/', $str, $mt)) {
            return sprintf('%02d:%02d', (int) $mt[1], (int) $mt[2]);
        }

        return null;
    }
}
