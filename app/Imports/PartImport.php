<?php
namespace App\Imports;

use App\Models\MasterPart;
use App\Models\MasterPartProses;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Illuminate\Support\Collection;

class PartImport implements ToCollection, WithHeadingRow, SkipsEmptyRows
{
    public int $imported = 0;
    public int $skipped  = 0;

    public function collection(Collection $rows): void
    {
        foreach ($rows as $row) {
            $partNo   = trim((string) ($row['part_no']   ?? ''));
            $partName = trim((string) ($row['part_name'] ?? ''));

            if ($partNo === '' || $partName === '') {
                $this->skipped++;
                continue;
            }

            /* Find-or-create Part header (restore soft-deleted if exists) */
            $part = MasterPart::withTrashed()->firstOrNew(['part_no' => $partNo]);
            $part->customer   = trim((string) ($row['customer'] ?? ''));
            $part->part_name  = $partName;
            $part->deleted_at = null;
            $part->save();

            /* Import the Proses row if id_proses + nama_proses present */
            $idProses   = trim((string) ($row['id_proses']   ?? ''));
            $namaProses = trim((string) ($row['nama_proses'] ?? ''));

            if ($idProses === '' || $namaProses === '') {
                $this->imported++;
                continue;
            }

            $proses = MasterPartProses::withTrashed()->firstOrNew([
                'id_part'   => $part->id,
                'id_proses' => (int) $idProses,
            ]);

            $proses->nama_proses            = $namaProses;
            $proses->sph_standar_produksi   = is_numeric($row['sph_produksi'] ?? null)
                                                ? (float) $row['sph_produksi'] : 0;
            $proses->sph_standar_qc         = is_numeric($row['sph_qc'] ?? null)
                                                ? (float) $row['sph_qc'] : 0;
            $proses->std_trial_qc_menit     = is_numeric($row['std_trial_qc_menit'] ?? null)
                                                ? (int) $row['std_trial_qc_menit'] : 0;
            $proses->std_setting_dies_menit = is_numeric($row['std_setting_dies_menit'] ?? null)
                                                ? (int) $row['std_setting_dies_menit'] : 0;
            $proses->deleted_at             = null;
            $proses->save();

            $this->imported++;
        }
    }
}
