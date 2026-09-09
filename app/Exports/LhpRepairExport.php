<?php

namespace App\Exports;

use App\Models\TransaksiRepair;
use Illuminate\Support\Enumerable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class LhpRepairExport implements FromCollection, WithColumnWidths, WithHeadings, WithStyles
{
    public function __construct(
        private ?string $dateFrom = null,
        private ?string $dateTo = null,
        private ?string $customer = null,
        private ?string $partName = null,
        private ?string $namaProses = null,
    ) {}

    public function collection(): Enumerable
    {
        $q = TransaksiRepair::with(['karyawan', 'partProsesRepair.part', 'shift']);

        if ($this->dateFrom) {
            $q->whereDate('tanggal_repair', '>=', $this->dateFrom);
        }
        if ($this->dateTo) {
            $q->whereDate('tanggal_repair', '<=', $this->dateTo);
        }
        if ($this->customer) {
            $q->whereHas('partProsesRepair.part', fn ($q) => $q->where('customer', 'like', "%{$this->customer}%"));
        }
        if ($this->partName) {
            $q->whereHas('partProsesRepair.part', fn ($q) => $q->where('part_name', 'like', "%{$this->partName}%"));
        }
        if ($this->namaProses) {
            $q->whereHas('partProsesRepair', fn ($q) => $q->where('nama_proses', 'like', "%{$this->namaProses}%"));
        }

        return $q->orderBy('tanggal_repair')->orderBy('id')->get()->map(function ($t) {
            $pp = $t->partProsesRepair;
            $part = $pp?->part;
            $sphStd = $pp?->sph_standar_repair ?? 0;
            $sphAct = $t->sph_aktual;
            $pct = $sphStd > 0 ? round($sphAct / $sphStd * 100, 1) : '';

            return [
                $t->tanggal_repair,
                $t->shift?->nama_shift ?? '',
                $t->karyawan?->nama_karyawan ?? '',
                $part?->customer ?? '',
                $part?->part_no ?? '',
                $part?->part_name ?? '',
                $pp?->id_proses ?? '',
                $pp?->nama_proses ?? '',
                substr($t->jam_start, 0, 5),
                substr($t->jam_finish, 0, 5),
                $t->qty_aktual,
                $sphStd,
                $sphAct,
                round($t->waktu_kerja_jam, 2),
                round($t->actual_cycle_time, 2),
                $pct !== '' ? $pct.'%' : '',
            ];
        });
    }

    public function headings(): array
    {
        return [
            'Tanggal', 'Shift', 'Operator', 'Customer', 'Part No', 'Part Name',
            'ID Proses', 'Nama Proses', 'Jam Start', 'Jam Finish', 'Qty Act Repair',
            'SPH Std', 'SPH Aktual', 'W. Kerja (jam)', 'CT Aktual (dtk)', '%',
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 13, 'B' => 12, 'C' => 20, 'D' => 14, 'E' => 14, 'F' => 22,
            'G' => 9, 'H' => 16, 'I' => 10, 'J' => 10, 'K' => 13,
            'L' => 9, 'M' => 11, 'N' => 15, 'O' => 15, 'P' => 8,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF111827']],
                'alignment' => ['wrapText' => true],
            ],
        ];
    }
}
