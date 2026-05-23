<?php
namespace App\Exports;
use App\Services\DashboardService;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class QcExport implements FromCollection, WithHeadings, WithTitle
{
    public function __construct(
        private DashboardService $service,
        private Carbon $dateFrom,
        private Carbon $dateTo,
        private ?int $shiftId
    ) {}

    public function title(): string { return 'VCB Quality'; }

    public function headings(): array
    {
        return ['QC Inspector', 'Target QTY', 'Qty OK', 'Qty NG', 'Total Check', 'Achievement %', 'Avg SPH QC'];
    }

    public function collection()
    {
        $rows = $this->service->getVcbQuality($this->dateFrom, $this->dateTo, $this->shiftId);
        return collect($rows)->map(fn($r) => [
            $r['karyawan'], $r['total_target_qty'],
            $r['total_qty_ok'], $r['total_qty_ng'], $r['total_qty_check'],
            $r['achievement_pct'] . '%', $r['avg_actual_sph'],
        ]);
    }
}
