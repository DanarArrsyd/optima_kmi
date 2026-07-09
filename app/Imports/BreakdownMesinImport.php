<?php
namespace App\Imports;

use App\Imports\Concerns\ParsesExcelDateTime;
use App\Models\MasterMesin;
use App\Models\TransaksiBreakdownMesin;
use App\Services\BreakdownMesinService;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Illuminate\Support\Collection;

class BreakdownMesinImport implements ToCollection, WithHeadingRow, SkipsEmptyRows
{
    use ParsesExcelDateTime;

    public int $imported = 0;
    public int $skipped  = 0;
    public array $errors = [];

    public function __construct(private BreakdownMesinService $svc) {}

    public function collection(Collection $rows): void
    {
        $nextNomor = $this->svc->nextNomorUrut();
        $mesinByKode = MasterMesin::pluck('id', 'id_mesin'); // kode → id

        foreach ($rows as $i => $row) {
            $line = $i + 2; // baris excel (header = 1)

            $kode = trim((string) ($row['id_mesin'] ?? ''));
            $mesinId = $mesinByKode[$kode] ?? null;
            if (! $mesinId) {
                $this->skipped++;
                $this->errors[] = "Baris {$line}: ID Mesin '{$kode}' tidak ditemukan di master.";
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
            $noLkm = $this->svc->generateNoLkm($nomor, $in['tanggal_breakdown']);

            if (TransaksiBreakdownMesin::withTrashed()->where('no_lkm', $noLkm)->exists()) {
                $this->skipped++;
                $this->errors[] = "Baris {$line}: No LKM {$noLkm} sudah ada.";
                continue;
            }

            $derived = $this->svc->computeDerived($in);
            TransaksiBreakdownMesin::create(array_merge($in, $derived, [
                'no_lkm'             => $noLkm,
                'id_mesin'           => $mesinId,
                'problem_mesin'      => trim((string) ($row['problem'] ?? '')),
                'penyebab_breakdown' => trim((string) ($row['penyebab'] ?? '')),
                'tindakan_perbaikan' => trim((string) ($row['tindakan'] ?? '')),
                'pic_maintenance'    => trim((string) ($row['pic'] ?? '')),
            ]));
            $this->imported++;
        }
    }
}
