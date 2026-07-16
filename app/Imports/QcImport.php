<?php
namespace App\Imports;
use App\Support\SaneDate;

use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiQc;
use App\Services\QcService;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Collection;

class QcImport implements ToCollection, WithHeadingRow
{
    public int   $importedCount = 0;
    public array $errors        = [];

    private QcService $service;

    public function __construct()
    {
        $this->service = app(QcService::class);
    }

    public function collection(Collection $rows): void
    {
        foreach ($rows as $i => $row) {
            $rowNum = $i + 2;

            $tanggalRaw = trim((string) ($row['tgl_pengecekan'] ?? ''));
            if ($tanggalRaw === '') continue;

            try {
                /* ── Tanggal ───────────────────────────────── */
                $tglCek  = $this->parseDate($row['tgl_pengecekan']);
                $tglProd = $this->parseDate($row['tgl_produksi_part'] ?? null);
                if (!$tglCek) {
                    $this->errors[] = "Baris {$rowNum}: Format Tgl Pengecekan tidak valid (gunakan YYYY-MM-DD atau DD/MM/YYYY).";
                    continue;
                }
                if (!$tglProd) {
                    $this->errors[] = "Baris {$rowNum}: Format Tgl Produksi Part tidak valid.";
                    continue;
                }

                /* ── Shift ─────────────────────────────────── */
                $namaShift = trim((string) ($row['nama_shift'] ?? ''));
                $shift = MasterShift::whereRaw('LOWER(TRIM(nama_shift)) = ?', [strtolower($namaShift)])->first();
                if (!$shift) {
                    $this->errors[] = "Baris {$rowNum}: Shift \"{$namaShift}\" tidak ditemukan.";
                    continue;
                }

                /* ── QC Inspector ──────────────────────────── */
                $namaInsp = trim((string) ($row['nama_inspector'] ?? ''));
                $karyawan = MasterKaryawan::where('departemen', 'QC')
                    ->whereRaw('LOWER(TRIM(nama_karyawan)) = ?', [strtolower($namaInsp)])
                    ->first();
                if (!$karyawan) {
                    $this->errors[] = "Baris {$rowNum}: Inspector \"{$namaInsp}\" tidak ditemukan (periksa departemen = QC).";
                    continue;
                }

                /* ── Part — auto-select finish-good proses ─── */
                $partNo = trim((string) ($row['part_no'] ?? ''));
                $part = MasterPart::whereRaw('LOWER(TRIM(part_no)) = ?', [strtolower($partNo)])->first();
                if (!$part) {
                    $this->errors[] = "Baris {$rowNum}: Part No \"{$partNo}\" tidak ditemukan.";
                    continue;
                }

                /* Finish-good = proses dengan id_proses tertinggi */
                $partProses = MasterPartProses::where('id_part', $part->id)
                    ->orderByDesc('id_proses')
                    ->first();
                if (!$partProses) {
                    $this->errors[] = "Baris {$rowNum}: Part \"{$partNo}\" belum memiliki proses.";
                    continue;
                }

                /* ── Jam ───────────────────────────────────── */
                $jamStart  = $this->parseTime($row['jam_start']  ?? null);
                $jamFinish = $this->parseTime($row['jam_finish'] ?? null);
                if (!$jamStart || !$jamFinish) {
                    $this->errors[] = "Baris {$rowNum}: Format jam tidak valid (gunakan HH:MM, contoh: 08:00).";
                    continue;
                }

                /* ── Qty ───────────────────────────────────── */
                $qtyOk = (int) ($row['qty_ok'] ?? 0);
                $qtyNg = (int) ($row['qty_ng'] ?? 0);
                if ($qtyOk + $qtyNg < 1) {
                    $this->errors[] = "Baris {$rowNum}: Total Qty OK + Qty NG harus lebih dari 0.";
                    continue;
                }

                /* ── Jenis NG (opsional) ───────────────────── */
                $idJenisNg = null;
                $kodeNg    = trim((string) ($row['kode_jenis_ng'] ?? ''));
                if ($qtyNg > 0 && $kodeNg !== '') {
                    $jenisNg = MasterJenisNg::whereRaw('LOWER(TRIM(kode)) = ?', [strtolower($kodeNg)])->first();
                    if (!$jenisNg) {
                        $this->errors[] = "Baris {$rowNum}: Jenis NG \"{$kodeNg}\" tidak ditemukan di master Jenis NG.";
                        continue;
                    }
                    $idJenisNg = $jenisNg->id;
                }

                /* ── Cycle time ────────────────────────────── */
                $cycleTime = $this->service->calculateActualCycleTime(
                    $jamStart . ':00',
                    $jamFinish . ':00',
                    $qtyOk,
                    $qtyNg
                );

                TransaksiQc::create([
                    'tgl_pengecekan'    => $tglCek,
                    'tgl_produksi_part' => $tglProd,
                    'id_shift'          => $shift->id,
                    'id_karyawan_qc'    => $karyawan->id,
                    'id_part_proses'    => $partProses->id,
                    'jam_start'         => $jamStart,
                    'jam_finish'        => $jamFinish,
                    'qty_ok'            => $qtyOk,
                    'qty_ng'            => $qtyNg,
                    'id_jenis_ng'       => $idJenisNg,
                    'actual_cycle_time' => $cycleTime,
                    'created_by'        => auth()->id(),
                ]);

                $this->importedCount++;

            } catch (\Throwable $e) {
                $this->errors[] = "Baris {$rowNum}: Error tidak terduga — " . $e->getMessage();
            }
        }
    }

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
            if ($d && $d->format($fmt) === $str) return $d->format('Y-m-d');
        }
        return null;
    }

    private function parseTime(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') return null;
        if (is_numeric($value) && (float) $value < 1) {
            $secs = (int) round((float) $value * 86400);
            return sprintf('%02d:%02d', intdiv($secs, 3600), intdiv($secs % 3600, 60));
        }
        $str = trim((string) $value);
        if (preg_match('/^(\d{1,2}):(\d{2})/', $str, $mt)) {
            return sprintf('%02d:%02d', (int) $mt[1], (int) $mt[2]);
        }
        return null;
    }
}
