<?php
namespace App\Exports;

use App\Models\TransaksiDandoriRincian;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class LhpDandoriExport implements FromCollection, WithHeadings, WithStyles, WithColumnWidths
{
    public function __construct(
        private ?string $dateFrom   = null,
        private ?string $dateTo     = null,
        private ?string $customer   = null,
        private ?string $partName   = null,
        private ?string $namaProses = null,
    ) {}

    public function collection(): \Illuminate\Support\Enumerable
    {
        $q = TransaksiDandoriRincian::with([
                'shift', 'karyawan', 'mesin', 'partProses.part', 'losstime',
            ]);

        if ($this->dateFrom)   $q->whereDate('tanggal', '>=', $this->dateFrom);
        if ($this->dateTo)     $q->whereDate('tanggal', '<=', $this->dateTo);
        if ($this->customer)   $q->whereHas('partProses.part', fn($q) => $q->where('customer',   'like', "%{$this->customer}%"));
        if ($this->partName)   $q->whereHas('partProses.part', fn($q) => $q->where('part_name',  'like', "%{$this->partName}%"));
        if ($this->namaProses) $q->whereHas('partProses',       fn($q) => $q->where('nama_proses','like', "%{$this->namaProses}%"));

        return $q->orderBy('tanggal')->orderBy('id')->get()->map(function ($r) {
            $pp   = $r->partProses;
            $part = $pp?->part;

            return [
                $r->tanggal,
                $r->shift?->nama_shift       ?? '',
                $r->karyawan?->nama_karyawan ?? '',
                $r->mesin?->id_mesin         ?? '',
                $r->mesin?->nama_mesin       ?? '',
                $part?->customer             ?? '',
                $part?->part_no              ?? '',
                $part?->part_name            ?? '',
                $pp?->id_proses              ?? '',
                $pp?->nama_proses            ?? '',
                $r->losstime?->kode          ?? '',
                $r->losstime?->jenis_losstime ?? '',
                $r->actual_menit,
                $r->std_menit,
                $r->over_menit,
            ];
        });
    }

    public function headings(): array
    {
        return [
            'Tanggal', 'Shift', 'Operator', 'ID Mesin', 'Nama Mesin',
            'Customer', 'Part No', 'Part Name', 'ID Proses', 'Nama Proses',
            'Kode Loss Time', 'Jenis Loss Time',
            'Aktual (mnt)', 'Std (mnt)', 'Over (mnt)',
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 13, 'B' => 12, 'C' => 20, 'D' => 10, 'E' => 20,
            'F' => 14, 'G' => 14, 'H' => 22, 'I' => 9,  'J' => 16,
            'K' => 14, 'L' => 24, 'M' => 12, 'N' => 10, 'O' => 10,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font'    => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF111827']],
                'alignment' => ['wrapText' => true],
            ],
        ];
    }
}
