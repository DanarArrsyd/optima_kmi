<?php
namespace App\Exports;

use App\Models\MasterPartProses;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class PartExport implements FromCollection, WithHeadings
{
    public function __construct(private ?string $customer = null) {}

    public function headings(): array
    {
        return [
            'Customer', 'Part No', 'Part Name', 'Tanggal Instalasi',
            'ID Proses', 'Nama Proses',
            'SPH Produksi',
            'SPH QC', 'Std Trial QC Menit',
            'Std Setting Dies Menit',
        ];
    }

    public function collection(): \Illuminate\Support\Enumerable
    {
        $q = MasterPartProses::with('part')
            ->join('master_part', 'master_part_proses.id_part', '=', 'master_part.id')
            ->whereNull('master_part.deleted_at')
            ->select('master_part_proses.*');

        if ($this->customer) {
            $q->where('master_part.customer', $this->customer);
        }

        return $q->orderBy('master_part.part_name')->orderBy('master_part_proses.id_proses')
            ->get()->map(fn ($pr) => [
                $pr->part?->customer ?? '',
                $pr->part?->part_no  ?? '',
                $pr->part?->part_name ?? '',
                optional($pr->part?->tanggal_instalasi)->format('Y-m-d'),
                $pr->id_proses,
                $pr->nama_proses,
                $pr->sph_standar_produksi,
                $pr->sph_standar_qc,
                $pr->std_trial_qc_menit,
                $pr->std_setting_dies_menit,
            ]);
    }
}
