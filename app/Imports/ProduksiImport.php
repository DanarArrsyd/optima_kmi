<?php

namespace App\Imports;

use App\Imports\Concerns\ParsesStrictImportDateTime;
use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Services\NgDetailService;
use App\Services\ProduksiService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ProduksiImport implements ToCollection, WithHeadingRow
{
    use ParsesStrictImportDateTime;

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
                $shift = $this->resolveShift($namaShift);
                if (! $shift) {
                    $this->errors[] = "Baris {$rowNum}: Shift \"{$namaShift}\" tidak ditemukan di master Shift.";

                    continue;
                }

                /* ── Operator ──────────────────────────────── */
                $namaOp = trim((string) ($row['nama_operator'] ?? ''));
                $karyawan = $this->resolveOperator($namaOp);
                if (! $karyawan) {
                    $this->errors[] = "Baris {$rowNum}: Operator \"{$namaOp}\" tidak ditemukan (periksa departemen = Produksi).";

                    continue;
                }

                /* ── Mesin ─────────────────────────────────── */
                $idMesin = trim((string) ($row['id_mesin'] ?? ''));
                $mesin = $this->resolveMesin($idMesin);
                if (! $mesin) {
                    $this->errors[] = "Baris {$rowNum}: Mesin \"{$idMesin}\" tidak ditemukan di master Mesin.";

                    continue;
                }

                /* ── Part ──────────────────────────────────── */
                $partNo = trim((string) ($row['part_no'] ?? ''));
                $part = $this->resolvePart($partNo);
                if (! $part) {
                    $this->errors[] = "Baris {$rowNum}: Part No \"{$partNo}\" tidak ditemukan di master Part.";

                    continue;
                }

                /* ── Proses ────────────────────────────────── */
                $idProses = trim((string) ($row['id_proses'] ?? ''));
                $partProses = $this->resolvePartProses($part->id, $idProses);
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

                    $jenisNg = $this->resolveJenisNg($kodeNg);

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

    /* ── Lookup helpers ──────────────────────────────────────────────── */

    private function resolveShift(string $nama): ?MasterShift
    {
        return MasterShift::whereRaw('LOWER(TRIM(nama_shift)) = ?', [strtolower($nama)])->first();
    }

    private function resolveOperator(string $nama): ?MasterKaryawan
    {
        return MasterKaryawan::where('departemen', 'Produksi')
            ->whereRaw('LOWER(TRIM(nama_karyawan)) = ?', [strtolower($nama)])
            ->first();
    }

    private function resolveMesin(string $idMesin): ?MasterMesin
    {
        return MasterMesin::whereRaw('LOWER(TRIM(id_mesin)) = ?', [strtolower($idMesin)])->first();
    }

    private function resolvePart(string $partNo): ?MasterPart
    {
        return MasterPart::whereRaw('LOWER(TRIM(part_no)) = ?', [strtolower($partNo)])->first();
    }

    private function resolvePartProses(int $idPart, string $idProses): ?MasterPartProses
    {
        return MasterPartProses::where('id_part', $idPart)
            ->where('id_proses', $idProses)
            ->first();
    }

    private function resolveJenisNg(string $kode): ?MasterJenisNg
    {
        return MasterJenisNg::whereRaw('LOWER(TRIM(kode)) = ?', [strtolower($kode)])->first();
    }
}
