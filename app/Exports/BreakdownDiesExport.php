<?php
namespace App\Exports;

use App\Models\TransaksiBreakdownDies;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class BreakdownDiesExport implements FromCollection, WithHeadings
{
    public function __construct(
        private ?string $dateFrom = null,
        private ?string $dateTo   = null,
        private ?int $partId      = null,
    ) {}

    public function headings(): array
    {
        return [
            'No. LKD', 'Part No', 'Nama Dies', 'Proses',
            'Tanggal Breakdown', 'Jam Breakdown',
            'Tanggal Mulai Perbaikan', 'Jam Mulai Perbaikan',
            'Tanggal Selesai Perbaikan', 'Jam Selesai Perbaikan',
            'Status', 'Repair (jam)', 'Downtime (jam)',
            'Problem', 'Penyebab', 'Tindakan', 'PIC Maintenance',
        ];
    }

    public function collection(): \Illuminate\Support\Enumerable
    {
        $q = TransaksiBreakdownDies::with('partProses.part');
        if ($this->dateFrom) $q->whereDate('tanggal_breakdown', '>=', $this->dateFrom);
        if ($this->dateTo)   $q->whereDate('tanggal_breakdown', '<=', $this->dateTo);
        if ($this->partId)   $q->whereHas('partProses', fn ($p) => $p->where('id_part', $this->partId));

        return $q->orderBy('tanggal_breakdown')->orderBy('id')->get()->map(fn ($b) => [
            $b->no_lkd,
            $b->partProses?->part?->part_no   ?? '',
            $b->partProses?->part?->part_name ?? '',
            $b->partProses?->nama_proses       ?? '',
            optional($b->tanggal_breakdown)->format('Y-m-d'),
            substr((string) $b->jam_breakdown, 0, 5),
            optional($b->tanggal_mulai_perbaikan)->format('Y-m-d'),
            substr((string) $b->jam_mulai_perbaikan, 0, 5),
            optional($b->tanggal_selesai_perbaikan)->format('Y-m-d'),
            substr((string) $b->jam_selesai_perbaikan, 0, 5),
            $b->status_breakdown,
            round((float) $b->repair_time, 2),
            round((float) $b->total_downtime, 2),
            $b->problem_dies,
            $b->penyebab_breakdown,
            $b->tindakan_perbaikan,
            $b->pic_maintenance,
        ]);
    }
}
