<?php

namespace App\Exports;

use App\Models\TransaksiProduksi;
use Illuminate\Support\Enumerable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class LhpProduksiExport implements FromCollection, WithColumnWidths, WithHeadings, WithStyles
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
        $q = TransaksiProduksi::with([
            'karyawan', 'mesin', 'partProses.part', 'shift', 'ngDetails.jenisNg',
        ]);

        if ($this->dateFrom) {
            $q->whereDate('tanggal_produksi', '>=', $this->dateFrom);
        }
        if ($this->dateTo) {
            $q->whereDate('tanggal_produksi', '<=', $this->dateTo);
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

        return $q->orderBy('tanggal_produksi')->orderBy('id')->get()->map(function ($t) {
            $pp = $t->partProses;
            $part = $pp?->part;
            $sphStd = $pp?->sph_standar_produksi ?? 0;
            $sphAct = $t->sph_aktual;
            $pct = $sphStd > 0 ? round($sphAct / $sphStd * 100, 1) : '';

            return [
                $t->tanggal_produksi,
                $t->shift?->nama_shift ?? '',
                $t->karyawan?->nama_karyawan ?? '',
                $t->mesin?->id_mesin ?? '',
                $t->mesin?->nama_mesin ?? '',
                $part?->customer ?? '',
                $part?->part_no ?? '',
                $part?->part_name ?? '',
                $pp?->id_proses ?? '',
                $pp?->nama_proses ?? '',
                substr($t->jam_start, 0, 5),
                substr($t->jam_finish, 0, 5),
                $t->qty_aktual,
                $t->qty_ng ?? 0,
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
            'Tanggal', 'Shift', 'Operator', 'ID Mesin', 'Nama Mesin',
            'Customer', 'Part No', 'Part Name', 'ID Proses', 'Nama Proses',
            'Jam Start', 'Jam Finish', 'Qty Aktual', 'Qty NG', 'Jenis NG',
            'SPH Std', 'SPH Aktual', 'Waktu Kerja (jam)', 'CT Aktual (dtk)', 'Achievement %',
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 13, 'B' => 12, 'C' => 20, 'D' => 10, 'E' => 20,
            'F' => 14, 'G' => 14, 'H' => 22, 'I' => 9,  'J' => 16,
            'K' => 10, 'L' => 10, 'M' => 11, 'N' => 9,  'O' => 14,
            'P' => 9,  'Q' => 11, 'R' => 17, 'S' => 15, 'T' => 13,
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
