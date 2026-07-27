<?php

namespace App\Exports;

use App\Models\TransaksiQc;
use Illuminate\Support\Enumerable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class LhpQcExport implements FromCollection, WithColumnWidths, WithHeadings, WithStyles
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
        $q = TransaksiQc::with([
            'karyawanQc', 'partProses.part', 'shift', 'ngDetails.jenisNg',
        ]);

        if ($this->dateFrom) {
            $q->whereDate('tgl_pengecekan', '>=', $this->dateFrom);
        }
        if ($this->dateTo) {
            $q->whereDate('tgl_pengecekan', '<=', $this->dateTo);
        }
        if ($this->customer) {
            $q->whereHas('partProses.part', fn ($q) => $q->where('customer', 'like', "%{$this->customer}%"));
        }
        if ($this->partName) {
            $q->whereHas('partProses.part', fn ($q) => $q->where('part_name', 'like', "%{$this->partName}%"));
        }
        if ($this->namaProses) {
            $q->whereHas('partProses', fn ($q) => $q->where('nama_proses', 'like', "%{$this->namaProses}%"));
        }

        return $q->orderBy('tgl_pengecekan')->orderBy('id')->get()->map(function ($t) {
            $pp = $t->partProses;
            $part = $pp?->part;
            $sphStd = $pp?->sph_standar_qc ?? 0;
            $sphAct = $t->sph_aktual;
            $total = $t->qty_ok + ($t->qty_ng ?? 0);
            $pct = $sphStd > 0 ? round($sphAct / $sphStd * 100, 1) : '';

            return [
                $t->tgl_pengecekan,
                $t->tgl_produksi_part,
                $t->shift?->nama_shift ?? '',
                $t->karyawanQc?->nama_karyawan ?? '',
                $part?->customer ?? '',
                $part?->part_no ?? '',
                $part?->part_name ?? '',
                $pp?->id_proses ?? '',
                $pp?->nama_proses ?? '',
                substr($t->jam_start, 0, 5),
                substr($t->jam_finish, 0, 5),
                $t->qty_ok,
                $t->qty_ng ?? 0,
                $total,
                $t->ng_ringkas,
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
            'Tgl Pengecekan', 'Tgl Produksi Part', 'Shift', 'QC Inspector',
            'Customer', 'Part No', 'Part Name', 'ID Proses', 'Nama Proses',
            'Jam Start', 'Jam Finish', 'Qty OK', 'Qty NG', 'Total Qty',
            'Jenis NG', 'SPH Std QC', 'SPH Aktual', 'Waktu Kerja (jam)', 'CT Aktual (dtk)', 'Achievement %',
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 16, 'B' => 18, 'C' => 12, 'D' => 20,
            'E' => 14, 'F' => 14, 'G' => 22, 'H' => 9,  'I' => 16,
            'J' => 10, 'K' => 10, 'L' => 9,  'M' => 9,  'N' => 10,
            'O' => 14, 'P' => 11, 'Q' => 11, 'R' => 17, 'S' => 15, 'T' => 13,
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
