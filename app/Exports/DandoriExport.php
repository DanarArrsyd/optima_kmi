<?php
namespace App\Exports;
use App\Services\DashboardService;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class DandoriExport implements FromCollection, WithHeadings, WithTitle
{
    public function __construct(
        private DashboardService $service,
        private ?string $bulan,
        private ?string $week,
        private ?int $mesinId,
        private ?int $shiftId
    ) {}

    public function title(): string { return 'VCB Dandori'; }

    public function headings(): array
    {
        return ['Mesin', 'Total Loss (menit)', 'Total Over (menit)'];
    }

    public function collection()
    {
        $result = $this->service->getVcbDandori($this->bulan, $this->week, $this->mesinId, $this->shiftId);
        return collect($result['rows'])->map(fn($r) => [
            $r['mesin'], $r['total_loss'], $r['total_over'],
        ]);
    }
}
