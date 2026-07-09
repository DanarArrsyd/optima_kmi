<?php
namespace App\Imports;

use App\Imports\Concerns\ParsesExcelDateTime;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\TransaksiBreakdownDies;
use App\Services\BreakdownDiesService;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Illuminate\Support\Collection;

class BreakdownDiesImport implements ToCollection, WithHeadingRow, SkipsEmptyRows
{
    use ParsesExcelDateTime;

    public int $imported = 0;
    public int $skipped  = 0;
    public array $errors = [];

    public function __construct(private BreakdownDiesService $svc) {}

    public function collection(Collection $rows): void
    {
        $nextNomor = $this->svc->nextNomorUrut();

        foreach ($rows as $i => $row) {
            $line = $i + 2;

            $partNo = trim((string) ($row['part_no'] ?? ''));
            $part   = MasterPart::whereRaw('LOWER(TRIM(part_no)) = ?', [strtolower($partNo)])->first();
            if (! $part) {
                $this->skipped++;
                $this->errors[] = "Baris {$line}: Part No '{$partNo}' tidak ditemukan di master.";
                continue;
            }

            $idProses   = trim((string) ($row['id_proses'] ?? ''));
            $partProses = MasterPartProses::where('id_part', $part->id)
                ->where('id_proses', $idProses)
                ->first();
            if (! $partProses) {
                $this->skipped++;
                $this->errors[] = "Baris {$line}: Proses '{$idProses}' untuk Part No '{$partNo}' tidak ditemukan.";
                continue;
            }

            $in = [
                'tanggal_breakdown'         => $this->parseDate($row['tanggal_breakdown'] ?? null),
                'jam_breakdown'             => $this->parseTime($row['jam_breakdown'] ?? null),
                'tanggal_mulai_perbaikan'   => $this->parseDate($row['tanggal_mulai_perbaikan'] ?? null),
                'jam_mulai_perbaikan'       => $this->parseTime($row['jam_mulai_perbaikan'] ?? null),
                'tanggal_selesai_perbaikan' => $this->parseDate($row['tanggal_selesai_perbaikan'] ?? null),
                'jam_selesai_perbaikan'     => $this->parseTime($row['jam_selesai_perbaikan'] ?? null),
            ];

            if (in_array(null, $in, true)) {
                $this->skipped++;
                $this->errors[] = "Baris {$line}: tanggal/jam tidak lengkap atau format salah.";
                continue;
            }

            $nomor = (int) ($row['nomor_urut'] ?? 0);
            if ($nomor < 1) {
                $nomor = $nextNomor++;
            }
            $noLkd = $this->svc->generateNoLkd($nomor, $in['tanggal_breakdown']);

            if (TransaksiBreakdownDies::withTrashed()->where('no_lkd', $noLkd)->exists()) {
                $this->skipped++;
                $this->errors[] = "Baris {$line}: No LKD {$noLkd} sudah ada.";
                continue;
            }

            $derived = $this->svc->computeDerived($in);
            TransaksiBreakdownDies::create(array_merge($in, $derived, [
                'no_lkd'             => $noLkd,
                'id_part_proses'     => $partProses->id,
                'problem_dies'       => trim((string) ($row['problem'] ?? '')),
                'penyebab_breakdown' => trim((string) ($row['penyebab'] ?? '')),
                'tindakan_perbaikan' => trim((string) ($row['tindakan'] ?? '')),
                'pic_maintenance'    => trim((string) ($row['pic'] ?? '')),
            ]));
            $this->imported++;
        }
    }
}
