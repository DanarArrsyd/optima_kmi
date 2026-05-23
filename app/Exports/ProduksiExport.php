<?php
namespace App\Exports;
use App\Services\DashboardService;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class ProduksiExport implements FromCollection, WithHeadings, WithTitle
{
    public function __construct(
        private DashboardService $service,
        private Carbon $dateFrom,
        private Carbon $dateTo,
        private ?int $shiftId,
        private string $groupBy = 'karyawan'
    ) {}

    public function title(): string { return 'VCB Produksi'; }

    public function headings(): array
    {
        return ['Operator/Mesin', 'Target QTY', 'Actual QTY', 'Duration (jam)', 'Achievement %', 'Avg Actual SPH'];
    }

    public function collection()
    {
        $rows = $this->groupBy === 'mesin'
            ? $this->service->getVcbMesin($this->dateFrom, $this->dateTo, $this->shiftId)
            : $this->service->getVcbProduksi($this->dateFrom, $this->dateTo, $this->shiftId);

        return collect($rows)->map(fn($r) => [
            $this->groupBy === 'mesin' ? ($r['mesin'] ?? '-') : ($r['karyawan'] ?? '-'),
            $r['total_target_qty'],
            $r['total_qty_aktual'],
            $r['total_duration_hours'],
            $r['achievement_pct'] . '%',
            $r['avg_actual_sph'],
        ]);
    }
}
